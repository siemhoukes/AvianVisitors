#!/usr/bin/env python3
"""AvianVisitors - occurrence likelihood for a place and week (Vogelkans).

Runs BirdNET's species range model (the "MData" model) for one lat/lon/week and
prints the species it expects there, with their occurrence probability. This is
the same model that already decides which species the analyzer will even try to
detect (SF_THRESH in the settings) - Vogelkans just shows it to you.

Called by avian/api/vogelkans.php, which caches the output per 0.25-degree grid
cell and enriches it with Dutch names, groups and artwork flags. Also useful on
its own:

    python3 vogelkans.py --lat 52.09 --lon 5.12            # this week, readable
    python3 vogelkans.py --lat 43.9 --lon 5.8 --week 20 --json

WEEK NUMBERING - read this before changing anything. BirdNET uses 48 weeks per
year (4 per month), NOT ISO weeks. Its own V1 metadata encoder makes this
explicit: it maps week to cos(week * 7.5 degrees), and 48 * 7.5 = 360. Feeding
it an ISO week of 49-53 is out of distribution and the output degrades badly -
measured at Utrecht, weeks 1-48 return 102-121 species above 0.05 while week 49
returns 236, ordered nonsensically. So --week is clamped to 1..48 and a date is
converted with week_of_year() below, never with isocalendar().
"""
from __future__ import annotations
import argparse
import datetime
import json
import os
import sys
from pathlib import Path

# scripts/ on the path so `utils` resolves the same way species.py sees it.
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))

from utils.helpers import birdnet_week, MODEL_PATH   # noqa: E402
from utils.models import MDataModel2                 # noqa: E402

WEEKS_PER_YEAR = 48
DEFAULT_LABELS = os.path.join(MODEL_PATH, 'BirdNET_GLOBAL_6K_V2.4_Model_FP16_Labels.txt')


def clamp_week(week: int) -> int:
    return max(1, min(WEEKS_PER_YEAR, int(week)))


def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--lat', type=float, required=True)
    ap.add_argument('--lon', type=float, required=True)
    ap.add_argument('--week', type=int, default=None,
                    help=f'BirdNET week 1..{WEEKS_PER_YEAR} (default: this week)')
    ap.add_argument('--threshold', type=float, default=0.01,
                    help='Minimum occurrence probability to report (default 0.01)')
    ap.add_argument('--labels', type=Path, default=Path(DEFAULT_LABELS))
    ap.add_argument('--json', action='store_true', help='Emit JSON for the API')
    args = ap.parse_args()

    if not -90 <= args.lat <= 90 or not -180 <= args.lon <= 180:
        print('error: lat/lon out of range', file=sys.stderr)
        return 2
    threshold = max(0.0, min(1.0, args.threshold))
    week = clamp_week(args.week if args.week is not None
                      else birdnet_week(datetime.date.today()))

    labels = [l.strip() for l in
              args.labels.read_text(encoding='utf-8').splitlines() if l.strip()]

    # MDataModel2 explicitly, not get_meta_model(): that honours the analyzer's
    # DATA_MODEL_VERSION (ships as 1), and Vogelkans always wants the V2 range
    # model regardless of what the analyzer is configured to run with.
    model = MDataModel2(threshold)
    model.set_meta_data(args.lat, args.lon, week)
    scored = model.get_species_list_details(labels)

    if args.json:
        json.dump({
            'lat': args.lat, 'lon': args.lon, 'week': week,
            'threshold': threshold,
            'species': [[sci, round(float(score), 4)] for score, sci in scored],
        }, sys.stdout, separators=(',', ':'))
        sys.stdout.write('\n')
    else:
        print(f'{len(scored)} species at {args.lat}/{args.lon}, week {week} '
              f'(>= {threshold})')
        for score, sci in scored:
            print(f'  {float(score):.4f}  {sci}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
