"""Selection rule of PREREGISTRATION.md, section 6.

``select`` keeps the configurations passing G1–G7, ranks them inside each (family, cadence)
by the bootstrap 90 % lower bound of the baseline mean net R (ties by the mean), keeps at most
three per group, and lists the second-pass configurations (the E filters on each survivor).
``merge_second_pass`` replaces a survivor by a filtered variant only when the variant passes the
gates and has a higher lower bound.

    python -m scripts.research_v2.select first --screen screen-training.json --out-dir DIR
    python -m scripts.research_v2.select second --survivors DIR/survivors.json \
        --screen DIR/screen-second-pass.json --out-dir DIR
"""

from __future__ import annotations

import argparse
import json
from collections import defaultdict
from pathlib import Path

from .filters import FILTER_IDS

MAX_PER_GROUP = 3


def _rank_key(row: dict) -> tuple[float, float]:
    return (row["baseline"]["lower_bound_r"], row["baseline"]["mean_r"])


def select(screen: dict, max_per_group: int = MAX_PER_GROUP) -> dict:
    groups: dict[tuple[str, str], list[dict]] = defaultdict(list)
    for row in screen["rows"]:
        if row.get("passes"):
            groups[(row["family"], row["cadence"])].append(row)
    survivors = []
    for key in sorted(groups):
        ranked = sorted(groups[key], key=_rank_key, reverse=True)
        for rank, row in enumerate(ranked[:max_per_group], 1):
            survivors.append(_entry(row, rank, len(ranked)))
    return {
        "schema": "research-v2-survivors.v1",
        "source_code_sha256": screen["code"]["package_sha256"],
        "passing": sum(len(rows) for rows in groups.values()),
        "survivors": survivors,
        "second_pass_configurations": [f"{row['id']}|{filter_id}" for row in survivors for filter_id in FILTER_IDS],
    }


def _entry(row: dict, rank: int, group_size: int) -> dict:
    return {
        "id": row["id"],
        "family": row["family"],
        "cadence": row["cadence"],
        "rank_in_group": rank,
        "group_size": group_size,
        "lower_bound_r": row["baseline"]["lower_bound_r"],
        "mean_r": row["baseline"]["mean_r"],
        "trades": row["baseline"]["trades"],
    }


def merge_second_pass(survivors: dict, second_pass: dict) -> dict:
    """Final validation list: each survivor or its best passing filtered variant."""
    by_base: dict[str, list[dict]] = defaultdict(list)
    for row in second_pass["rows"]:
        base_id = "|".join(row["id"].split("|")[:4])
        by_base[base_id].append(row)
    final = []
    decisions = []
    for survivor in survivors["survivors"]:
        candidates = [row for row in by_base.get(survivor["id"], []) if row.get("passes") and row["baseline"]["lower_bound_r"] > survivor["lower_bound_r"]]
        if candidates:
            best = max(candidates, key=_rank_key)
            final.append({**_entry(best, survivor["rank_in_group"], survivor["group_size"]), "replaces": survivor["id"]})
            decisions.append({"survivor": survivor["id"], "chosen": best["id"], "reason": "filtered variant passes the gates with a higher lower bound"})
        else:
            final.append(survivor)
            decisions.append({"survivor": survivor["id"], "chosen": survivor["id"], "reason": "no passing filtered variant with a higher lower bound"})
    return {
        "schema": "research-v2-validation-list.v1",
        "second_pass_code_sha256": second_pass["code"]["package_sha256"],
        "final": final,
        "decisions": decisions,
        "validation_configurations": [entry["id"] for entry in final],
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_v2.select")
    sub = parser.add_subparsers(dest="command", required=True)
    first = sub.add_parser("first")
    first.add_argument("--screen", required=True)
    first.add_argument("--out-dir", required=True)
    second = sub.add_parser("second")
    second.add_argument("--survivors", required=True)
    second.add_argument("--screen", required=True)
    second.add_argument("--out-dir", required=True)
    args = parser.parse_args(argv)
    out = Path(args.out_dir)
    if args.command == "first":
        document = select(json.loads(Path(args.screen).read_text()))
        (out / "survivors.json").write_text(json.dumps(document, indent=2, sort_keys=True) + "\n")
        (out / "second-pass-configs.txt").write_text("\n".join(document["second_pass_configurations"]) + "\n")
        print(json.dumps({"passing": document["passing"], "survivors": len(document["survivors"]), "second_pass": len(document["second_pass_configurations"])}))
    else:
        document = merge_second_pass(json.loads(Path(args.survivors).read_text()), json.loads(Path(args.screen).read_text()))
        (out / "validation-list.json").write_text(json.dumps(document, indent=2, sort_keys=True) + "\n")
        (out / "validation-configs.txt").write_text("\n".join(document["validation_configurations"]) + "\n")
        print(json.dumps({"final": document["validation_configurations"]}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
