#!/usr/bin/env python3
"""AvianVisitors -> GeekMagic SmallTV-Ultra: a packed collage of the last 24 h.

Renders a 240x240 collage of every species heard in a window - the SAME look as
the website: silhouettes packed tightly (ported from apt.js maskPack), sized by
how often each bird was heard, soft drop-shadows, warm cream ground - then
pushes it to a SmallTV-Ultra over its HTTP API (from the hass-geekmagic
integration; verified live on a V9.x device):
    upload : POST http://<host>/doUpload?dir=/image/   multipart field "file"
    show   : GET  http://<host>/set?img=/image/<name>
    photo  : GET  http://<host>/set?theme=3            (Theme 3 = Photo Album)

Composition works with no device (use --out to save a preview JPEG); the push
only happens when --host is given.

    python scripts/smalltv_push.py --db scripts/live_birds.db --out frame.jpg     # preview
    python scripts/smalltv_push.py --db scripts/birds.db --host 192.168.1.249      # push once
    python scripts/smalltv_push.py --db scripts/birds.db --host 192.168.1.249 --loop 300
    python scripts/smalltv_push.py --host 192.168.1.249 --image any.jpg            # API test
"""
from __future__ import annotations
import argparse
import base64
import concurrent.futures
import io
import json
import math
import re
import socket
import sqlite3
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

from PIL import Image, ImageFilter

ROOT = Path(__file__).resolve().parents[1]
ILL = ROOT / "avian" / "assets" / "illustrations"
PLACEHOLDER = ROOT / "avian" / "assets" / "placeholder.png"
APT = (ROOT / "avian" / "frontend" / "apt.js").read_text(encoding="utf-8")
SIZE = 240
PAPER = (236, 217, 180)   # warm cream sampled from the cutouts' generation ground
GRID_STRIDE = 4
COLLAGE_PAD = 3
MASK_MAX = 93


def _table(name):
    m = re.search(r"var " + name + r" = (\{.*?\});", APT)
    return json.loads(m.group(1)) if m else {}

DIMS = _table("DIMS")
MASKS = _table("MASKS")


def slugify(sci):
    return re.sub(r"[^a-z0-9]+", "-", sci.lower()).strip("-")


def mask_cells_from_rec(rec):
    bits = base64.b64decode(rec["bits"]); w, h = rec["w"], rec["h"]
    cells = []
    for y in range(h):
        for x in range(w):
            i = y * w + x
            if (bits[i >> 3] >> (7 - (i & 7))) & 1:
                cells.append((x, y))
    return {"w": w, "h": h, "cells": cells}


_ph_cache = {}
def mask_from_png(path):
    """Build a silhouette mask (build_masks.py format) from any PNG's alpha -
    used for the placeholder so undrawn species still pack into the collage."""
    if path in _ph_cache:
        return _ph_cache[path]
    im = Image.open(path).convert("RGBA"); w, h = im.size
    ms = MASK_MAX / max(w, h); mw, mh = max(1, round(w * ms)), max(1, round(h * ms))
    a = im.getchannel("A").resize((mw, mh), Image.LANCZOS).load()
    cells = [(x, y) for y in range(mh) for x in range(mw) if a[x, y] > 127]
    rec = {"w": mw, "h": mh, "cells": cells, "ar": w / h}
    _ph_cache[path] = rec
    return rec


def tuning(n):
    return dict(
        budget=0.82 if n <= 4 else 0.76 if n <= 12 else 0.68 if n <= 24 else 0.60,
        countExp=0.65,
        minTile=0.0140 if n <= 8 else 0.0100 if n <= 20 else 0.0072,
    )


def paste_bird(frame, bird, x, y):
    """Paste a cutout with the website's soft drop-shadow (0 2px 6px ink@10%)."""
    sa = bird.split()[3].point(lambda v: int(v * 0.10))
    shadow = Image.new("RGBA", bird.size, (26, 22, 18, 0)); shadow.putalpha(sa)
    shadow = shadow.filter(ImageFilter.GaussianBlur(3))
    frame.paste(shadow, (round(x), round(y + 2)), shadow)
    frame.paste(bird, (round(x), round(y)), bird)


