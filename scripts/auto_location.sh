#!/usr/bin/env bash
# AvianVisitors - autonomous location for a travelling install.
#
# On a caravan trip (Netherlands -> Spain) BirdNET's species-occurrence filter
# is tied to LATITUDE/LONGITUDE + week-of-year, normally set once at install.
# The caravan is unplugged to drive, so EVERY MOVE IS A REBOOT - which makes
# boot the one moment we need to act. This runs once at boot, fetches a general
# IP-based location, and if it has moved far enough rewrites birdnet.conf and
# reloads detection - the same thing the web UI's Location form does - so the
# species list tracks where you are with zero intervention (plug and play).
#
# Why boot-only (no periodic re-check): the router's uplink flips between
# campsite WiFi and 4G during a stay, and a cellular IP geolocates to a far
# carrier hub. Re-checking mid-stay would chase that jitter. Acting only at
# boot sidesteps it entirely. The IP fix is coarse/general (often the carrier
# region on 4G) - that's fine for a regional species filter, and the location
# can always be corrected by hand from the web UI later.
#
# Symlinked into /usr/local/bin by install_scripts(); run @reboot by
# templates/auto_location.cron (as root). Set AUTO_LOCATION=false in
# birdnet.conf to pin a fixed/manual location and skip this entirely.
#
# Logs to syslog:  journalctl -t avian-autolocation
set -o pipefail
export PATH=/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

CONF=/etc/birdnet/birdnet.conf
MIN_MOVE_KM=50          # only relocate after moving at least this far - a same-site
                        # reboot (incl. IP wobble / stable 4G hub) stays put.
FETCH_TRIES=8           # retry the lookup (network/uplink may be down at boot)
FETCH_GAP=15            # seconds between retries
TAG=avian-autolocation

log() { logger -t "$TAG" -- "$*" 2>/dev/null; echo "[$TAG] $*"; }

[ -f "$CONF" ] || { log "no $CONF found - abort"; exit 0; }

# Current config (sourced like the other BirdNET-Pi scripts). Defaults stand in
# for an install whose conf predates the AUTO_LOCATION knob.
AUTO_LOCATION=true; LATITUDE=0; LONGITUDE=0; LAST_AUTO_LAT=; LAST_AUTO_LON=
# shellcheck disable=SC1090
source "$CONF" 2>/dev/null || true
case "${AUTO_LOCATION,,}" in
  false|0|no|off) log "AUTO_LOCATION disabled in birdnet.conf - manual location kept"; exit 0 ;;
esac
# Compare each IP fix against the LAST AUTO-fix (LAST_AUTO_*), not the active
# location. A hand-moved pin changes LATITUDE/LONGITUDE but NOT this baseline,
# so the correction sticks until you physically move far enough for a new fix
# to land here. Empty baseline = first run / pre-upgrade conf.
oldlat="$LAST_AUTO_LAT"; oldlon="$LAST_AUTO_LON"

# Detect current location (IPv4), retrying in case the uplink isn't up yet.
json=""
for try in $(seq 1 "$FETCH_TRIES"); do
  json=$(curl -s4 --max-time 15 "http://ip-api.com/json/?fields=status,message,country,city,lat,lon,mobile" || true)
  if [ -n "$json" ] && [ "$(echo "$json" | jq -r '.status // empty')" = "success" ]; then
    break
  fi
  json=""
  [ "$try" -lt "$FETCH_TRIES" ] && sleep "$FETCH_GAP"
done
if [ -z "$json" ]; then
  log "geolocation lookup failed (offline?) - leaving location unchanged"
  exit 0
fi

newlat=$(echo "$json" | jq -r '.lat')
newlon=$(echo "$json" | jq -r '.lon')
place=$(echo "$json" | jq -r '[.city, .country] | map(select(. != null and . != "")) | join(", ")')
[ "$(echo "$json" | jq -r '.mobile // false')" = "true" ] && \
  log "note: cellular IP - location is general (carrier region); correct by hand if needed"
