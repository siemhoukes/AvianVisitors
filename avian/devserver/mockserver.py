#!/usr/bin/env python3
"""AvianVisitors local dev server — test the 3-tier login WITHOUT a Pi.

Serves the *real* frontend (avian/frontend) + bundled assets and mocks the
PHP/Caddy backend, faithfully reproducing the per-path, per-user Basic-auth
rules that scripts/update_caddyfile.sh writes for the caravan deploy. It is a
TEST HARNESS ONLY — never shipped to the Pi.

Three access tiers (matches the Caddyfile):
  - anonymous       : collage / atlas / stats / basemap only. 0 sounds, 0 pins.
  - pensionado      : everything, INCLUDING the live mic stream.   (user "pensionado")
  - admin (Siem)    : everything EXCEPT the live mic stream.       (user "birdnet")

Run:  python avian/devserver/mockserver.py   then open http://localhost:8089/
Passwords are printed on startup (override with LIVE_PWD / ADMIN_PWD env vars).
"""
import base64
import io
import json
import math
import os
import struct
import sys
import wave
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

# ---- locate the repo's frontend + assets relative to this file --------------
HERE = os.path.dirname(os.path.abspath(__file__))
AVIAN = os.path.dirname(HERE)                      # .../avian
FRONTEND = os.path.join(AVIAN, "frontend")
ASSETS = os.path.join(AVIAN, "assets")

# ---- credentials (the two tiers). Plain text here = the mock's stand-in for
#      LIVE_PWD / CADDY_PWD in birdnet.conf. ----------------------------------
LIVE_USER = "pensionado"
LIVE_PWD = os.environ.get("LIVE_PWD", "live")
ADMIN_USER = "birdnet"
ADMIN_PWD = os.environ.get("ADMIN_PWD", "beheer")
PORT = int(os.environ.get("PORT", "8089"))

ROLE_ANON, ROLE_PENS, ROLE_ADMIN = "anon", "pensionado", "admin"


def role_of(authorization):
    """Decode a Basic auth header to a role, validating the password."""
    if not authorization or not authorization.lower().startswith("basic "):
        return ROLE_ANON
    try:
        raw = base64.b64decode(authorization.split(" ", 1)[1]).decode("utf-8", "replace")
        user, _, pwd = raw.partition(":")
    except Exception:
        return ROLE_ANON
    if user == LIVE_USER and pwd == LIVE_PWD:
        return ROLE_PENS
    if user == ADMIN_USER and pwd == ADMIN_PWD:
        return ROLE_ADMIN
    return ROLE_ANON


# ---- mock detection data (a handful of species that have bundled art) -------
SPECIES = [
    ("Turdus merula", "Merel", 42),
    ("Parus major", "Koolmees", 31),
    ("Erithacus rubecula", "Roodborst", 19),
    ("Fringilla coelebs", "Vink", 14),
    ("Passer domesticus", "Huismus", 12),
    ("Carduelis carduelis", "Putter", 8),
    ("Columba palumbus", "Houtduif", 6),
    ("Cyanistes caeruleus", "Pimpelmees", 5),
]


def now_iso():
    return datetime.now(timezone.utc).astimezone().isoformat()


# Pi-wide livestream-encoder switch (birdnet-status.php?action=livestream).
# In-memory bool standing in for `systemctl is-enabled livestream`.
_LIVESTREAM = {"enabled": True}

# ---- mock live-guess feed (guesses.php / #admin=live) ------------------------
# Stands in for the JSONL that scripts/utils/guesses.py writes: one record per
# 3s analysis slot, extended lazily up to "now" on each request. A tiny state
# machine makes birds "sing" for a few consecutive slots so the feed looks and
# scrolls like the real thing.
_GUESSES = {"slots": [], "last": None, "bird": None, "left": 0}
_GUESS_MAX = 2400  # ~2h of 3s slots kept in memory


