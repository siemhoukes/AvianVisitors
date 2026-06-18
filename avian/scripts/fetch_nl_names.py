#!/usr/bin/env python3
"""AvianVisitors - Dutch common names from the eBird taxonomy.

Bird names in the UI are shown in Dutch, sourced from the **eBird/Clements
taxonomy** (locale=nl) so they are the authoritative eBird names, never a
machine translation. This fetches the eBird taxonomy once and inlines a
`var NAMES_NL = {...};` table into avian/frontend/apt.js (same mechanism as
build_masks.py rewrites DIMS/MASKS), keyed by the BirdNET scientific name so
the frontend can look it up by the DB's Sci_Name.

How the keys line up:
  - The frontend shows `s.sci` = birds.db Sci_Name = a BirdNET label key.
  - Our illustration slugs were made with slugify(BirdNET sci).
  - labels_en.json is keyed by the BirdNET sci, so slugify(its keys) maps a
    slug back to the exact sci the frontend uses at runtime.
  - The eBird taxonomy is keyed by eBird sciName; slugify(it) bridges to the
    same slug. So: slug -> BirdNET sci (key) + eBird Dutch comName (value).

Only species we actually ship an illustration for are emitted, to keep the
inlined table small. Species whose BirdNET sci doesn't resolve to an eBird
taxon (rare taxonomy splits/lumps) are reported and left out - the frontend
falls back to the English DB name for those.

Needs EBIRD_API_KEY in the repo-root .env (same key the FR/ES/PT library
curation used). Run after changing the illustration set.

Usage:
    python3 fetch_nl_names.py            # rewrite apt.js in place
    python3 fetch_nl_names.py --check    # report coverage, don't write
"""
from __future__ import annotations
import argparse
import json
import re
import sys
import urllib.request
from pathlib import Path

EBIRD_TAXONOMY = "https://api.ebird.org/v2/ref/taxonomy/ebird?fmt=json&cat=species&locale="

# BirdNET's label set predates a few eBird splits and keeps the old name for
# both the sciName and the English name, so neither the slug nor the
# English-name join finds the taxon. Map the BirdNET sci to the current eBird
# English name; the Dutch name is still pulled from eBird via that name.
SPLIT_RENAMES = {
    "Accipiter gentilis": "Eurasian Goshawk",     # split 2023: -> Astur gentilis
    "Bubulcus ibis": "Western Cattle-Egret",      # split 2023: -> Ardea ibis
}


def slugify(sci: str) -> str:
    """Mirror the frontend's slugify() in apt.js exactly."""
    return re.sub(r"[^a-z0-9]+", "-", sci.lower()).strip("-")


def title_case_nl(name: str) -> str:
    """eBird returns Dutch names in sentence case ('Grote zilverreiger'); the
    Dutch (CSNA) convention capitalises every word ('Grote Zilverreiger').
    This only changes capitalisation - the words stay exactly eBird's - so it
    is presentation, not a translation. Capitalise each word's first letter,
    leaving the rest as-is so internal caps/apostrophes survive ('Anna's')."""
    return " ".join(w[:1].upper() + w[1:] for w in name.split(" "))


def load_env_key(env_path: Path, name: str) -> str:
    if not env_path.exists():
        raise SystemExit(f"error: {env_path} not found")
    for line in env_path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        if k.strip() == name:
            return v.strip().strip('"').strip("'")
    raise SystemExit(f"error: {name} not set in {env_path}")


