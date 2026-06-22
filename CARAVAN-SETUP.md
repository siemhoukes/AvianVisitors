# AvianVisitors — caravan Pi (3B+) setup runbook

Fresh install of the AvianVisitors fork on a Raspberry Pi 3B+ for the caravan.
Internet on the road = **USB tethering** from a phone. Remote/any-device access =
**Tailscale**. Bird-detection location updates **autonomously at every boot**.

Placeholders to fill in: `<HOME_WIFI_SSID>`, `<HOME_WIFI_PW>`, `<PI_USER>`,
`<STADIA_KEY>` (from repo `.env`), `<MIC_PASSWORD>` (pick one).

---

## 1. Flash the SD card (Raspberry Pi Imager)

- **SD card:** **≥ 32 GB.**
- **OS:** Raspberry Pi OS **Lite (64-bit)** — Bookworm. (Lite = no desktop, saves RAM. 64-bit required.)
- **Pi model note:** the project officially lists 4B / 5 / Zero 2W. The **3B+ isn't listed**, but it has more RAM (1 GB) and a comparable CPU to the supported **Zero 2W (512 MB)**, so it works — just slower. The installer's `<2 GB` low-mem branch (swap + WiFi power-save) handles it automatically.
- Click the **⚙️ / "Edit settings"** before writing, and set:
  - **Hostname:** `birdnet`
  - **Enable SSH** → use password authentication (or paste your public key)
  - **Username / password:** `<PI_USER>` / a password you'll remember
  - **Configure wireless LAN:** SSID `<HOME_WIFI_SSID>`, password `<HOME_WIFI_PW>`
    *(this is just so you can SSH in at home to install — on the road it uses USB tethering, not WiFi)*
  - **Wireless LAN country:** `NL` *(regulatory — without it the WiFi radio stays off; NL is valid for the whole EU trip)*
  - **Locale / timezone:** `Europe/Amsterdam`
- Write, then put the card in the Pi and power on. Give it ~1–2 min on first boot.

## 2. SSH in (from your laptop on the same home network)

```bash
ssh <PI_USER>@birdnet.local
```
(If `birdnet.local` doesn't resolve, find the IP in your router's client list and `ssh <PI_USER>@<ip>`.)

## 3. Run the installer