def _guess_extend():
    import random
    now = datetime.now().replace(microsecond=0)
    last = _GUESSES["last"] or (now - timedelta(minutes=15))
    t = last
    while t + timedelta(seconds=3) <= now:
        t += timedelta(seconds=3)
        if _GUESSES["left"] <= 0:
            r = random.random()
            if r < 0.40:
                _GUESSES["bird"] = None                       # silence
            elif r < 0.46:
                _GUESSES["bird"] = "human"
            else:
                _GUESSES["bird"] = random.choice(SPECIES)
            _GUESSES["left"] = random.randint(1, 5)
        _GUESSES["left"] -= 1
        ts = t.strftime("%Y-%m-%dT%H:%M:%S")
        if _GUESSES["bird"] == "human":
            rec = {"t": ts, "s": 0.0, "e": 3.0, "status": "human",
                   "top": [{"sci": "Human_Human", "com": "Human_Human", "conf": 0.0}]}
        elif _GUESSES["bird"] is None:
            sci, com, _ = random.choice(SPECIES)
            rec = {"t": ts, "s": 0.0, "e": 3.0, "status": "quiet",
                   "top": [{"sci": sci, "com": com, "conf": round(random.uniform(0.005, 0.04), 3)}]}
        else:
            sci, com, _ = _GUESSES["bird"]
            conf = round(random.uniform(0.1, 0.97), 3)
            others = random.sample([s for s in SPECIES if s[0] != sci], 2)
            top = [{"sci": sci, "com": com, "conf": conf}]
            for osci, ocom, _n in others:
                oconf = round(random.uniform(0.05, max(0.06, conf / 2)), 3)
                top.append({"sci": osci, "com": ocom, "conf": oconf})
            status = "confident" if conf >= 0.7 else "below_confidence"
            if status == "confident" and random.random() < 0.1:
                status = "sf_thresh"
            rec = {"t": ts, "s": 0.0, "e": 3.0, "status": status, "top": top}
        _GUESSES["slots"].append(rec)
    _GUESSES["last"] = t
    del _GUESSES["slots"][:-_GUESS_MAX]


def guesses_live_payload(n, since):
    _guess_extend()
    out = []
    for rec in reversed(_GUESSES["slots"]):
        if since and rec["t"] <= since:
            break
        out.append(rec)
        if len(out) >= n:
            break
    return {"slots": out, "confidence": 0.7, "now": now_iso()}


def guesses_species_payload(hours):
    _guess_extend()
    cutoff = (datetime.now() - timedelta(hours=hours)).strftime("%Y-%m-%dT%H:%M:%S")
    agg = {}
    for rec in _GUESSES["slots"]:
        if rec["t"] < cutoff or rec["status"] in ("human", "quiet"):
            continue
        top = rec["top"][0]
        a = agg.setdefault(top["sci"], {"sci": top["sci"], "com": top["com"],
                                        "n": 0, "best": 0.0, "last": "", "n_confident": 0})
        a["n"] += 1
        a["best"] = max(a["best"], top["conf"])
        a["last"] = max(a["last"], rec["t"])
        if rec["status"] == "confident":
            a["n_confident"] += 1
    out = sorted(agg.values(), key=lambda a: -a["best"])
    return {"species": out, "hours": hours, "now": now_iso()}


def names_payload(lang):
    # Mirrors birdnet-api.php action=names: the real l18n labels file,
    # filtered to the species "in the DB" (our mock list).
    labels_path = os.path.join(HERE, "..", "..", "model", "l18n", f"labels_{lang}.json")
    if not os.path.isfile(labels_path):
        return None
    with open(labels_path, encoding="utf-8") as f:
        labels = json.load(f)
    return {"lang": lang,
            "names": {sci: labels[sci] for sci, _, _ in SPECIES if sci in labels},
            "as_of": now_iso()}


def recent_payload(hours=24, location=False):
    base = datetime.now()
    species = []
    # "deze plek" is a smaller subset - only some species were heard at the
    # CURRENT LATITUDE/LONGITUDE, exercising the filtered (not just relabeled)
    # path so the empty/partial states are also testable offline.
    rows = SPECIES[:5] if location else SPECIES
    for i, (sci, com, n) in enumerate(rows):
        last = base - timedelta(minutes=7 * i + 3)
        species.append({
            "sci": sci, "com": com, "n": n,
            "best_conf": round(0.95 - i * 0.03, 4),
            "last_seen": last.strftime("%Y-%m-%d %H:%M:%S"),
            "top_file": f"{sci.replace(' ', '_')}-sample.wav",
            "top_at": last.strftime("%Y-%m-%d %H:%M:%S"),
        })
    return {"hours": hours, "from": "", "to": "", "location": location,
            "location_available": True, "species": species, "as_of": now_iso()}


