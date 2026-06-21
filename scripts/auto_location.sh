#!/usr/bin/env bash
# AvianVisitors - keep the Pi's location current for a travelling install.
#
# On a caravan trip (e.g. Netherlands -> Spain) BirdNET's species-occurrence
# filter is tied to LATITUDE/LONGITUDE + week-of-year, normally set once at
# install. This re-detects the location via IP geolocation (the same
# http://ip-api.com source install_config.sh uses at install time) and, when it
# has genuinely moved, rewrites birdnet.conf and restarts services - exactly
# what the web UI's Tools -> Settings -> Location form does - so detections
# track where you actually are, with no manual reconfiguration.
#
# Built for a caravan whose router uplink flips between campsite WiFi and a 4G
# tether. Cellular IPs often geolocate to a far-away carrier hub, so a naive
# "moved > threshold -> relocate" would jump to the wrong city the moment the
# uplink switched. Two guards prevent that:
#   * MIN_MOVE_KM (50): ignore anything closer than a real caravan hop.
#   * Hysteresis: only relocate once TWO consecutive readings agree within
#     SAME_PLACE_KM. A one-off 4G-hub spike never matches the next reading, so
#     it is never committed - it just becomes a pending candidate that the next
#     run discards when the uplink is back on WiFi.
#
# Symlinked into /usr/local/bin by install_scripts(); scheduled @reboot + every
# 2 h by templates/auto_location.cron (runs as root). To pin a fixed location
# (manual override), set AUTO_LOCATION=false in birdnet.conf - the web UI's
# Location form then has the last word.
#
# Logs to syslog:  journalctl -t avian-autolocation
set -o pipefail
export PATH=/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

CONF=/etc/birdnet/birdnet.conf
STATE_DIR=/var/lib/avian
CAND=$STATE_DIR/autoloc_candidate     # pending move awaiting a 2nd consistent reading
MIN_MOVE_KM=50          # only relocate after moving at least this far (caravan hop)
SAME_PLACE_KM=10        # two readings within this are "the same place" (hysteresis)
FETCH_TRIES=6           # retry the lookup (covers "no network yet" at @reboot)
FETCH_GAP=20            # seconds between retries
TAG=avian-autolocation

log() { logger -t "$TAG" -- "$*" 2>/dev/null; echo "[$TAG] $*"; }

# Great-circle distance in km between two lat/lon pairs.
haversine() {
  awk -v la1="$1" -v lo1="$2" -v la2="$3" -v lo2="$4" 'BEGIN{
    pi=atan2(0,-1); d2r=pi/180; r=6371;
    dlat=(la2-la1)*d2r; dlon=(lo2-lo1)*d2r;
    sa=sin(dlat/2); sb=sin(dlon/2);
    a=sa*sa + cos(la1*d2r)*cos(la2*d2r)*sb*sb;
    if(a<0)a=0; if(a>1)a=1;
    printf "%.1f", r*2*atan2(sqrt(a),sqrt(1-a));
  }'
}
le() { awk -v a="$1" -v b="$2" 'BEGIN{print (a<=b)?1:0}'; }   # a <= b ?

[ -f "$CONF" ] || { log "no $CONF found - abort"; exit 0; }

# Current config (sourced like the other BirdNET-Pi scripts). Defaults stand in
# for an install whose conf predates the AUTO_LOCATION knob.
AUTO_LOCATION=true; LATITUDE=0; LONGITUDE=0
# shellcheck disable=SC1090
source "$CONF" 2>/dev/null || true
case "${AUTO_LOCATION,,}" in
  false|0|no|off) log "AUTO_LOCATION disabled in birdnet.conf - manual location kept"; exit 0 ;;
esac
oldlat="$LATITUDE"; oldlon="$LONGITUDE"

# Detect current location (IPv4), retrying in case the network isn't up yet at
# boot. Request mobile/proxy flags so we can note an untrustworthy cellular IP.
json=""
for try in $(seq 1 "$FETCH_TRIES"); do
  json=$(curl -s4 --max-time 15 "http://ip-api.com/json/?fields=status,message,country,city,lat,lon,mobile,proxy" || true)
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
mobile=$(echo "$json" | jq -r '.mobile // false')
proxy=$(echo "$json" | jq -r '.proxy // false')
case "$newlat" in ''|*[!0-9.+-]*) log "bad latitude '$newlat' - abort"; exit 0 ;; esac
case "$newlon" in ''|*[!0-9.+-]*) log "bad longitude '$newlon' - abort"; exit 0 ;; esac
[ "$mobile" = "true" ] && log "note: reading is from a cellular/mobile IP - geolocation may be coarse"
[ "$proxy" = "true" ] && log "note: reading is from a proxy/VPN IP - geolocation may be unreliable"

commit_move() {
  # Round to 4 decimals, matching the web UI's Location form precision. Write
  # via a redirect (not sed -i) so the file keeps its owner/permissions - the
  # web UI (caddy user) writes it directly too.
  local clat clon updated
  clat=$(awk -v v="$newlat" 'BEGIN{printf "%.4f", v}')
  clon=$(awk -v v="$newlon" 'BEGIN{printf "%.4f", v}')
  updated=$(sed -e "s/^LATITUDE=.*/LATITUDE=$clat/" \
                -e "s/^LONGITUDE=.*/LONGITUDE=$clon/" "$CONF") \
    || { log "failed to edit $CONF - abort"; exit 1; }
  printf '%s\n' "$updated" > "$CONF" || { log "failed to write $CONF - abort"; exit 1; }
  rm -f "$CAND"
  log "location updated to ${place:-unknown}: ($oldlat,$oldlon) -> ($clat,$clon), moved ${moved} km - restarting services"
  restart_services.sh >/dev/null 2>&1 || log "restart_services.sh returned non-zero"
  log "done"
}

# First run on a fresh install (still 0,0): adopt the reading immediately so the
# species list isn't stuck at the null island.
if [ "$(awk -v a="$oldlat" -v b="$oldlon" 'BEGIN{print (a==0 && b==0)?1:0}')" = "1" ]; then
  log "no location set yet - adopting first reading"
  moved=0; commit_move; exit 0
fi

moved=$(haversine "$oldlat" "$oldlon" "$newlat" "$newlon")
if [ "$(le "$moved" "$MIN_MOVE_KM")" = "1" ]; then
  # Still near the configured location: we haven't moved. Drop any stale
  # candidate (e.g. a transient 4G-hub reading from a previous run).
  rm -f "$CAND"
  log "at ${place:-unknown} ($newlat,$newlon); moved ${moved} km (< ${MIN_MOVE_KM}) - no change"
  exit 0
fi

# Moved far enough to be a real relocation candidate. Apply hysteresis: only
# commit if the PREVIOUS run saw essentially this same new place.
mkdir -p "$STATE_DIR" 2>/dev/null
if [ -f "$CAND" ]; then
  read -r plat plon _ < "$CAND" 2>/dev/null
  if [ -n "$plat" ] && [ -n "$plon" ]; then
    drift=$(haversine "$plat" "$plon" "$newlat" "$newlon")
    if [ "$(le "$drift" "$SAME_PLACE_KM")" = "1" ]; then
      log "confirmed new location (2 consistent readings, ${drift} km apart) - committing"
      commit_move
      exit 0
    fi
    log "candidate changed (${drift} km from last reading) - likely a WiFi/4G flip; re-arming"
  fi
fi
printf '%s %s\n' "$newlat" "$newlon" > "$CAND"
log "new location ${place:-unknown} ($newlat,$newlon) is ${moved} km away - pending one more consistent reading before relocating"