**First, make sure passwordless sudo works** — the installer aborts without it, and an
Imager-created user doesn't always have it:
```bash
sudo -n true && echo "passwordless sudo OK" || \
  echo "$USER ALL=(ALL) NOPASSWD: ALL" | sudo tee /etc/sudoers.d/010_$USER-nopasswd
```
(The second half only runs if the check failed; it'll prompt for your password once.)

Then run the installer:
```bash
curl -s https://raw.githubusercontent.com/siemhoukes/AvianVisitors/avian-visitors/newinstaller.sh | bash
```
- This clones the fork, expands swap to 1 GB + disables WiFi power-save (low-mem tuning),
  installs everything, and **auto-reboots** on success.
- Official estimate is **20–40 min**; on the slower **3B+ expect the upper end or a bit more**
  (TensorFlow/pip). Leave it. If it dies on a flaky download, just re-run the same line.
- Our auto-location cron + the mic-auth Caddyfile are applied automatically here.

After it reboots, SSH back in.

## 4. Post-install config (the manual values)

### 4a. Dutch detection names
Open `http://birdnet.local` → menu → Settings (or BirdNET-Pi's `/views.php`), set
**Language = Dutch (nl)** so detections come through as Dutch common names.
(Or in `/etc/birdnet/birdnet.conf` set `DATABASE_LANG=nl` then `sudo systemctl restart birdnet_analysis`.)

### 4b. Map basemap key (Stadia) — needed for the kaart tab to show tiles off-localhost
```bash
# add the key to the PHP-FPM pool env, then restart PHP
echo 'env[STADIA_API_KEY] = "<STADIA_KEY>"' | sudo tee -a /etc/php/*/fpm/pool.d/www.conf
sudo systemctl restart php*-fpm.service
```
> Note: Stadia keys can be origin-restricted in the Stadia dashboard. If the map is blank,
> add your Tailscale hostname / the Pi IP as an allowed origin there (or leave the key
> unrestricted). The map is non-critical — everything else works without it.

### 4c. Microphone privacy (option A — lock audio only)
```bash
# set a password, then regenerate the Caddyfile with the auth rules
sudo sed -i 's/^CADDY_PWD=.*/CADDY_PWD=<MIC_PASSWORD>/' /etc/birdnet/birdnet.conf
sudo bash ~/BirdNET-Pi/scripts/update_caddyfile.sh
```
Now the live stream + recorded clips + the map's location-edit need user `birdnet` +
`<MIC_PASSWORD>`. The collage/atlas/map/stats stay open for the family.

### 4d. Verify auto-location got installed (should already be there from the installer)
```bash
ls -l /usr/local/bin/auto_location.sh          # symlink should exist
grep auto_location /etc/crontab                # should show the @reboot line
grep AUTO_LOCATION /etc/birdnet/birdnet.conf   # should be true
```
On every boot it sets a **general** location from the (tethered) IP if you've moved
>50 km. It's coarse on 4G — fine for the regional species list, correctable by hand later.

### 4e. SmallTV-Ultra collage display (GeekMagic)
Pushes a packed last-24h collage (the website look, 240×240) to a GeekMagic
SmallTV-Ultra over its HTTP API. It **auto-discovers** the device on whatever
network it's on (tries a cached IP, then scans the /24 for the GeekMagic
fingerprint), so it keeps working after moving to the caravan — **as long as the
SmallTV and the Pi are on the same network** (see §7 / the gotcha below).
```bash
# install the push service (auto-discovers the device; pushes every 5 min,
# skipping re-uploads when the species set hasn't changed, to spare its flash)
sed -e "s|__USER__|$USER|g" -e "s|__HOME__|$HOME|g" \
  ~/BirdNET-Pi/templates/smalltv_push.service \
  | sudo tee /etc/systemd/system/smalltv_push.service >/dev/null
sudo systemctl daemon-reload && sudo systemctl enable --now smalltv_push.service
journalctl -u smalltv_push -f          # watch it discover + push
```
Manual one-offs (handy for testing):
```bash
PY=~/BirdNET-Pi/birdnet/bin/python3; SP=~/BirdNET-Pi/scripts/smalltv_push.py
$PY $SP --discover                       # print the SmallTV IP it finds
$PY $SP --db ~/BirdNET-Pi/scripts/birds.db --out /tmp/preview.jpg   # preview, no device
$PY $SP --db ~/BirdNET-Pi/scripts/birds.db --host <ip>              # push once
```
> The SmallTV is a **separate WiFi device** — at dad's it must also join dad's
> network (its own AP-fallback config page, or `http://<smalltv-ip>/network.html`).
> Once both are on the same LAN, the Pi finds it automatically.

## 5. USB tethering (the trip's internet)

1. Plug the phone into the Pi with USB.
2. On the phone: Settings → enable **USB tethering**.
3. The Pi auto-gets internet over USB (no config). Verify:
```bash
ping -c2 1.1.1.1        # internet reachable?
ip route | grep default # default route via the usb interface
```
> Android usually needs USB tethering re-toggled each time it's plugged in — worth telling Dad.

## 6. Tailscale (remote + any-device access)

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up --ssh
```
- `tailscale up` prints a URL — open it, log in with your Tailscale account to add the Pi.
- `--ssh` also lets you SSH the Pi from anywhere on your tailnet.
- Then from **any device on your tailnet** (your laptop, Dad's phone/tablet with the
  Tailscale app logged in): open `http://birdnet/` (MagicDNS) or `http://<pi-tailscale-ip>/`.
- Works over the 4G USB tether (Tailscale punches through CGNAT). This is how Dad reaches
  it when the only network is the USB tether.
- `siem.codes` later: a **Tailscale Funnel** or a Cloudflare Tunnel can map a public URL to
  the Pi — build when wanted.

## 7. WiFi networks (auto-switch)

> ⚠️ **Check what's actually saved first** — `nmcli connection show`. If the only
> connection is "Wired connection 1", the Imager WiFi config didn't take and the Pi
> will **only** work on Ethernet until you add a network (this was the case on the
> shakeout Pi). The radio still works (`nmcli device wifi list` shows networks);
> there's just nothing saved to join.

Add each network you want it to auto-join (home, dad's caravan router, and/or dad's
phone **hotspot** as a fallback):
```bash
sudo nmcli device wifi connect "<SSID>" password "<PW>"   # if in range now
# or add without being in range (pre-stage dad's network before the trip):
sudo nmcli connection add type wifi con-name "<SSID>" ssid "<SSID>"
sudo nmcli connection modify "<SSID>" wifi-sec.key-mgmt wpa-psk wifi-sec.psk "<PW>" connection.autoconnect yes
nmcli connection show     # confirm it's listed with AUTOCONNECT yes
```
It auto-joins any known network in range; no switching needed. (The SmallTV must
join the same network too — see §4e.)

## 8. Final check

- `http://birdnet.local/` (home) or `http://birdnet/` (Tailscale) loads the Dutch collage.
- Play a bird sound near the mic → it appears within ~30 s.
- `journalctl -t avian-autolocation -b` → shows the boot location decision.
- Reboot once and confirm it comes back up clean.

---

### Notes / gotchas
- **Mic**: a **USB microphone** must be plugged in (the 3B+ has no audio input) — the project
  uses a USB lavalier (~$17). Check `arecord -l` lists it. The printed mic mount/case is **not
  rain-proof** ("california weather grade") — shelter the mic if it's outside the caravan.
- **First-run location** starts at 0,0 until the first successful IP lookup, then adopts it.
- **Remote access:** Tailscale (step 6) is best for *private* multi-device access. For a public
  **siem.codes** URL later, the documented path is a **Cloudflare Tunnel** (`cloudflared`) — the
  fork has `avian/forwarding/` for it, and the author runs their own public instance this way.
- **SmallTV-Ultra (done):** the Pi pushes a packed last-24h collage to the GeekMagic over
  its HTTP API (`scripts/smalltv_push.py` + the `smalltv_push.service` in §4e). No `/frame.png`
  endpoint needed — the Pi composes the image and uploads it. Auto-discovers the device on the
  LAN, so it survives a network change. Verified live on a SmallTV-Ultra.
- **Power supply:** use a solid **5V/3A** USB-C/micro-USB supply. On the shakeout Pi
  `vcgencmd get_throttled` showed under-voltage had occurred (`0x…0008` family) — a weak supply
  or thin cable causes brown-outs that can corrupt the SD card. In the caravan, don't run it off a
  marginal car-USB port.
- **Memory/swap (3B+, 1 GB):** the analyzer + push run comfortably (load ~0.5, push peaks <40 MB),
  but headroom is thin. Keep a persistent swapfile: `/swapfile` (2 GB) in `/etc/fstab` alongside
  zram. The installer's dphys step **fails on Debian Trixie** (zram, no dphys) — add it by hand:
  `sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile && echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab`.
- **Web UI at `/`:** the installer now regenerates the Caddyfile so `http://birdnet.local/` serves
  the AvianVisitors collage (not stock BirdNET-Pi). If you ever see the stock page, run
  `sudo bash ~/BirdNET-Pi/scripts/update_caddyfile.sh` — it must run **as root** (it writes
  `/etc/caddy/Caddyfile`); the script now self-elevates if you forget.
- Shakeout install done on a real 3B+ (Debian Trixie): recognition, collage push, auto-location,
  and a clean reboot all verified. Live-mic capture is the one thing left to confirm on site.