def stats_payload():
    total = sum(n for _, _, n in SPECIES)
    return {
        "totals": {"detections": total, "species": len(SPECIES)},
        "today": {"detections": 37, "species": 6},
        "last_hour": {"detections": 4},
        "week": {"detections": total, "species": len(SPECIES)},
        "started": "2026-06-20",
        "as_of": now_iso(),
    }


def lifelist_payload():
    base = datetime.now()
    sp = []
    for i, (sci, com, n) in enumerate(SPECIES):
        first = base - timedelta(days=len(SPECIES) - i)
        sp.append({
            "sci": sci, "com": com,
            "first_seen": first.strftime("%Y-%m-%d %H:%M:%S"),
            "last_seen": base.strftime("%Y-%m-%d %H:%M:%S"),
            "n": n, "best_conf": round(0.95 - i * 0.03, 4),
        })
    return {"species": sp, "as_of": now_iso()}


def firstseen_payload(limit=10):
    sp = lifelist_payload()["species"][::-1][:limit]
    return {"species": [{"sci": s["sci"], "com": s["com"],
                         "first_seen": s["first_seen"], "total": s["n"]} for s in sp],
            "as_of": now_iso()}


def timeseries_payload(days=30):
    base = datetime.now().date()
    daily = []
    for d in range(days):
        day = base - timedelta(days=days - 1 - d)
        daily.append({"date": day.strftime("%Y-%m-%d"),
                      "detections": (d * 3) % 17 + 1, "species": (d % 6) + 1})
    by_hour = [{"hour": h, "detections": (h * 5) % 23} for h in range(24)]
    return {"days": days, "daily": daily, "by_hour": by_hour, "as_of": now_iso()}


def species_payload(sci):
    base = datetime.now()
    dets = []
    for i in range(6):
        t = base - timedelta(hours=i * 5 + 1)
        dets.append({"d": t.strftime("%Y-%m-%d"), "t": t.strftime("%H:%M:%S"),
                     "file": f"{sci.replace(' ', '_')}-{i}.wav",
                     "conf": round(0.95 - i * 0.05, 4)})
    com = next((c for s, c, _ in SPECIES if s == sci), sci)
    summary = {"com": com, "total": 42,
               "first_seen": (base - timedelta(days=8)).strftime("%Y-%m-%d %H:%M:%S"),
               "last_seen": base.strftime("%Y-%m-%d %H:%M:%S"), "best_conf": 0.95}
    return {"sci": sci, "summary": summary, "detections": dets}


def moderation_moments():
    """Synthetic moments for the hide/unhide admin screen - a handful per
    species so pagination + the hidden-state toggle are all exercisable
    without a real birds.db."""
    base = datetime.now()
    out = []
    rid = 1000
    for i, (sci, com, n) in enumerate(SPECIES):
        for k in range(3):
            t = base - timedelta(hours=i * 3 + k * 7, minutes=k * 5)
            rowids = [rid, rid + 1] if k == 1 else [rid]
            rid += len(rowids)
            # Deterministic spread across the score bands (0.51 .. 0.97) so
            # the moderation "onzeker" filter and the color-coded bars are
            # all exercisable offline.
            conf = 0.51 + ((i * 37 + k * 53) % 47) / 100.0
            out.append({
                "sci": sci, "com": com,
                "file": f"{sci.replace(' ', '_')}-{i}-{k}.wav",
                "best_conf": round(conf, 4),
                "first_seen": t.strftime("%Y-%m-%d %H:%M:%S"),
                "last_seen": t.strftime("%Y-%m-%d %H:%M:%S"),
                "n": len(rowids),
                "rowids": rowids,
            })
    out.sort(key=lambda m: m["last_seen"], reverse=True)
    return out


_HIDDEN_ROWIDS = set()


