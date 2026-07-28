#!/usr/bin/env python3
"""AvianVisitors - taxonomy table for the Vogelkans panel.

Vogelkans lists the species the BirdNET range model thinks are likely at a
place and week, grouped into broad field-guide buckets (Zangvogels, Roofvogels,
Steltlopers...). The range model only gives scores per BirdNET label; it knows
nothing about families. This fetches the eBird taxonomy once and writes
avian/data/taxonomy.json, which vogelkans.php joins against at request time.

Dutch names are NOT stored here - model/l18n/labels_nl.json already ships every
species and lives on the Pi. This file only adds what BirdNET lacks: family,
order, group, and the eBird species code (for outbound links).

Joining BirdNET labels to eBird taxa, in order:
  1. Exact scientific name. Covers ~6162 of 6522.
  2. scripts/ebird.php's sci -> eBird species code map, which predates the
     recent genus splits and so still bridges them (Accipiter gentilis ->
     `norgos1` -> Astur gentilis). Recovers ~233 more.
Anything left is either a non-bird BirdNET also classifies (frogs, insects,
a few mammals - ebird.php marks these "null") or a genuine taxonomy gap. Both
are reported and omitted; vogelkans.php falls back to group "Overig".

The eBird taxonomy endpoint used here needs NO API key.

Usage:
    python3 fetch_taxonomy.py            # write avian/data/taxonomy.json
    python3 fetch_taxonomy.py --check    # report coverage, don't write
"""
from __future__ import annotations
import argparse
import csv
import io
import json
import re
import sys
import urllib.request
from pathlib import Path

EBIRD_TAXONOMY = "https://api.ebird.org/v2/ref/taxonomy/ebird?fmt=csv&cat=species"
FALLBACK_GROUP = "Overig"


def fetch_taxonomy_csv(url: str = EBIRD_TAXONOMY) -> list[dict]:
    """eBird's full species taxonomy as CSV rows. No API token required."""
    req = urllib.request.Request(url, headers={"User-Agent": "AvianVisitors/1.0"})
    with urllib.request.urlopen(req, timeout=120) as r:
        text = r.read().decode("utf-8-sig")
    rows = list(csv.DictReader(io.StringIO(text)))
    missing = {"SCIENTIFIC_NAME", "SPECIES_CODE", "ORDER", "FAMILY_SCI_NAME"} - set(rows[0] or {})
    if missing:
        raise SystemExit(f"error: eBird CSV is missing columns {sorted(missing)} "
                         "- the schema changed, update this script")
    return rows


def read_ebird_codes(php_path: Path) -> dict[str, str]:
    """scripts/ebird.php is a PHP array of BirdNET sci => eBird species code."""
    src = php_path.read_text(encoding="utf-8", errors="replace")
    return dict(re.findall(r'"([^"]+)"\s*=>\s*"([^"]+)"', src))


def main() -> int:
    here = Path(__file__).resolve().parents[1]          # .../avian
    root = here.parent                                  # repo root
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--labels", type=Path,
                    default=root / "model" / "BirdNET_GLOBAL_6K_V2.4_Model_FP16_Labels.txt",
                    help="BirdNET label list (scientific names, one per line)")
    ap.add_argument("--ebird-php", type=Path, default=root / "scripts" / "ebird.php")
    ap.add_argument("--groups", type=Path, default=here / "data" / "bird-groups.json")
    ap.add_argument("--out", type=Path, default=here / "data" / "taxonomy.json")
    ap.add_argument("--check", action="store_true", help="Report coverage, don't write")
    args = ap.parse_args()

    labels = [l.strip() for l in args.labels.read_text(encoding="utf-8").splitlines() if l.strip()]
    codes = read_ebird_codes(args.ebird_php)
    gmap = json.loads(args.groups.read_text(encoding="utf-8"))
    groups: list[str] = gmap["groups"]
    by_family: dict[str, str] = gmap["by_family"]
    by_order: dict[str, str] = gmap["by_order"]
    if FALLBACK_GROUP not in groups:
        raise SystemExit(f"error: {args.groups} must list '{FALLBACK_GROUP}' in groups")

    print(f"fetching eBird taxonomy (no API key needed) ...")
    taxa = fetch_taxonomy_csv()
    by_sci = {t["SCIENTIFIC_NAME"]: t for t in taxa}
    by_code = {t["SPECIES_CODE"]: t for t in taxa}
    print(f"  {len(taxa)} eBird species")

    def lookup(sci: str):
        if sci in by_sci:
            return by_sci[sci], "sci"
        code = codes.get(sci)
        if code and code != "null" and code in by_code:
            return by_code[code], "code"
        return None, "null" if codes.get(sci) == "null" else "gap"

    # Intern families/orders/groups so the file stays small: each species is
    # [groupIdx, familyIdx, orderIdx, ebirdCode].
    families: list[str] = []
    orders: list[str] = []
    fidx: dict[str, int] = {}
    oidx: dict[str, int] = {}
    gidx = {g: i for i, g in enumerate(groups)}

    def intern(name, store, index):
        if name not in index:
            index[name] = len(store)
            store.append(name)
        return index[name]

    sp: dict[str, list] = {}
    n_sci = n_code = 0
    nonbird: list[str] = []
    gaps: list[str] = []
    ungrouped: set[str] = set()

    for sci in labels:
        t, how = lookup(sci)
        if t is None:
            (nonbird if how == "null" else gaps).append(sci)
            continue
        n_sci += how == "sci"
        n_code += how == "code"
        fam = t["FAMILY_SCI_NAME"] or ""
        order = t["ORDER"] or ""
        group = by_family.get(fam) or by_order.get(order) or FALLBACK_GROUP
        if group not in gidx:
            raise SystemExit(f"error: {args.groups} maps {fam or order} to '{group}', "
                             f"which is not in its groups list")
        if fam and fam not in by_family and order not in by_order:
            ungrouped.add(f"{order}/{fam}")
        sp[sci] = [gidx[group], intern(fam, families, fidx),
                   intern(order, orders, oidx), t["SPECIES_CODE"]]

    print(f"\n{len(sp)} / {len(labels)} BirdNET labels resolved")
    print(f"  matched on scientific name : {n_sci}")
    print(f"  recovered via ebird.php    : {n_code}")
    print(f"  non-birds (frogs/insects)  : {len(nonbird)}")
    print(f"  taxonomy gaps              : {len(gaps)}"
          + (": " + ", ".join(gaps[:8]) + (" ..." if len(gaps) > 8 else "") if gaps else ""))
    print(f"  families {len(families)}, orders {len(orders)}, groups {len(groups)}")
    if ungrouped:
        print(f"  fell through to '{FALLBACK_GROUP}' ({len(ungrouped)} families, "
              f"add them to bird-groups.json if they matter): "
              + ", ".join(sorted(ungrouped)[:8]) + (" ..." if len(ungrouped) > 8 else ""))

    if args.check:
        return 0

    out = {
        "_README": "Generated by avian/scripts/fetch_taxonomy.py - do not hand-edit. "
                   "Edit avian/data/bird-groups.json and re-run instead.",
        "groups": groups,
        "families": families,
        "orders": orders,
        "sp": sp,
    }
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps(out, separators=(",", ":"), ensure_ascii=False),
                        encoding="utf-8")
    print(f"\nwrote {args.out} ({args.out.stat().st_size // 1024} KB, {len(sp)} species)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
