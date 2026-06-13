#!/usr/bin/env python3
"""AvianVisitors - hybrid cutout: BiRefNet silhouette + hole-fill from source.

Step 2-alt (after pregen.py, before build_masks.py), the robust option for
the whole set. cutout.py (BiRefNet alone) eats white bellies as holes;
cutout_flatground.py (flood-fill) drains mostly-white birds through gaps in
the ink outline and keeps shadow specks. This combines the strengths:

  1. BiRefNet matte  -> clean anti-aliased outer edge, drops the cast shadow.
  2. drop small connected components -> removes stray specks.
  3. fill enclosed holes -> recovers white bellies BiRefNet punched out.
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


def process(src_path: Path, session, margin: float, min_cc_frac: float) -> Image.Image:
    from rembg import remove
    src = Image.open(src_path).convert("RGB")
    cut = remove(src, session=session)            # RGBA, BiRefNet matte
    soft = np.asarray(cut.getchannel("A"))
    mask = soft > 127

    # drop stray specks: keep components >= min_cc_frac of the largest
    lbl, n = ndimage.label(mask)
    if n > 1:
        sizes = ndimage.sum(np.ones_like(lbl), lbl, range(1, n + 1))
        keep = np.where(sizes >= sizes.max() * min_cc_frac)[0] + 1
        mask = np.isin(lbl, keep)

    filled = ndimage.binary_fill_holes(mask)       # recover enclosed white

    # Solid interior, anti-aliased outer edge. Keeping BiRefNet's soft alpha
    # inside the bird leaves faint "ghost line" artifacts at internal edges
    # (white belly meeting wing), so make the whole silhouette opaque and only
    # feather the boundary.
    from PIL import ImageFilter
    final = np.asarray(
        Image.fromarray((filled * 255).astype(np.uint8)).filter(
            ImageFilter.GaussianBlur(0.8)))

    out = np.dstack([np.asarray(src), final.astype(np.uint8)])
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
        im = process(p, session, args.margin, args.min_cc_frac)
        im.save(args.dir / p.name)
        print(f"  [cut]  {p.name}  -> {im.width}x{im.height}")
    print(f"\ncut {len(paths)} (hybrid hole-fill)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