def moderation_payload(q, limit, offset):
    moments = moderation_moments()
    if q:
        ql = q.lower()
        moments = [m for m in moments if ql in m["sci"].lower() or ql in m["com"].lower()]
    total = len(moments)
    page = moments[offset:offset + limit]
    out = []
    for m in page:
        hits = sum(1 for r in m["rowids"] if r in _HIDDEN_ROWIDS)
        out.append(dict(m, hidden=hits == len(m["rowids"]),
                        partially_hidden=0 < hits < len(m["rowids"])))
    return {"moments": out, "total": total, "limit": limit, "offset": offset, "as_of": now_iso()}


def locations_payload():
    """GATED: compatibility endpoint, now backed by the reisschema."""
    return {"locations": schedule_state()["schedule"], "as_of": now_iso()}


def config_payload():
    return {"values": {"CONFIDENCE": 0.7, "SENSITIVITY": 1.25, "OVERLAP": 0.0,
                       "AUDIO_HIGHPASS_FREQ": 300, "FULL_DISK": "purge",
                       "AV_GROUP_ENABLED": True, "AV_GROUP_GAP_SEC": 15},
            "meta": {}, "preserve": False}


# ---- manual date+time travel schedule (replaces IP geolocation) -------------
# Each entry = "from this date+time, we're at this place". The ACTIVE location is
# the most recent entry whose timestamp is <= now; future entries are pre-staged.
# Timestamps are "YYYY-MM-DDTHH:MM" (lexicographic order == chronological order).
SCHEDULE = [
    {"id": 1, "from_ts": "2026-06-18T09:00", "lat": 52.0907, "lon": 5.1214, "label": "Utrecht, Nederland"},
    {"id": 2, "from_ts": "2026-06-24T15:00", "lat": 44.8378, "lon": -0.5792, "label": "Bordeaux, Frankrijk"},
    {"id": 3, "from_ts": "2026-06-29T18:00", "lat": 42.8782, "lon": -8.5448, "label": "Santiago de Compostela, Spanje"},
]
_next_id = [4]


def schedule_state():
    now = datetime.now().strftime("%Y-%m-%dT%H:%M")
    sched = [dict(e) for e in sorted(SCHEDULE, key=lambda e: (e["from_ts"], e["id"]))]
    for i, e in enumerate(sched):
        e["until_ts"] = sched[i + 1]["from_ts"] if i + 1 < len(sched) else None
        base = datetime.strptime(e["from_ts"], "%Y-%m-%dT%H:%M")
        picked = SPECIES[i:i + 4] or SPECIES[:3]
        e["species"] = [{
            "sci": s, "com": c, "n": n,
            "first_seen": (base + timedelta(hours=1)).strftime("%Y-%m-%d %H:%M:%S"),
            "last_seen": (base + timedelta(days=1, hours=2)).strftime("%Y-%m-%d %H:%M:%S"),
        } for s, c, n in picked]
        e["n"] = sum(s["n"] for s in e["species"])
        e["first_seen"] = e["species"][0]["first_seen"] if e["species"] else None
        e["last_seen"] = e["species"][-1]["last_seen"] if e["species"] else None
    past = [e for e in sched if e["from_ts"] <= now]
    return {"schedule": sched, "active": (past[-1] if past else None), "now": now}


# canned geocoder (the real geocode.php proxies Nominatim; this keeps the dev
# server self-contained + deterministic offline)
_GEO = [
    ("Utrecht, Nederland", 52.0907, 5.1214), ("Amsterdam, Nederland", 52.3676, 4.9041),
    ("Bordeaux, Frankrijk", 44.8378, -0.5792), ("Parijs, Frankrijk", 48.8566, 2.3522),
    ("San Sebastián, Spanje", 43.3183, -1.9812), ("Bilbao, Spanje", 43.2630, -2.9350),
    ("Santiago de Compostela, Spanje", 42.8782, -8.5448), ("Madrid, Spanje", 40.4168, -3.7038),
    ("Porto, Portugal", 41.1579, -8.6291), ("Lissabon, Portugal", 38.7223, -9.1393),
]