def mask_pack(tiles, W, H, xBias, yBias, pad):
    GW = math.ceil(W / GRID_STRIDE) + 2; GH = math.ceil(H / GRID_STRIDE) + 2
    grid = bytearray(GW * GH)

    def crange(t, tx, ty, c):
        sx = t["fullW"] / t["mask"]["w"]; sy = t["fullH"] / t["mask"]["h"]
        return (max(0, int((tx + c[0] * sx) / GRID_STRIDE)), max(0, int((ty + c[1] * sy) / GRID_STRIDE)),
                min(GW - 1, int((tx + (c[0] + 1) * sx) / GRID_STRIDE)), min(GH - 1, int((ty + (c[1] + 1) * sy) / GRID_STRIDE)))

    def collides(t, tx, ty):
        for c in t["mask"]["cells"]:
            x0, y0, x1, y1 = crange(t, tx, ty, c)
            for gy in range(y0, y1 + 1):
                off = gy * GW
                for gx in range(x0, x1 + 1):
                    if grid[off + gx]:
                        return True
        return False

    def stamp(t, tx, ty):
        for c in t["mask"]["cells"]:
            x0, y0, x1, y1 = crange(t, tx, ty, c)
            for gy in range(max(0, y0 - pad), min(GH - 1, y1 + pad) + 1):
                off = gy * GW
                for gx in range(max(0, x0 - pad), min(GW - 1, x1 + pad) + 1):
                    grid[off + gx] = 1

    def off_grid(t, tx, ty):
        return tx < 0 or ty < 0 or tx + t["fullW"] > W or ty + t["fullH"] > H

    cx, cy = W / 2, H / 2
    tiles.sort(key=lambda t: t["fullW"] * t["fullH"], reverse=True)
    seed = [0x9E3779B9]
    def rand():
        seed[0] = (seed[0] * 16807) % 2147483647; return seed[0] / 2147483647
    placed = []
    for i, t in enumerate(tiles):
        if i == 0:
            t["x"] = cx - t["fullW"] / 2; t["y"] = cy - t["fullH"] / 2
            stamp(t, t["x"], t["y"]); placed.append(t); continue
        comX = comY = comW = 0.0
        for p in placed:
            a = p["fullW"] * p["fullH"]
            comX += (p["x"] + p["fullW"] / 2) * a; comY += (p["y"] + p["fullH"] / 2) * a; comW += a
        comX /= comW; comY /= comW
        best = None; bestCost = float("inf")
        step = max(GRID_STRIDE, min(t["fullW"], t["fullH"]) * 0.05)
        maxR = max(W, H); foundRing = -1; phase = rand() * math.pi * 2; r = 0.0
        while r <= maxR:
            if foundRing >= 0 and r > foundRing + step * 2:
                break
            samples = max(36, int(r / 1.6))
            for k in range(samples):
                theta = phase + (k / samples) * math.pi * 2
                px = cx + r * xBias * math.cos(theta) - t["fullW"] / 2
                py = cy + r * yBias * math.sin(theta) - t["fullH"] / 2
                if off_grid(t, px, py) or collides(t, px, py):
                    continue
                cost = math.hypot((px + t["fullW"] / 2 - comX) / xBias, (py + t["fullH"] / 2 - comY) / yBias) + rand() * step * 0.5
                if cost < bestCost:
                    bestCost = cost; best = (px, py)
            if best and foundRing < 0:
                foundRing = r
            r += step
        if best:
            t["x"], t["y"] = best; stamp(t, *best); placed.append(t)
        else:
            t["x"] = t["y"] = -99999; placed.append(t)
    return placed


