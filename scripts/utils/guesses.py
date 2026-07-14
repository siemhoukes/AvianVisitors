"""Persistent per-chunk guess log ("what is the algo guessing right now?").

Every analyzed time slot becomes one JSON line, INCLUDING top guesses that
never pass CONFIDENCE or the species filters - those are exactly the ones
the journal log made hard to read. The admin frontend (#admin=live, via
avian/api/guesses.php) tails these files for a stable live view, and they
double as history for tuning CONFIDENCE / SF_THRESH later.

Files: $RECS_DIR/guesses/guesses-YYYY-MM-DD.jsonl, one file per day,
pruned after GUESS_RETENTION_DAYS (birdnet.conf, default 14). Deliberately
NOT under StreamData - install_helpers.sh mounts that as tmpfs.

Record shape (one line each):
  {"t": "2026-07-14T09:12:33", "s": 9.0, "e": 12.0,
   "top": [{"sci": "Turdus merula", "com": "Merel", "conf": 0.42}, ...],
   "status": "confident"}

status of the slot = verdict on its #1 guess:
  confident        - became a detection
  below_confidence - best guess scored under CONFIDENCE
  quiet            - best guess under 0.05: effectively silence/noise
  human            - privacy filter blanked this slot
  not_in_include / excluded / sf_thresh - species-list rejections
"""
import datetime
import glob
import json
import logging
import os

from .helpers import get_settings

log = logging.getLogger(__name__)

TOP_N = 3
QUIET_CONF = 0.05
DEFAULT_RETENTION_DAYS = 14
_pruned_on = None  # date of last retention sweep (once per day per process)


def guesses_dir():
    return os.path.join(get_settings()['RECS_DIR'], 'guesses')


def _slot_status(sci_name, confidence, thresholds):
    # Mirrors the accept/reject cascade in analysis.run_analysis for the
    # top guess only - display metadata, not a second source of truth.
    confidence_min, include, exclude, predicted, whitelist = thresholds
    if sci_name == 'Human_Human':
        return 'human'
    if confidence < QUIET_CONF:
        return 'quiet'
    if confidence < confidence_min:
        return 'below_confidence'
    if include and sci_name not in include:
        return 'not_in_include'
    if exclude and sci_name in exclude:
        return 'excluded'
    if predicted and sci_name not in predicted and sci_name not in whitelist:
        return 'sf_thresh'
    return 'confident'


def write_guesses(file, raw_detections, names, include_list, exclude_list,
                  predicted_species_list, whitelist_list):
    """Append one JSONL record per analyzed slot. Never raises - a full
    disk or permission problem must not take down the analysis loop."""
    try:
        conf = get_settings()
        thresholds = (conf.getfloat('CONFIDENCE'), include_list, exclude_list,
                      predicted_species_list, whitelist_list)
        lines = []
        for time_slot, entries in raw_detections.items():
            start, end = (float(x) for x in time_slot.split(';'))
            sci, score = entries[0]
            top = [{'sci': s, 'com': names.get(s, s), 'conf': round(float(c), 3)}
                   for s, c in entries[:TOP_N] if float(c) >= QUIET_CONF or (s, c) == entries[0]]
            when = file.file_date + datetime.timedelta(seconds=start)
            lines.append(json.dumps({
                't': when.strftime('%Y-%m-%dT%H:%M:%S'),
                's': start, 'e': end, 'top': top,
                'status': _slot_status(sci, float(score), thresholds),
            }, ensure_ascii=False))
        if not lines:
            return
        d = guesses_dir()
        os.makedirs(d, exist_ok=True)
        day_file = os.path.join(d, 'guesses-%s.jsonl' % file.file_date.strftime('%Y-%m-%d'))
        with open(day_file, 'a', encoding='utf-8') as f:
            f.write('\n'.join(lines) + '\n')
        _prune(d)
    except Exception:
        log.exception('could not write guess log')


def _prune(d):
    global _pruned_on
    today = datetime.date.today()
    if _pruned_on == today:
        return
    _pruned_on = today
    try:
        keep_days = int(get_settings().get('GUESS_RETENTION_DAYS', '') or DEFAULT_RETENTION_DAYS)
    except ValueError:
        keep_days = DEFAULT_RETENTION_DAYS
    cutoff = today - datetime.timedelta(days=keep_days)
    for path in glob.glob(os.path.join(d, 'guesses-*.jsonl')):
        try:
            day = datetime.datetime.strptime(
                os.path.basename(path), 'guesses-%Y-%m-%d.jsonl').date()
            if day < cutoff:
                os.remove(path)
                log.info('pruned old guess log %s', os.path.basename(path))
        except ValueError:
            continue