def geocode_results(q):
    q = (q or "").strip().lower()
    if not q:
        return []
    hits = [{"label": n, "lat": la, "lon": lo} for (n, la, lo) in _GEO if q in n.lower()]
    if not hits:  # fallback so the search flow is always testable offline
        hits = [{"label": q.title() + " (voorbeeld)", "lat": 45.0, "lon": 2.0}]
    return hits[:5]


# ---- tiny generated media (so playback + spectrogram actually work) ---------
def tone_wav(seconds=4.0, base_hz=900.0, sr=22050):
    buf = io.BytesIO()
    w = wave.open(buf, "wb")
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(sr)
    frames = bytearray()
    n = int(seconds * sr)
    for i in range(n):
        t = i / sr
        # warble + overtones so the spectrogram has something bird-like to show
        f = base_hz + 350 * math.sin(2 * math.pi * 4 * t)
        env = 0.4 * (1 + math.sin(2 * math.pi * 2 * t))
        s = env * (math.sin(2 * math.pi * f * t) + 0.3 * math.sin(2 * math.pi * 2 * f * t))
        frames += struct.pack("<h", int(max(-1, min(1, s)) * 12000))
    w.writeframes(bytes(frames)); w.close()
    return buf.getvalue()


_PNG_1x1 = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")


def slugify(sci):
    out = []
    for ch in sci.lower():
        out.append(ch if ch.isalnum() else "-")
    s = "".join(out)
    while "--" in s:
        s = s.replace("--", "-")
    return s.strip("-")


CONTENT_TYPES = {
    ".html": "text/html; charset=utf-8", ".js": "application/javascript; charset=utf-8",
    ".css": "text/css; charset=utf-8", ".json": "application/json; charset=utf-8",
    ".png": "image/png", ".jpg": "image/jpeg", ".svg": "image/svg+xml",
    ".ico": "image/x-icon",
}

REALM = "birdnet"