def compose_collage(species):
    """species: list of {sci, n}. Returns a 240x240 cream collage Image."""
    n = len(species)
    if not n:
        return Image.new("RGB", (SIZE, SIZE), PAPER)
    T = tuning(n); vp = SIZE * SIZE; budget = vp * T["budget"]; minArea = vp * T["minTile"]
    tiles = []
    for s in species:
        slug = slugify(s["sci"])
        if slug in MASKS and (ILL / f"{slug}.png").is_file():
            mask = mask_cells_from_rec(MASKS[slug]); d = DIMS.get(slug, [560, 400])
            ar = d[0] / d[1]; imgpath = ILL / f"{slug}.png"
        elif PLACEHOLDER.is_file():
            rec = mask_from_png(PLACEHOLDER); mask = rec; ar = rec["ar"]; imgpath = PLACEHOLDER
        else:
            continue
        tiles.append({"mask": mask, "ar": ar, "img": imgpath,
                      "score": max(1, s.get("n", 1)) ** T["countExp"]})
    if not tiles:
        return Image.new("RGB", (SIZE, SIZE), PAPER)
    ssum = sum(t["score"] for t in tiles) or 1
    for t in tiles:
        t["area"] = max(minArea, budget * t["score"] / ssum)
    sa = sum(t["area"] for t in tiles)
    if sa > budget:
        fixed = sum(t["area"] for t in tiles if t["area"] <= minArea + 1e-9)
        flex = sa - fixed; shrink = min(1, max(0, budget - fixed) / flex) if flex > 0 else 1
        for t in tiles:
            if t["area"] > minArea + 1e-9:
                t["area"] *= shrink
    for t in tiles:
        t["fullW"] = math.sqrt(t["area"] * t["ar"]); t["fullH"] = t["fullW"] / t["ar"]
    placed = mask_pack(tiles, SIZE, SIZE, 1.0, 1.0, COLLAGE_PAD - 1)

    def bounds(arr):
        L = Tp = float("inf"); R = B = float("-inf")
        for t in arr:
            if t["x"] < -1000:
                continue
            L = min(L, t["x"]); R = max(R, t["x"] + t["fullW"]); Tp = min(Tp, t["y"]); B = max(B, t["y"] + t["fullH"])
        return L, R, Tp, B
    L, R, Tp, B = bounds(placed)
    for _ in range(10):
        if not any(t["x"] < -1000 for t in placed) and not (L < 0 or Tp < 0 or R > SIZE or B > SIZE):
            break
        scale = 0.93
        if L < 0 or Tp < 0 or R > SIZE or B > SIZE:
            scale = min(scale, (SIZE * 0.99) / max(R - L, SIZE * 0.99), (SIZE * 0.99) / max(B - Tp, SIZE * 0.99))
        for t in tiles:
            t["fullW"] *= scale; t["fullH"] *= scale
        placed = mask_pack(tiles, SIZE, SIZE, 1.0, 1.0, COLLAGE_PAD - 1)
        L, R, Tp, B = bounds(placed)
    dx = SIZE / 2 - (L + R) / 2; dy = SIZE / 2 - (Tp + B) / 2
    frame = Image.new("RGB", (SIZE, SIZE), PAPER)
    for t in placed:
        if t["x"] < -1000:
            continue
        img = Image.open(t["img"]).convert("RGBA").resize(
            (max(1, round(t["fullW"])), max(1, round(t["fullH"]))), Image.LANCZOS)
        paste_bird(frame, img, t["x"] + dx, t["y"] + dy)
    return frame


def recent_species(db_path, hours, limit):
    c = sqlite3.connect(db_path); c.row_factory = sqlite3.Row
    rs = c.execute(
        "SELECT Sci_Name sci, COUNT(*) n FROM detections "
        "WHERE (julianday('now','localtime')-julianday(Date||' '||Time))*24 <= ? "
        "GROUP BY Sci_Name ORDER BY n DESC LIMIT ?", (hours, limit)).fetchall()
    c.close()
    return [dict(r) for r in rs]


def species_at_location(db_path, lat, lon, limit, tol=0.02):
    """All species ever heard at (lat,lon) - the 'deze plek' window, no time cap."""
    c = sqlite3.connect(db_path); c.row_factory = sqlite3.Row
    rs = c.execute(
        "SELECT Sci_Name sci, COUNT(*) n FROM detections "
        "WHERE abs(Lat-?)<? AND abs(Lon-?)<? GROUP BY Sci_Name ORDER BY n DESC LIMIT ?",
        (lat, tol, lon, tol, limit)).fetchall()
    c.close()
    return [dict(r) for r in rs]


WINDOW_HOURS = {"8h": 8, "24h": 24, "7d": 168}