def fetch_taxonomy(token: str, locale: str) -> list:
    """Full eBird species taxonomy in the given locale (comName localised)."""
    req = urllib.request.Request(EBIRD_TAXONOMY + locale,
                                 headers={"X-eBirdApiToken": token,
                                          "User-Agent": "AvianVisitors/1.0"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.load(r)


def replace_decl(src: str, name: str, value: str) -> str:
    """Replace `  var <name> = {...};` (single line) with the new value."""
    pat = re.compile(r"  var " + name + r" = \{.*?\};")
    repl = f"  var {name} = {value};"
    new, n = pat.subn(lambda _m: repl, src, count=1)
    if n != 1:
        raise SystemExit(
            f"error: could not find `var {name} = {{...}};` in apt.js "
            f"(add a placeholder `  var {name} = {{}};` line first)")
    return new


def main() -> int:
    here = Path(__file__).resolve().parents[1]          # .../avian
    root = here.parent                                  # repo root
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--illustrations", type=Path, default=here / "assets" / "illustrations")
    ap.add_argument("--labels-en", type=Path, default=root / "model" / "l18n" / "labels_en.json")
    ap.add_argument("--labels-nl", type=Path, default=root / "model" / "l18n" / "labels_nl.json")
    ap.add_argument("--apt", type=Path, default=here / "frontend" / "apt.js")
    ap.add_argument("--env", type=Path, default=root / ".env")
    ap.add_argument("--check", action="store_true", help="Report coverage, don't write")
    ap.add_argument("--sentence-case", action="store_true",
                    help="Keep eBird's sentence case instead of Dutch Title Case")
    args = ap.parse_args()

    # slug -> BirdNET sci (the runtime key the frontend looks up by).
    en = json.loads(args.labels_en.read_text(encoding="utf-8"))
    sci_by_slug = {slugify(sci): sci for sci in en}

    # Unique library species (strip the -2 flight-pose suffix).
    slugs = sorted({re.sub(r"-2$", "", p.stem)
                    for p in args.illustrations.glob("*.png")
                    if re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", p.stem)})

    token = load_env_key(args.env, "EBIRD_API_KEY")
    print("fetching eBird taxonomy (nl + en) ...")
    taxa_nl = fetch_taxonomy(token, "nl")
    taxa_en = fetch_taxonomy(token, "en")
    print(f"  {len(taxa_nl)} eBird species")

    # Primary join: slug(eBird sciName) -> Dutch name.
    nl_by_slug = {slugify(t["sciName"]): t["comName"]
                  for t in taxa_nl if t.get("sciName") and t.get("comName")}
    # Fallback join for genus reassignments (BirdNET keeps the old sciName,
    # but the English name is stable): speciesCode -> Dutch name, and
    # lower(English name) -> speciesCode.
    nl_by_code = {t["speciesCode"]: t["comName"]
                  for t in taxa_nl if t.get("speciesCode") and t.get("comName")}
    code_by_en = {t["comName"].lower(): t["speciesCode"]
                  for t in taxa_en if t.get("speciesCode") and t.get("comName")}

    names, no_ebird, no_sci = {}, [], []
    for slug in slugs:
        sci = sci_by_slug.get(slug)
        if not sci:
            no_sci.append(slug)
            continue
        nl = nl_by_slug.get(slug)
        if not nl:  # fall back to matching on the stable/current English name
            en_name = SPLIT_RENAMES.get(sci) or en.get(sci) or ""
            code = code_by_en.get(en_name.lower())
            nl = nl_by_code.get(code) if code else None
        if not nl:
            no_ebird.append(slug)
            continue
        names[sci] = nl if args.sentence_case else title_case_nl(nl)

    # Informational: how the bundled BirdNET labels_nl.json compares to eBird.
    bundled = json.loads(args.labels_nl.read_text(encoding="utf-8"))
    diff = sum(1 for sci, nl in names.items()
               if sci in bundled and bundled[sci] != nl)

    print(f"\n{len(names)} / {len(slugs)} library species got an eBird Dutch name")
    print(f"  bundled labels_nl.json differs from eBird on {diff} of them")
    if no_ebird:
        print(f"  no eBird match ({len(no_ebird)}): " + ", ".join(no_ebird[:12])
              + (" ..." if len(no_ebird) > 12 else ""))
    if no_sci:
        print(f"  no labels_en sci ({len(no_sci)}): " + ", ".join(no_sci[:12])
              + (" ..." if len(no_sci) > 12 else ""))

    if args.check:
        return 0

    names_json = json.dumps(names, separators=(",", ":"), ensure_ascii=False)
    src = args.apt.read_text(encoding="utf-8")
    src = replace_decl(src, "NAMES_NL", names_json)
    args.apt.write_text(src, encoding="utf-8")
    print(f"\npatched {args.apt} (NAMES_NL, {len(names)} entries)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
