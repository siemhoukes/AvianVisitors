#!/usr/bin/env python3
"""AvianVisitors - hybrid cutout: BiRefNet silhouette + hole-fill from source.

Step 2-alt (after pregen.py, before build_masks.py), the robust option for
the whole set. cutout.py (BiRefNet alone) eats white bellies as holes;
cutout_flatground.py (flood-fill) drains mostly-white birds through gaps in
the ink outline and keeps shadow specks. This combines the strengths:

  1. BiRefNet matte  -> clean anti-aliased outer edge, drops the cast shadow.
  2. drop small connected components -> removes stray specks.
  3. fill ALL enclosed holes (recovers white/cream bellies BiRefNet punched
     out), then drain the cream that is really background, two ways - because
     colour alone can't help (belly and leg-gap are both the ground colour):
       (a) WIDE channels (between a pale wader's splayed legs, behind a curved
           neck) via a width-gated colour flood: erode the cream map by --seal
           so thin ink-outline gaps seal shut and the flood can't drain a pale
           body, then flood from the border. Handles all-pale birds.
       (b) NARROW leg-gaps on colored birds: a small enclosed hole bridged at
           the bottom by thin feet re-opens to the exterior when the silhouette
           is eroded by --erode (a real belly stays enclosed). A --max-gap-frac
           size guard protects a pale bird's large recovered body-hole.
  4. composite RGB from the cream-ground SOURCE so the recovered interior
     shows the real white, not the black rembg left behind.

Reads cream-ground sources from --src (kept in assets/_cream_source/).

Usage:
    python3 cutout_holefill.py --src ../assets/_cream_source            # all
    python3 cutout_holefill.py --src ../assets/_cream_source egretta-garzetta
    python3 cutout_holefill.py --src ../assets/_cream_source --dir /tmp/test pica-pica
"""
from __future__ import annotations
import argparse
import sys
from pathlib import Path

import numpy as np
from PIL import Image
from scipy import ndimage


def _ground_color(arr: np.ndarray, c: int = 30) -> np.ndarray:
    """Median colour of the four corner patches - the flat cream ground."""
    corners = np.concatenate([
        arr[:c, :c].reshape(-1, 3), arr[:c, -c:].reshape(-1, 3),
        arr[-c:, :c].reshape(-1, 3), arr[-c:, -c:].reshape(-1, 3)])
    return np.median(corners, axis=0)


def _width_gated_bg(arr: np.ndarray, ground: np.ndarray, tol: float,
                    seal: int) -> np.ndarray:
    """Background = cream-ground pixels reaching the border through a channel
    wider than ``seal`` px. Erode the ground map to seal thin ink-outline leaks
    into the belly, flood from the border, then dilate back onto the ground."""
    is_ground = np.linalg.norm(arr.astype(np.float32) - ground, axis=2) < tol
    core = ndimage.binary_erosion(is_ground, iterations=seal)
    lbl, _ = ndimage.label(core)
    border = (set(lbl[0, :]) | set(lbl[-1, :]) | set(lbl[:, 0]) | set(lbl[:, -1]))
    border.discard(0)
    bg_core = np.isin(lbl, list(border))
    return ndimage.binary_dilation(bg_core, iterations=seal) & is_ground


