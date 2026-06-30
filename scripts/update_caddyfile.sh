#!/usr/bin/env bash
# Writing /etc/caddy/Caddyfile needs root. Re-exec under sudo if we were run
# as a normal user, otherwise the heredoc redirects below fail silently with
# "permission denied" and the install_services.sh Caddyfile (which lacks the
# index.html try_files override) stays active - so / serves the stock
# BirdNET-Pi page instead of the AvianVisitors collage.
if [ "$(id -u)" -ne 0 ]; then exec sudo -E bash "$0" "$@"; fi
source /etc/birdnet/birdnet.conf
my_dir=$HOME/BirdNET-Pi/scripts
set -x

# Find the active PHP-FPM Unix socket. The path is version-specific on
# modern Raspberry Pi OS (e.g. /run/php/php8.2-fpm.sock); the generic
# /run/php/php-fpm.sock only exists if a compat shim is installed, so
# hardcoding it breaks Caddy's php_fastcgi handler on stock Bookworm.
FPM_SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)
FPM_SOCK=${FPM_SOCK:-/run/php/php-fpm.sock}

[ -d /etc/caddy ] || mkdir /etc/caddy
if [ -f /etc/caddy/Caddyfile ];then
  cp /etc/caddy/Caddyfile{,.original}
fi
if ! [ -z ${CADDY_PWD} ];then
HASHWORD=$(caddy hash-password --plaintext ${CADDY_PWD})
# AvianVisitors two-tier auth. CADDY_PWD = admin (user "birdnet"): everything
# EXCEPT the live mic. LIVE_PWD = pensionado (user "pensionado"): everything
# INCLUDING the live mic. A basicauth block lists the users allowed on that
# path; ${AUTH_BOTH} = both tiers, ${AUTH_LIVE} = pensionado only (the live
# stream). If LIVE_PWD is unset we degrade to a single admin tier (admin also
# gets /stream) so an install without the live tier still works.
# NB: the frontend sends the Authorization header explicitly via fetch on every
# gated request (incl. the live stream + recordings, via fetch->blob/MediaSource),
# so these 401s never reach a bare <audio>/navigation -> the browser's native
# login popup never fires. Auth stays at Caddy; the in-app drawer is the only login.
AUTH_BOTH="    birdnet ${HASHWORD}"
AUTH_LIVE="    birdnet ${HASHWORD}"
if ! [ -z ${LIVE_PWD} ];then
  LIVEHASH=$(caddy hash-password --plaintext ${LIVE_PWD})
  AUTH_BOTH=$(printf '    birdnet %s\n    pensionado %s' "${HASHWORD}" "${LIVEHASH}")
  AUTH_LIVE=$(printf '    pensionado %s' "${LIVEHASH}")