def _conf_get(key, default=None, path="/etc/birdnet/birdnet.conf"):
    try:
        for line in open(path):
            if line.startswith(key + "="):
                return line.split("=", 1)[1].strip().strip('"').strip("'")
    except Exception:
        pass
    return default


def species_for_window(db_path, window, limit):
    """Pick species by the configured SmallTV window: 8h / 24h / 7d / location."""
    if window == "location":
        try:
            lat = float(_conf_get("LATITUDE", "0")); lon = float(_conf_get("LONGITUDE", "0"))
        except (TypeError, ValueError):
            return []
        return species_at_location(db_path, lat, lon, limit)
    return recent_species(db_path, WINDOW_HOURS.get(window, 24), limit)


# ---- SmallTV HTTP API ----
def _norm(host):
    return (host if host.startswith("http") else "http://" + host).rstrip("/")

def jpeg_bytes(frame):
    b = io.BytesIO(); frame.save(b, "JPEG", quality=88); return b.getvalue()

def upload_image(base, jpeg, filename):
    boundary = "----avian" + uuid.uuid4().hex
    body = (b"--" + boundary.encode() + b"\r\n"
            b'Content-Disposition: form-data; name="file"; filename="' + filename.encode() + b'"\r\n'
            b"Content-Type: image/jpeg\r\n\r\n" + jpeg + b"\r\n--" + boundary.encode() + b"--\r\n")
    req = urllib.request.Request(base + "/doUpload?dir=/image/", data=body, method="POST",
                                 headers={"Content-Type": "multipart/form-data; boundary=" + boundary})
    urllib.request.urlopen(req, timeout=20).read()

def show_image(base, filename):
    urllib.request.urlopen(base + "/set?img=/image/" + urllib.parse.quote(filename), timeout=10).read()

def set_theme(base, theme):
    urllib.request.urlopen(base + "/set?theme=" + str(theme), timeout=10).read()


# ---- discovery (so it finds the SmallTV on ANY network, e.g. dad's caravan) ----
DEFAULT_CACHE = Path.home() / ".avian_smalltv_ip"


def _is_smalltv(ip, timeout=0.5):
    """A GeekMagic answers /filelist?dir=/image with an HTML file table."""
    try:
        with urllib.request.urlopen(f"http://{ip}/filelist?dir=/image", timeout=timeout) as r:
            body = r.read(400).decode("latin1", "replace")
        return "id='list'" in body or ("list" in body and "/image" in body)
    except Exception:
        return False


def _local_subnet():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect(("8.8.8.8", 80)); ip = s.getsockname()[0]
    except Exception:
        ip = "127.0.0.1"
    finally:
        s.close()
    return ip.rsplit(".", 1)[0], ip


def _save_cache(cache, ip):
    try:
        Path(cache).write_text(ip)
    except Exception:
        pass


def discover_host(cache=DEFAULT_CACHE, timeout=0.5):
    """Find the SmallTV: cached IP -> mDNS names -> /24 scan for the fingerprint."""
    if cache and Path(cache).is_file():
        ip = Path(cache).read_text().strip()
        if ip and _is_smalltv(ip, timeout):
            return ip
    for name in ("smalltv.local", "geekmagic.local", "smalltv-ultra.local"):
        try:
            ip = socket.gethostbyname(name)
            if _is_smalltv(ip, timeout):
                _save_cache(cache, ip); return ip
        except Exception:
            pass
    net, myip = _local_subnet()
    hosts = [f"{net}.{i}" for i in range(1, 255) if f"{net}.{i}" != myip]
    with concurrent.futures.ThreadPoolExecutor(max_workers=64) as ex:
        for ip, ok in zip(hosts, ex.map(lambda h: _is_smalltv(h, timeout), hosts)):
            if ok:
                _save_cache(cache, ip); return ip
    return None


def _signature(sp):
    return tuple((s["sci"], s["n"]) for s in sp)


def compose_and_upload(base, sp):
    frame = compose_collage(sp)
    upload_image(base, jpeg_bytes(frame), "av_collage.jpg")
    show_image(base, "av_collage.jpg")
    set_theme(base, 3)