def process(src_path: Path, session, margin: float, min_cc_frac: float,
            ground_tol: float, seal: int, erode: int,
            max_gap_frac: float) -> Image.Image:
    from rembg import remove
    src = Image.open(src_path).convert("RGB")
    src_arr = np.asarray(src)
    cut = remove(src, session=session)            # RGBA, BiRefNet matte
    soft = np.asarray(cut.getchannel("A"))
    matte = soft > 127

    # drop stray specks: keep components >= min_cc_frac of the largest
    lbl, n = ndimage.label(matte)
    if n > 1:
        sizes = ndimage.sum(np.ones_like(lbl), lbl, range(1, n + 1))
        keep = np.where(sizes >= sizes.max() * min_cc_frac)[0] + 1
        matte = np.isin(lbl, keep)

    # Fill every enclosed hole (recovers the white/cream belly BiRefNet punched
    # out), then drain the two kinds of cream that are really background:
    filled = ndimage.binary_fill_holes(matte)
    mask = filled.copy()

    # (a) WIDE channels - the gap between the splayed legs of a pale wader, or
    #     behind a curved neck. A sealed colour flood drains these and never
    #     leaks into a pale body (the thin ink-outline gaps are eroded shut).
    mask &= ~_width_gated_bg(src_arr, _ground_color(src_arr), ground_tol, seal)

    # (b) NARROW leg-gaps on colored birds - a small enclosed hole whose bottom
    #     is bridged only by thin feet/toes, so eroding the silhouette re-opens
    #     it to the exterior (a real belly stays enclosed). The size guard keeps
    #     a pale bird's large recovered body-hole: its thin outline also erodes
    #     away, but it is far too big to be a leg-gap, and (a) drains its gap.
    holes = filled & ~matte
    if holes.any():
        opened = ndimage.binary_fill_holes(
            ndimage.binary_erosion(matte, iterations=erode))
        hl, hn = ndimage.label(holes)
        area = filled.sum()
        for i in range(1, hn + 1):
            comp = hl == i
            s = comp.sum()
            if s < 50 or s > max_gap_frac * area:
                continue
            if (comp & opened).sum() / s < 0.5:      # re-opened -> leg-gap
                mask &= ~comp

    # Solid interior, anti-aliased outer edge. Keeping BiRefNet's soft alpha
    # inside the bird leaves faint "ghost line" artifacts at internal edges
    # (white belly meeting wing), so make the whole silhouette opaque and only
    # feather the boundary.
    from PIL import ImageFilter
    final = np.asarray(
        Image.fromarray((mask * 255).astype(np.uint8)).filter(
            ImageFilter.GaussianBlur(0.8)))

    out = np.dstack([src_arr, final.astype(np.uint8)])
    im = Image.fromarray(out, "RGBA")

    bbox = im.getchannel("A").point(lambda v: 255 if v > 16 else 0).getbbox()
    if bbox:
        pad = round(margin * max(bbox[2] - bbox[0], bbox[3] - bbox[1]))
        im = im.crop((max(0, bbox[0] - pad), max(0, bbox[1] - pad),
                      min(im.width, bbox[2] + pad), min(im.height, bbox[3] + pad)))
    return im


def main() -> int:
    here = Path(__file__).resolve().parents[1]
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("slugs", nargs="*", help="Slugs to cut (default: all in --src)")
    ap.add_argument("--src", type=Path, required=True, help="Cream-ground source dir")
    ap.add_argument("--dir", type=Path, default=here / "assets" / "illustrations",
                    help="Output dir (default: avian/assets/illustrations/)")
    ap.add_argument("--model", default="birefnet-general")
    ap.add_argument("--margin", type=float, default=0.02)
    ap.add_argument("--min-cc-frac", type=float, default=0.02,
                    help="Drop components smaller than this fraction of the largest")
    ap.add_argument("--ground-tol", type=float, default=34.0,
                    help="Per-pixel RGB distance under which a source pixel counts "
                         "as cream ground (for the width-gated background flood).")
    ap.add_argument("--seal", type=int, default=12,
                    help="(a) Erosion radius (px) sealing thin ink-outline leaks "
                         "for the wide-channel flood. Bigger = safer on pale "
                         "bodies but only drains wider gaps.")
    ap.add_argument("--erode", type=int, default=10,
                    help="(b) Silhouette erosion (px) for re-opening narrow "
                         "leg-gaps bridged by thin feet. Bigger drains wider "
                         "bridges but risks thin real parts.")
    ap.add_argument("--max-gap-frac", type=float, default=0.08,
                    help="(b) Only erosion-drop holes smaller than this fraction "
                         "of the bird, so a pale bird's big body-hole is kept.")
    args = ap.parse_args()

    if args.slugs:
        paths = [args.src / f"{s}.png" for s in args.slugs]
    else:
        paths = sorted(args.src.glob("*.png"))
    missing = [p for p in paths if not p.exists()]
    if missing:
        print("error: not found: " + ", ".join(p.name for p in missing), file=sys.stderr)
        return 2

    from rembg import new_session
    session = new_session(args.model)
    args.dir.mkdir(parents=True, exist_ok=True)
    for p in paths:
        im = process(p, session, args.margin, args.min_cc_frac, args.ground_tol,
                     args.seal, args.erode, args.max_gap_frac)
        im.save(args.dir / p.name)
        print(f"  [cut]  {p.name}  -> {im.width}x{im.height}")
    print(f"\ncut {len(paths)} (hybrid hole-fill)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
