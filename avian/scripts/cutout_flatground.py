#!/usr/bin/env python3
"""AvianVisitors - flat-ground cutout that never eats the bird's white parts.

Alternative to cutout.py's BiRefNet matting. BiRefNet treats a bird's
pale/white regions as background when they sit on the cream ground, eating
holes in white-bellied species (magpie, shelduck, egret, gull...).

Because pregen.py renders every bird on a FLAT, KNOWN cream ground with a
cream margin around the bird, the ground is exactly the set of cream-coloured
pixels CONNECTED TO THE BORDER. A flood-fill from the border removes only
that, and stops at the bird's dark kacho-e ink outline -- so an enclosed
white belly (bounded by outline, not reachable from the border) is kept.

Pipeline step 2-alt (after pregen.py, before build_masks.py). Reads
cream-ground sources from --src, writes RGBA cutouts to --dir (default: the
illustrations dir, in place). Keep cream sources around (they are the only
way to re-cut without re-generating).

Usage:
    python3 cutout_flatground.py --src _cream_source pica-pica
    python3 cutout_flatground.py --src _cream_source        # all in --src
"""
from __future__ import annotations
import argparse
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter
from scipy import ndimage


def cut(im: Image.Image, tol: float, margin: float) -> Image.Image:
    rgb = np.asarray(im.convert("RGB")).astype(np.int16)
    h, w, _ = rgb.shape

    # cream colour = median of an 8px border frame
    frame = np.concatenate([
        rgb[:8, :, :].reshape(-1, 3), rgb[-8:, :, :].reshape(-1, 3),
        rgb[:, :8, :].reshape(-1, 3), rgb[:, -8:, :].reshape(-1, 3)])
    cream = np.median(frame, axis=0)
    dist = np.sqrt(((rgb - cream) ** 2).sum(axis=2))
    near = dist < tol

    # background = near-cream AND connected (4-conn) to the image border
    lbl, _ = ndimage.label(near)
    border = set(lbl[0, :]) | set(lbl[-1, :]) | set(lbl[:, 0]) | set(lbl[:, -1])
    border.discard(0)
    bg = np.isin(lbl, list(border))

    # dilate bg by 1px to swallow the cream anti-alias fringe just inside
    # the outline, then build a soft alpha so the edge composites cleanly.
    bg = ndimage.binary_dilation(bg, iterations=1)
    alpha = np.where(bg, 0.0, 255.0).astype(np.float32)
    alpha = np.asarray(Image.fromarray(alpha.astype(np.uint8)).filter(
        ImageFilter.GaussianBlur(0.8))).astype(np.uint8)

    out = np.dstack([np.asarray(im.convert("RGB")), alpha])
    cut_im = Image.fromarray(out, "RGBA")

    bbox = cut_im.getchannel("A").point(lambda v: 255 if v > 16 else 0).getbbox()
    if bbox:
        pad = round(margin * max(bbox[2] - bbox[0], bbox[3] - bbox[1]))
        x0, y0 = max(0, bbox[0] - pad), max(0, bbox[1] - pad)
        x1, y1 = min(cut_im.width, bbox[2] + pad), min(cut_im.height, bbox[3] + pad)
        cut_im = cut_im.crop((x0, y0, x1, y1))
    return cut_im


def main() -> int:
    here = Path(__file__).resolve().parents[1]
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("slugs", nargs="*", help="Slugs to cut (default: all in --src)")
    ap.add_argument("--src", type=Path, required=True,
                    help="Directory of cream-ground source PNGs")
    ap.add_argument("--dir", type=Path, default=here / "assets" / "illustrations",
                    help="Output directory (default: avian/assets/illustrations/)")
    ap.add_argument("--tol", type=float, default=42.0,
                    help="Cream colour distance tolerance (default 42)")
    ap.add_argument("--margin", type=float, default=0.02)
    args = ap.parse_args()

    if args.slugs:
        paths = [args.src / f"{s}.png" for s in args.slugs]
    else:
        paths = sorted(args.src.glob("*.png"))
    missing = [p for p in paths if not p.exists()]
    if missing:
        print("error: not found: " + ", ".join(p.name for p in missing), file=sys.stderr)
        return 2

    done = 0
    for p in paths:
        cut_im = cut(Image.open(p), args.tol, args.margin)
        cut_im.save(args.dir / p.name)
        print(f"  [cut]  {p.name}  -> {cut_im.width}x{cut_im.height}")
        done += 1
    print(f"\ncut {done} (flat-ground flood-fill)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
