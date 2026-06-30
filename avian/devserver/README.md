# Local dev server (login test harness)

Test the 3-tier login + menu **without a Raspberry Pi**. This serves the real
`avian/frontend` and fakes the PHP/Caddy backend, faithfully reproducing the
per-path, per-user Basic-auth rules the caravan deploy uses. **Dev only — never
shipped to the Pi.**

## Run

```bash
python avian/devserver/mockserver.py
```

Then open <http://localhost:8089/>. Stop with Ctrl+C.

(Port in use? `PORT=8090 python avian/devserver/mockserver.py`.)

## The three tiers

Press **`menu`** (top-right) and type a password:

| Tier | Password | Access |
|------|----------|--------|
| anonymous | *(don't log in)* | collage / atlas / stats / basemap only — **0 sounds, 0 pins** |
| pensionado | `live` | everything, **including** the live mic stream |
| admin (Siem) | `beheer` | everything **except** the live mic |

Override the passwords with env vars: `LIVE_PWD=... ADMIN_PWD=... python avian/devserver/mockserver.py`.

In a real deploy these map to `LIVE_PWD` (pensionado) and `CADDY_PWD` (admin)
in `birdnet.conf`; the usernames are `pensionado` and `birdnet`. The browser
only ever asks for the password — the frontend figures out the tier.

## What's mocked

- Static frontend + the bundled bird illustrations (collage renders for real).
- `birdnet-api.php` (recent/stats/lifelist/species/locations/journey), `menu.php`,
  `config.php`, `recording.php` (a generated tone), `/stream` (a generated tone),
  `spectrogram.php`, `birdnet-status.php`, `smalltv-config.php`.
- The auth gate: public paths open; gated paths need a tier; `/stream` is
  pensionado-only. 401s carry `WWW-Authenticate` like Caddy does.

Detection data is a fixed handful of species — enough to exercise the UI, not
real birds.