def push_collage(base, db, hours, limit):
    sp = recent_species(Path(db), hours, limit)
    if not sp:
        print("[smalltv] no birds in window - nothing to push"); return None
    compose_and_upload(base, sp)
    print(f"[smalltv] pushed collage of {len(sp)} birds (last {hours}h) -> {base}")
    return _signature(sp)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--db", default=str(ROOT / "scripts" / "birds.db"))
    ap.add_argument("--host", help="SmallTV IP/hostname (omit to auto-discover on the LAN)")
    ap.add_argument("--cache", default=str(DEFAULT_CACHE), help="file to remember the SmallTV IP across runs/networks")
    ap.add_argument("--out", help="save the composed 240x240 collage here (preview, no device)")
    ap.add_argument("--hours", type=int, default=24)
    ap.add_argument("--limit", type=int, default=24, help="max birds (device storage / legibility cap)")
    ap.add_argument("--loop", type=int, default=0, help="check every N seconds (re-discovers if the network changes)")
    ap.add_argument("--count-refresh", type=int, default=300, help="when only detection COUNTS changed (same species), re-push at most every N seconds - spares the device flash")
    ap.add_argument("--image", help="push THIS image file to the SmallTV (fit to 240x240) - API test")
    ap.add_argument("--discover", action="store_true", help="just find + print the SmallTV IP and exit")
    args = ap.parse_args()

    if args.discover:
        print(discover_host(args.cache) or ""); return

    if args.out:   # preview only - never touches the network
        compose_collage(recent_species(Path(args.db), args.hours, args.limit)).save(args.out, "JPEG", quality=90)
        print(f"[smalltv] preview saved -> {args.out}"); return

    if args.image:
        host = args.host or discover_host(args.cache)
        if not host:
            print("[smalltv] --image: no device (give --host or connect the SmallTV to this network)"); return
        base = _norm(host)
        src = Image.open(args.image).convert("RGB")
        frame = Image.new("RGB", (SIZE, SIZE), PAPER)
        s = min(SIZE / src.width, SIZE / src.height)
        src = src.resize((round(src.width * s), round(src.height * s)), Image.LANCZOS)
        frame.paste(src, ((SIZE - src.width) // 2, (SIZE - src.height) // 2))
        upload_image(base, jpeg_bytes(frame), "av_test.jpg"); show_image(base, "av_test.jpg"); set_theme(base, 3)
        print(f"[smalltv] pushed {args.image} -> {host}"); return

    if args.loop:
        host = args.host
        last_set = None; last_sig = None; last_push = 0.0
        while True:
            if not host:
                host = discover_host(args.cache)
                if not host:
                    print("[smalltv] no device found on this network; retrying"); time.sleep(min(args.loop, 60)); continue
                print(f"[smalltv] discovered SmallTV at {host}")
                last_set = None; last_sig = None   # force a push after (re)connecting
            try:
                # window is read fresh each cycle from birdnet.conf, so a change
                # in the menu (AV_SMALLTV_WINDOW) takes effect within one loop.
                sp = species_for_window(args.db, _conf_get("AV_SMALLTV_WINDOW", "24h"), args.limit)
                cur_set = frozenset(s["sci"] for s in sp)
                sig = _signature(sp)
                now = time.monotonic()
                set_changed = cur_set != last_set
                count_changed = sig != last_sig
                # Push immediately when a species appears/drops off (the event worth
                # seeing). For count-only changes - a bird that keeps singing ticks
                # its count every few seconds - re-push at most every count_refresh
                # seconds so frequent polling doesn't wear the device's flash.
                if sp and (set_changed or (count_changed and now - last_push >= args.count_refresh)):
                    compose_and_upload(_norm(host), sp)
                    print(f"[smalltv] pushed {len(sp)} birds ({'new species' if set_changed else 'count refresh'}) -> {host}")
                    last_set = cur_set; last_sig = sig; last_push = now
            except (urllib.error.URLError, OSError) as e:
                print(f"[smalltv] push failed ({e}); will re-discover"); host = None
            except Exception as e:
                print(f"[smalltv] push error: {e}")
            time.sleep(args.loop)
    else:
        host = args.host or discover_host(args.cache)
        if not host:
            print("[smalltv] no device found; pass --host or connect the SmallTV to this network"); return
        push_collage(_norm(host), args.db, args.hours, args.limit)


if __name__ == "__main__":
    main()
