"""Markdown rendering of a v2 screen document."""

from __future__ import annotations

from collections import defaultdict


def _fmt(value, digits: int = 2, suffix: str = "") -> str:
    if value is None:
        return "—"
    if isinstance(value, float):
        return f"{value:,.{digits}f}{suffix}"
    return f"{value}{suffix}"


def _pct(value) -> str:
    return "—" if value is None else f"{100 * value:.1f} %"


def render_report(document: dict) -> str:
    rows = document["rows"]
    phase = document["phase"]
    lines = [f"# Screen v2 — {phase}", ""]
    lines.append(f"Generated {document['generated_at']}; code `{document['code']['package_sha256'][:12]}`; {document['configurations']} configurations; {document['seeds']} control seeds; {document['elapsed_seconds']} s.")
    lines.append("")
    by_family: dict[str, list[dict]] = defaultdict(list)
    for row in rows:
        by_family[row["family"]].append(row)
    lines.append("## Overview")
    lines.append("")
    lines.append("| Family | Configurations | With trades | Median mean R (baseline) | Share mean R > 0 | Passing gates |")
    lines.append("|---|---:|---:|---:|---:|---:|")
    for family in sorted(by_family):
        family_rows = by_family[family]
        traded = [row for row in family_rows if row["baseline"].get("trades", 0) > 0]
        means = sorted(row["baseline"]["mean_r"] for row in traded)
        median = means[len(means) // 2] if means else None
        positive = sum(1 for mean in means if mean > 0)
        passing = sum(1 for row in family_rows if row.get("passes"))
        lines.append(f"| {family} | {len(family_rows)} | {len(traded)} | {_fmt(median, 3)} | {_pct(positive / len(means)) if means else '—'} | {passing} |")
    lines.append("")
    passing_rows = [row for row in rows if row.get("passes")]
    lines.append(f"## Configurations passing every training gate: {len(passing_rows)}")
    lines.append("")
    if passing_rows:
        lines.extend(_table(passing_rows))
    lines.append("")
    lines.append("## Best twenty by baseline mean net R (any gate status)")
    lines.append("")
    top = sorted((row for row in rows if row["baseline"].get("trades", 0) >= 50), key=lambda row: row["baseline"]["mean_r"], reverse=True)[:20]
    lines.extend(_table(top))
    lines.append("")
    lines.append("## Gate failures")
    lines.append("")
    counter: dict[str, int] = defaultdict(int)
    for row in rows:
        for reason in row.get("gate_reasons", []):
            counter[reason.split(":")[0]] += 1
    for gate in sorted(counter):
        lines.append(f"- {gate}: {counter[gate]} configurations")
    lines.append("")
    return "\n".join(lines) + "\n"


def _table(rows: list[dict]) -> list[str]:
    out = ["| Configuration | Trades | Win | Net base | Mean R base | LB90 | PF base | PF adv | Mean R adv | Control median / p95 | Pairs + | Months + | Gates |", "|---|---:|---:|---:|---:|---:|---:|---:|---:|---|---:|---:|---|"]
    for row in rows:
        base, adverse, control = row["baseline"], row["adverse"], row.get("control") or {}
        if base.get("trades", 0) == 0:
            continue
        gates = ", ".join(reason.split(":")[0] for reason in row.get("gate_reasons", [])) or "pass"
        out.append(
            f"| `{row['id']}` | {base['trades']} | {_pct(base['win_rate'])} | {_fmt(base['net'])} | {_fmt(base['mean_r'], 3)} | {_fmt(base['lower_bound_r'], 3)} | {_fmt(base['profit_factor'])} | {_fmt(adverse.get('profit_factor'))} | {_fmt(adverse.get('mean_r'), 3)} | {_fmt(control.get('median'), 3)} / {_fmt(control.get('p95'), 3)} | {base['pairs_positive']}/{base['pairs_with_trades']} | {base['positive_months']}/{len(base['by_month'])} | {gates} |"
        )
    return out