fi
cat << EOF > /etc/caddy/Caddyfile
http:// ${BIRDNETPI_URL} {
  root * ${EXTRACTED}
  file_server browse
  # AvianVisitors: basicauth signals a failed login by returning a 401 handler
  # *error*, which Caddy renders in its error path - a normal-chain
  # `header -WWW-Authenticate` never wraps that write, so the challenge header
  # survives and the browser shows its native login popup. Strip it here, inside
  # handle_errors, where the 401 is actually written. No WWW-Authenticate => no
  # native popup; the in-app drawer stays the only login.
  handle_errors 401 {
    header -WWW-Authenticate
    respond 401
  }
  # Always revalidate the app shell so UI changes appear without a manual cache
  # clear (304 when unchanged, fresh bytes when changed).
  @appshell path / /index.html /apt.js /styles.css /dims.json /masks.json
  header @appshell Cache-Control "no-cache"
  handle /By_Date/* {
    file_server browse
  }
  handle /Charts/* {
    file_server browse
  }
  basicauth /views.php?view=File* {
${AUTH_BOTH}
  }
  basicauth /Processed* {
${AUTH_BOTH}
  }
  basicauth /scripts* {
${AUTH_BOTH}
  }
  basicauth /stream {
${AUTH_LIVE}
  }
  # AvianVisitors: keep recorded mic audio + the map's write endpoint private
  # too (live /stream above is only half the story). Collage/atlas/map/stats
  # read APIs stay open; spectrogram images stay open so the UI still renders.
  basicauth /avian/api/recording.php* {
${AUTH_BOTH}
  }
  basicauth /avian/api/location-edit.php* {
${AUTH_BOTH}
  }
  basicauth /phpsysinfo* {
${AUTH_BOTH}
  }
  basicauth /terminal* {
${AUTH_BOTH}
  }
  # AvianVisitors: gate the whole menu/settings drawer. menu.php returns the
  # drawer contents, so 401ing it (no creds) keeps the lock screen up until the
  # password is entered - there's no way to render the menu (settings, live
  # audio, tools) without it. This is what the frontend's lock-screen flow
  # expects on a password-protected deploy.
  basicauth /avian/api/menu.php* {
${AUTH_BOTH}
  }
  # AvianVisitors: the reisschema (manual location plan) + place search both
  # read/write where the unit thinks it is, so gate them like the rest.
  basicauth /avian/api/location-schedule.php* {
${AUTH_BOTH}
  }
  basicauth /avian/api/geocode.php* {
${AUTH_BOTH}
  }
  # AvianVisitors: the kaart/map reveals where you are + your travel stops, so
  # gate its data (locations/mapconfig/journey actions on birdnet-api.php). The
  # collage/atlas/stats (recent/species/stats actions) stay open for the family.
  @mapdata query action=locations action=journey
  basicauth @mapdata {
${AUTH_BOTH}
  }
  # SmallTV window setting: anyone may read it (GET), only authed users set it.
  @tvconfigwrite {
    method POST
    path /avian/api/smalltv-config.php*
  }
  basicauth @tvconfigwrite {
${AUTH_BOTH}
  }
  reverse_proxy /stream localhost:8000
  # AvianVisitors overlay drops an index.html alongside BirdNET-Pi's
  # index.php. The default try_files for php_fastcgi prefers index.php
  # over index.html, so override it - this is a no-op on stock installs
  # since EXTRACTED has no index.html there.
  php_fastcgi unix/${FPM_SOCK} {
    try_files {path} {path}/index.html {path}/index.php index.php
  }
  reverse_proxy /log* localhost:8080
  reverse_proxy /stats* localhost:8501
  reverse_proxy /terminal* localhost:8888
}
EOF
else
  cat << EOF > /etc/caddy/Caddyfile
http:// ${BIRDNETPI_URL} {
  root * ${EXTRACTED}
  file_server browse
  # AvianVisitors: basicauth signals a failed login by returning a 401 handler
  # *error*, which Caddy renders in its error path - a normal-chain
  # `header -WWW-Authenticate` never wraps that write, so the challenge header
  # survives and the browser shows its native login popup. Strip it here, inside
  # handle_errors, where the 401 is actually written. No WWW-Authenticate => no
  # native popup; the in-app drawer stays the only login.
  handle_errors 401 {
    header -WWW-Authenticate
    respond 401
  }
  # Always revalidate the app shell so UI changes appear without a manual cache
  # clear (304 when unchanged, fresh bytes when changed).
  @appshell path / /index.html /apt.js /styles.css /dims.json /masks.json
  header @appshell Cache-Control "no-cache"
  handle /By_Date/* {
    file_server browse
  }
  handle /Charts/* {
    file_server browse
  }
  reverse_proxy /stream localhost:8000
  # AvianVisitors overlay drops an index.html alongside BirdNET-Pi's
  # index.php. The default try_files for php_fastcgi prefers index.php
  # over index.html, so override it - this is a no-op on stock installs
  # since EXTRACTED has no index.html there.
  php_fastcgi unix/${FPM_SOCK} {
    try_files {path} {path}/index.html {path}/index.php index.php
  }
  reverse_proxy /log* localhost:8080
  reverse_proxy /stats* localhost:8501
  reverse_proxy /terminal* localhost:8888
}
EOF
fi

sudo caddy fmt --overwrite /etc/caddy/Caddyfile
sudo systemctl reload caddy
