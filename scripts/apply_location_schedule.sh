#!/usr/bin/env bash
# AvianVisitors - apply the manual date+time travel schedule (the "reisschema").
#
# Replaces the IP geolocation (auto_location.sh) with a plan the user enters in
# the web UI: rows of "from this timestamp, we're at <lat,lon>" stored in
# birds.db (table av_location_schedule, written by avian/api/location-schedule.php).
#
# The ACTIVE row is the most recent whose timestamp is <= now. This script sets
# birdnet.conf LATITUDE/LONGITUDE to it (the BirdNET species-occurrence filter,
# also stamped onto every NEW detection) and reloads detection when it changes.
# Run @reboot AND every ~10 min by templates/location_schedule.cron, so a
# future-dated move activates on its own when its time arrives - no IP lookup,
# nothing changes until the user schedules it.
#
# Symlinked into /usr/local/bin by the installer; runs as root via cron.
# Logs to syslog:  journalctl -t avian-schedule
set -o pipefail
export PATH=/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

CONF=/etc/birdnet/birdnet.conf
TAG=avian-schedule
log() { logger -t "$TAG" -- "$*" 2>/dev/null; echo "[$TAG] $*"; }

[ -f "$CONF" ] || { log "no $CONF - abort"; exit 0; }

# birds.db sits next to this script in the BirdNET-Pi install (resolve through
# the /usr/local/bin symlink to the real path).
SELF=$(readlink -f "$0" 2>/dev/null || echo "$0")
DB="$(dirname "$SELF")/birds.db"
[ -f "$DB" ] || { log "no birds.db at $DB - abort"; exit 0; }
command -v sqlite3 >/dev/null || { log "sqlite3 missing - abort"; exit 0; }

NOW=$(date +%Y-%m-%dT%H:%M)

# Active row = latest timestamp that has arrived. Table may not exist yet (no
# schedule created) -> empty result, leave everything untouched.
ACTIVE=$(sqlite3 "$DB" \
  "SELECT printf('%.4f,%.4f', lat, lon) FROM av_location_schedule WHERE from_ts <= '$NOW' ORDER BY from_ts DESC, id DESC LIMIT 1;" \
  2>/dev/null)
if [ -z "$ACTIVE" ]; then
  log "no active schedule entry (<= $NOW) - location unchanged"
  exit 0
fi
clat=${ACTIVE%,*}
clon=${ACTIVE#*,}
case "$clat" in ''|*[!0-9.+-]*) log "bad lat '$clat' - abort"; exit 0 ;; esac
case "$clon" in ''|*[!0-9.+-]*) log "bad lon '$clon' - abort"; exit 0 ;; esac

# Current active location in the conf.
LATITUDE=0; LONGITUDE=0
# shellcheck disable=SC1090
source "$CONF" 2>/dev/null || true
same=$(awk -v a="$LATITUDE" -v b="$LONGITUDE" -v c="$clat" -v d="$clon" \
  'BEGIN{print (((a-c)<0?(c-a):(a-c))<0.0005 && ((b-d)<0?(d-b):(b-d))<0.0005)?1:0}')
if [ "$same" = "1" ]; then
  exit 0   # already at the scheduled spot - nothing to do (quiet on the 10-min tick)
fi

# Apply via redirect (not sed -i) so the file keeps its owner/permissions - the
# web UI (caddy user) writes it the same way.
upd=$(cat "$CONF") || { log "read $CONF failed - abort"; exit 1; }
upd=$(printf '%s\n' "$upd" | sed -e "s/^LATITUDE=.*/LATITUDE=$clat/" -e "s/^LONGITUDE=.*/LONGITUDE=$clon/")
printf '%s\n' "$upd" > "$CONF" || { log "write $CONF failed - abort"; exit 1; }
log "active location -> ($clat,$clon) at $NOW - reloading detection"
restart_services.sh >/dev/null 2>&1 || systemctl restart birdnet_analysis >/dev/null 2>&1 || log "restart returned non-zero"
log "done"