case "$newlat" in ''|*[!0-9.+-]*) log "bad latitude '$newlat' - abort"; exit 0 ;; esac
case "$newlon" in ''|*[!0-9.+-]*) log "bad longitude '$newlon' - abort"; exit 0 ;; esac

clat=$(awk -v v="$newlat" 'BEGIN{printf "%.4f", v}')
clon=$(awk -v v="$newlon" 'BEGIN{printf "%.4f", v}')

# Decide. Baseline (oldlat/oldlon) is the LAST AUTO-fix, not the active pin.
#   no baseline yet   -> anchor it to this fix; adopt as the active location too
#                        ONLY if the active one is still unset (fresh install),
#                        otherwise keep whatever's there (a manual/earlier fix).
#   moved <  50 km     -> same area; keep everything (manual edits preserved).
#   moved >= 50 km     -> a real hop; move the active location AND the baseline.
set_active=0
if [ -z "$oldlat" ] || [ -z "$oldlon" ] || [ "$(awk -v a="$oldlat" -v b="$oldlon" 'BEGIN{print (a==0 && b==0)?1:0}')" = "1" ]; then
  if [ "$(awk -v a="$LATITUDE" -v b="$LONGITUDE" 'BEGIN{print (a==0 && b==0)?1:0}')" = "1" ]; then
    set_active=1; moved="(first run)"
  else
    moved="(baseline init)"
  fi
else
  moved=$(awk -v la1="$oldlat" -v lo1="$oldlon" -v la2="$newlat" -v lo2="$newlon" 'BEGIN{
    pi=atan2(0,-1); d2r=pi/180; r=6371;
    dlat=(la2-la1)*d2r; dlon=(lo2-lo1)*d2r; sa=sin(dlat/2); sb=sin(dlon/2);
    a=sa*sa+cos(la1*d2r)*cos(la2*d2r)*sb*sb; if(a<0)a=0; if(a>1)a=1;
    printf "%.1f", r*2*atan2(sqrt(a),sqrt(1-a)); }')
  if [ "$(awk -v m="$moved" -v t="$MIN_MOVE_KM" 'BEGIN{print (m<t)?1:0}')" = "1" ]; then
    log "at ${place:-unknown} ($newlat,$newlon); ${moved} km from last auto-fix (< ${MIN_MOVE_KM}) - keeping current location (manual edits preserved)"
    exit 0
  fi
  set_active=1
fi

# Apply via a redirect (not sed -i) so the file keeps its owner/permissions -
# the web UI (caddy user) writes it directly too. Always re-anchor the
# LAST_AUTO_* baseline to this fix; move the active LATITUDE/LONGITUDE only on a
# real hop / fresh install (a manual pin then survives until the next hop).
upd=$(cat "$CONF") || { log "failed to read $CONF - abort"; exit 1; }
if [ "$set_active" = "1" ]; then
  upd=$(printf '%s\n' "$upd" | sed -e "s/^LATITUDE=.*/LATITUDE=$clat/" -e "s/^LONGITUDE=.*/LONGITUDE=$clon/")
fi
if printf '%s\n' "$upd" | grep -q '^LAST_AUTO_LAT='; then
  upd=$(printf '%s\n' "$upd" | sed -e "s/^LAST_AUTO_LAT=.*/LAST_AUTO_LAT=$clat/" -e "s/^LAST_AUTO_LON=.*/LAST_AUTO_LON=$clon/")
else
  upd="$upd
LAST_AUTO_LAT=$clat
LAST_AUTO_LON=$clon"
fi
printf '%s\n' "$upd" > "$CONF" || { log "failed to write $CONF - abort"; exit 1; }

if [ "$set_active" = "1" ]; then
  log "location set to ${place:-unknown}: -> ($clat,$clon), moved ${moved} km - reloading detection"
  # Services started moments ago at boot with the OLD location; restart so the
  # analyzer reloads the species list for the new spot. (Once per actual hop.)
  restart_services.sh >/dev/null 2>&1 || log "restart_services.sh returned non-zero"
else
  log "baseline anchored at ${place:-unknown} ($clat,$clon); active location kept ($LATITUDE,$LONGITUDE)"
fi
log "done"