class Handler(BaseHTTPRequestHandler):
    server_version = "AvianMock/1.0"

    def log_message(self, fmt, *args):
        sys.stderr.write("  %s\n" % (fmt % args))

    # -- helpers --------------------------------------------------------------
    def _send(self, code, body=b"", ctype="text/plain; charset=utf-8", extra=None):
        if isinstance(body, str):
            body = body.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _json(self, obj, code=200):
        self._send(code, json.dumps(obj), "application/json; charset=utf-8")

    def _read_json(self):
        try:
            n = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(n) if n else b""
            return json.loads(raw.decode("utf-8") or "{}")
        except Exception:
            return {}

    def _401(self):
        # BARE 401 — deliberately NO WWW-Authenticate header. That header is what
        # makes browsers throw the native username/password popup; we don't need
        # it because the frontend sends the Authorization header explicitly and
        # handles 401s itself (shows the in-app login). The real Caddy deploy
        # must likewise avoid emitting the challenge (app-level auth, not Caddy
        # basic_auth) to stay popup-free.
        self._send(401, json.dumps({"error": "unauthorized"}),
                   "application/json; charset=utf-8")

    def _file(self, path, ctype=None):
        if not os.path.isfile(path):
            self._send(404, "not found")
            return
        ctype = ctype or CONTENT_TYPES.get(os.path.splitext(path)[1].lower(),
                                           "application/octet-stream")
        with open(path, "rb") as f:
            data = f.read()
        # static assets are public + cacheable
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(data)

    # -- routing --------------------------------------------------------------
    def do_HEAD(self):
        self.route()

    def do_GET(self):
        self.route()

    def do_POST(self):
        self.route()

    def route(self):
        u = urlparse(self.path)
        path = u.path
        q = parse_qs(u.query)
        role = role_of(self.headers.get("Authorization"))

        # ---- static frontend -------------------------------------------------
        if path == "/" or path == "/index.html":
            return self._file(os.path.join(FRONTEND, "index.html"), CONTENT_TYPES[".html"])
        if path in ("/apt.js", "/styles.css", "/dims.json", "/masks.json"):
            return self._file(os.path.join(FRONTEND, path.lstrip("/")))
        if path == "/favicon.png":
            return self._file(os.path.join(ASSETS, "favicon.png"), "image/png")
        if path.startswith("/avian/assets/"):
            rel = path[len("/avian/assets/"):].replace("..", "")
            return self._file(os.path.join(ASSETS, *rel.split("/")))

        # ---- PUBLIC api: collage / atlas / stats ----------------------------
        if path == "/avian/api/cutout.php":
            sci = (q.get("sci", [""])[0]).strip()
            pose = q.get("pose", ["1"])[0]
            slug = slugify(sci)
            suffix = "-2" if pose == "2" else ""
            for cand in (os.path.join(ASSETS, "illustrations", f"{slug}{suffix}.png"),
                         os.path.join(ASSETS, "illustrations", f"{slug}.png"),
                         os.path.join(ASSETS, "cutouts", f"{slug}.png")):
                if os.path.isfile(cand) and os.path.getsize(cand) > 1024:
                    return self._file(cand, "image/png")
            return self._send(404, "no illustration")
        if path == "/avian/api/spectrogram.php":
            return self._send(200, _PNG_1x1, "image/png")
        if path == "/avian/api/birdnet-api.php":
            action = q.get("action", ["stats"])[0]
            public = {"stats": stats_payload, "recent": recent_payload,
                      "lifelist": lifelist_payload, "firstseen": firstseen_payload,
                      "timeseries": timeseries_payload}
            if action in public:
                if action == "recent":
                    return self._json(recent_payload(int(q.get("hours", ["24"])[0] or 24),
                                                     q.get("location", ["0"])[0] == "1"))
                if action == "firstseen":
                    return self._json(firstseen_payload(int(q.get("limit", ["10"])[0] or 10)))
                if action == "timeseries":
                    return self._json(timeseries_payload(int(q.get("days", ["30"])[0] or 30)))
                return self._json(public[action]())
            if action == "species":
                return self._json(species_payload(q.get("sci", [""])[0]))
            if action == "mapconfig":
                return self._json({"stadia_key": ""})   # blank -> basemap degrades gracefully
            if action == "names":
                lang = q.get("lang", ["en"])[0]
                payload = names_payload(lang)
                if payload is None:
                    return self._json({"error": f"no labels for '{lang}'"}, 404)
                return self._json(payload)
            # GATED actions: locations / journey reveal where you are
            if action in ("locations", "journey"):
                if role == ROLE_ANON:
                    return self._401()
                if action == "locations":
                    return self._json(locations_payload())
                return self._json({"species": [], "as_of": now_iso()})
            return self._json({"error": "unknown action"}, 404)

        # ---- GATED (pensionado OR admin) ------------------------------------
        if path == "/avian/api/menu.php":
            if role == ROLE_ANON:
                return self._401()
            return self._json({"items": [
                {"label": "settings", "href": "/#admin=settings", "native": True},
                {"label": "system", "href": "/#admin=system", "native": True},
                {"label": "live", "href": "/#admin=live", "native": True},
                {"label": "history", "href": "/#admin=history", "native": True},
                {"label": "logs", "href": "/#admin=logs", "native": True},
                {"label": "tools", "href": "/#admin=tools", "native": True},
            ]})
        if path == "/avian/api/guesses.php":
            if role == ROLE_ANON:
                return self._401()
            op = q.get("op", ["live"])[0]
            if op == "live":
                n = max(10, min(500, int(q.get("n", ["120"])[0] or 120)))
                return self._json(guesses_live_payload(n, q.get("since", [""])[0]))
            if op == "species":
                hours = max(1, min(72, int(q.get("hours", ["24"])[0] or 24)))
                return self._json(guesses_species_payload(hours))
            return self._json({"error": "unknown op"}, 400)
        if path == "/avian/api/config.php":
            if role == ROLE_ANON:
                return self._401()
            if self.command == "POST":
                return self._json({"ok": True, "updates": {}, "restarted": {}})
            return self._json(config_payload())
        if path == "/avian/api/moderation.php":
            # Both logged-in tiers - mirrors the AUTH_BOTH basicauth block
            # in scripts/update_caddyfile.sh.
            if role == ROLE_ANON:
                return self._401()
            if self.command == "POST":
                body = self._read_json()
                op = body.get("op")
                rowids = [int(r) for r in (body.get("rowids") or [])]
                if op == "hide":
                    _HIDDEN_ROWIDS.update(rowids)
                elif op == "unhide":
                    _HIDDEN_ROWIDS.difference_update(rowids)
                else:
                    return self._json({"error": "unknown op"}, 400)
                return self._json({"ok": True, "op": op, "rowids": rowids})
            limit = int(q.get("limit", ["100"])[0] or 100)
            offset = int(q.get("offset", ["0"])[0] or 0)
            return self._json(moderation_payload(q.get("q", [""])[0].strip(), limit, offset))
        if path == "/avian/api/recording.php":
            if role == ROLE_ANON:
                return self._401()
            return self._send(200, tone_wav(), "audio/wav")
        if path == "/avian/api/location-edit.php":
            if role == ROLE_ANON:
                return self._401()
            return self._json({"error": "location edits moved to reisschema",
                               "use": "location-schedule.php"}, 410)
        if path == "/avian/api/location-schedule.php":
            if role == ROLE_ANON:
                return self._401()
            if self.command == "POST":
                body = self._read_json()
                op = body.get("op")
                if op == "add":
                    SCHEDULE.append({"id": _next_id[0], "from_ts": body.get("from_ts", ""),
                                     "lat": float(body.get("lat", 0)), "lon": float(body.get("lon", 0)),
                                     "label": body.get("label", "")})
                    _next_id[0] += 1
                elif op == "update":
                    for e in SCHEDULE:
                        if e["id"] == body.get("id"):
                            e.update({"from_ts": body.get("from_ts", e["from_ts"]),
                                      "lat": float(body.get("lat", e["lat"])),
                                      "lon": float(body.get("lon", e["lon"])),
                                      "label": body.get("label", e["label"])})
                elif op == "delete":
                    SCHEDULE[:] = [e for e in SCHEDULE if e["id"] != body.get("id")]
                return self._json(dict(schedule_state(), ok=True))
            return self._json(schedule_state())
        if path == "/avian/api/geocode.php":
            if role == ROLE_ANON:
                return self._401()
            return self._json({"results": geocode_results(q.get("q", [""])[0])})
        if path == "/avian/api/smalltv-config.php":
            if self.command == "POST" and role == ROLE_ANON:
                return self._401()
            return self._json({"window": "24"})
        if path == "/avian/api/birdnet-status.php":
            if role == ROLE_ANON:
                return self._401()
            action = q.get("action", ["diag"])[0]
            if action == "logs":
                return self._json({"unit": q.get("unit", ["?"])[0],
                                   "lines": ["[mock] service log line 1", "[mock] line 2"]})
            if action == "restart":
                return self._json({"ok": True})
            if action == "livestream":
                if self.command == "POST":
                    _LIVESTREAM["enabled"] = q.get("on", ["1"])[0] == "1"
                return self._json({"enabled": _LIVESTREAM["enabled"],
                                   "active": _LIVESTREAM["enabled"]})
            return self._json({"services": [
                {"unit": "birdnet_analysis", "active": True, "sub": "running"},
                {"unit": "birdnet_recording", "active": True, "sub": "running"}],
                "disk": {"used": 4200000000, "total": 31000000000},
                "cpu_temp": 59.6, "load": [0.4, 0.5, 0.6], "uptime_s": 86400})

        # ---- PENSIONADO ONLY: the live mic ----------------------------------
        if path == "/stream":
            if role != ROLE_PENS:
                return self._401()
            # a looping-ish tone stands in for the icecast mic stream
            return self._send(200, tone_wav(seconds=8.0, base_hz=600.0), "audio/wav")

        self._send(404, "not found")


def main():
    os.chdir(HERE)
    httpd = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    print("=" * 64)
    print("AvianVisitors mock dev server")
    print(f"  http://localhost:{PORT}/")
    print("-" * 64)
    print("  Tiers (enter the password in the top-right menu):")
    print(f"    pensionado (full, incl. LIVE mic):  user {LIVE_USER!r}  pwd {LIVE_PWD!r}")
    print(f"    admin/Siem (full, NO live mic):     user {ADMIN_USER!r}  pwd {ADMIN_PWD!r}")
    print(f"    anonymous: just don't log in (0 sounds, 0 pins)")
    print("=" * 64)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nbye")


if __name__ == "__main__":
    main()
