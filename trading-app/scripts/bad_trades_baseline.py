#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import json
import math
import random
import statistics
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from typing import Any, Iterable


PROFILES = ("regular", "scalper", "scalper_micro")
TRADES_PER_SIMULATION = 100
CAPITAL_USDT = 100.0


def parse_bool(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "t", "yes", "y"}


def parse_float(value: Any) -> float | None:
    if value is None:
        return None
    normalized = str(value).strip()
    if normalized == "":
        return None
    return float(normalized)


def parse_int(value: Any) -> int:
    parsed = parse_float(value)
    return 0 if parsed is None else int(parsed)


def parse_datetime(value: Any) -> datetime:
    normalized = str(value or "").strip()
    if normalized.endswith("Z"):
        normalized = normalized[:-1] + "+00:00"
    return datetime.fromisoformat(normalized)


def parse_flags(value: Any) -> list[str]:
    normalized = str(value or "").strip()
    if normalized == "":
        return []
    try:
        payload = json.loads(normalized)
    except json.JSONDecodeError:
        return [normalized]
    if isinstance(payload, list):
        return [str(item) for item in payload]
    return [str(payload)]


def round_or_none(value: float | None, digits: int = 6) -> float | None:
    if value is None or math.isnan(value):
        return None
    return round(value, digits)


def median(values: Iterable[float]) -> float | None:
    collected = list(values)
    if not collected:
        return None
    return float(statistics.median(collected))


def mean(values: Iterable[float]) -> float | None:
    collected = list(values)
    if not collected:
        return None
    return float(sum(collected) / len(collected))


def percentile(values: list[float], pct: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    if len(ordered) == 1:
        return ordered[0]
    rank = (len(ordered) - 1) * pct
    low = math.floor(rank)
    high = math.ceil(rank)
    if low == high:
        return ordered[int(rank)]
    weight = rank - low
    return ordered[low] * (1 - weight) + ordered[high] * weight


def wilson_interval(wins: int, total: int, z: float = 1.959963984540054) -> dict[str, float | None]:
    if total <= 0:
        return {"low": None, "high": None}
    p_hat = wins / total
    denominator = 1 + z**2 / total
    center = (p_hat + z**2 / (2 * total)) / denominator
    margin = z * math.sqrt((p_hat * (1 - p_hat) + z**2 / (4 * total)) / total) / denominator
    return {"low": round(center - margin, 6), "high": round(center + margin, 6)}


def is_certified(row: dict[str, str]) -> bool:
    flags = parse_flags(row.get("pnl_quality_flags"))
    declared = row.get("is_certified")
    declared_ok = True if declared is None or declared == "" else parse_bool(declared)

    return (
        declared_ok
        and row.get("analysis_status") == "matched_closed"
        and row.get("close_match_status") == "matched"
        and row.get("cost_completeness") == "complete"
        and not flags
        and parse_bool(row.get("position_fully_closed"))
        and parse_float(row.get("net_pnl_usdt")) is not None
        and parse_float(row.get("realized_net_pnl_r")) is not None
    )


def exclusion_reasons(row: dict[str, str]) -> list[str]:
    reasons: list[str] = []
    if row.get("analysis_status") != "matched_closed":
        reasons.append(f"analysis_status:{row.get('analysis_status') or 'unknown'}")
    if row.get("close_match_status") != "matched":
        reasons.append(f"close_match_status:{row.get('close_match_status') or 'unknown'}")
    if row.get("cost_completeness") != "complete":
        reasons.append(f"cost_completeness:{row.get('cost_completeness') or 'unknown'}")
    flags = parse_flags(row.get("pnl_quality_flags"))
    for flag in flags:
        reasons.append(f"pnl_quality_flag:{flag}")
    if not parse_bool(row.get("position_fully_closed")):
        reasons.append("position_not_fully_closed")
    if parse_float(row.get("net_pnl_usdt")) is None:
        reasons.append("missing_net_pnl_usdt")
    if parse_float(row.get("realized_net_pnl_r")) is None:
        reasons.append("missing_realized_net_pnl_r")
    if not reasons:
        reasons.append("declared_not_certified")
    return reasons


def loss_causes(row: dict[str, str]) -> list[str]:
    net = parse_float(row.get("net_pnl_usdt")) or 0.0
    gross = parse_float(row.get("gross_realized_pnl_usdt"))
    if net > 0 or (net == 0 and (gross is None or gross <= 0)):
        return []

    causes: list[str] = []
    mfe_r = parse_float(row.get("mfe_r"))
    realized_r = parse_float(row.get("realized_net_pnl_r"))
    entry_rsi = parse_float(row.get("entry_rsi"))
    entry_volume_ratio = parse_float(row.get("entry_volume_ratio"))
    mfe_mae_quality = str(row.get("mfe_mae_data_quality") or "unknown")

    if gross is not None and gross > 0 and net <= 0:
        causes.append("costs_destroy_edge")
    if entry_rsi is not None and (entry_rsi >= 70 or entry_rsi <= 30):
        causes.append("entry_momentum_extreme_candidate")
    if entry_volume_ratio is not None and entry_volume_ratio >= 1.5:
        causes.append("late_entry_candidate")
    if realized_r is not None and realized_r <= -0.9 and mfe_r is not None and mfe_r >= 0.5:
        causes.append("stop_or_exit_management_candidate")
    if mfe_r is not None and mfe_r > 0 and "costs_destroy_edge" not in causes:
        causes.append("positive_mfe_closed_negative")
    if mfe_mae_quality != "complete":
        causes.append(f"mfe_mae_quality:{mfe_mae_quality}")

    return causes or ["unknown_loss_driver"]


def max_drawdown(rows: list[dict[str, str]]) -> float | None:
    if not rows:
        return None
    equity = 0.0
    peak = 0.0
    drawdown = 0.0
    def realized_order_key(item: dict[str, str]) -> tuple[datetime, datetime, str]:
        entry_time = parse_datetime(item["entry_time"])
        close_time = parse_datetime(item.get("close_time") or item["entry_time"])
        return close_time, entry_time, str(item.get("entry_event_id") or "")

    for row in sorted(rows, key=realized_order_key):
        equity += parse_float(row.get("net_pnl_usdt")) or 0.0
        peak = max(peak, equity)
        drawdown = min(drawdown, equity - peak)
    return drawdown


def summarize_rows(rows: list[dict[str, str]]) -> dict[str, Any]:
    count = len(rows)
    nets = [parse_float(row.get("net_pnl_usdt")) or 0.0 for row in rows]
    rs = [value for row in rows if (value := parse_float(row.get("realized_net_pnl_r"))) is not None]
    gross_rs = [value for row in rows if (value := parse_float(row.get("realized_gross_pnl_r"))) is not None]
    mfe_rs = [value for row in rows if (value := parse_float(row.get("mfe_r"))) is not None]
    mae_rs = [value for row in rows if (value := parse_float(row.get("mae_r"))) is not None]
    durations = [value for row in rows if (value := parse_float(row.get("holding_time_sec"))) is not None]
    cost_fields = (
        "fees_usdt",
        "spread_cost_usdt",
        "slippage_cost_usdt",
        "funding_usdt",
        "total_known_cost_usdt",
    )

    wins = sum(1 for value in nets if value > 0)
    losses = sum(1 for value in nets if value < 0)
    gross_positive = sum(value for value in nets if value > 0)
    gross_negative = abs(sum(value for value in nets if value < 0))
    profit_factor = None if gross_negative == 0 else gross_positive / gross_negative
    causes = Counter(cause for row in rows for cause in loss_causes(row))

    return {
        "rows": count,
        "wins": wins,
        "losses": losses,
        "breakeven": count - wins - losses,
        "winrate": round_or_none(wins / count if count else None),
        "wilson_95": wilson_interval(wins, count),
        "net_pnl_usdt": round_or_none(sum(nets)),
        "net_expectancy_usdt": round_or_none(mean(nets)),
        "median_net_pnl_usdt": round_or_none(median(nets)),
        "profit_factor": round_or_none(profit_factor),
        "max_drawdown_usdt": round_or_none(max_drawdown(rows)),
        "mean_realized_net_pnl_r": round_or_none(mean(rs)),
        "median_realized_net_pnl_r": round_or_none(median(rs)),
        "mean_realized_gross_pnl_r": round_or_none(mean(gross_rs)),
        "mean_mfe_r": round_or_none(mean(mfe_rs)),
        "mean_mae_r": round_or_none(mean(mae_rs)),
        "median_duration_sec": round_or_none(median(durations)),
        "mean_duration_sec": round_or_none(mean(durations)),
        "costs_usdt": {
            field: round_or_none(sum(parse_float(row.get(field)) or 0.0 for row in rows))
            for field in cost_fields
        },
        "liquidity": {
            "maker_fills": sum(parse_int(row.get("maker_fill_count")) for row in rows),
            "taker_fills": sum(parse_int(row.get("taker_fill_count")) for row in rows),
            "unknown_fills": sum(parse_int(row.get("unknown_liquidity_fill_count")) for row in rows),
        },
        "loss_causes": dict(sorted(causes.items())),
    }


def summarize_population(rows: list[dict[str, str]], certified: list[dict[str, str]]) -> dict[str, Any]:
    excluded = [row for row in rows if row not in certified]
    excluded_reasons = Counter(reason for row in excluded for reason in exclusion_reasons(row))
    profiles: dict[str, dict[str, int]] = {}
    for profile in PROFILES:
        profile_rows = [row for row in rows if row.get("mtf_profile") == profile]
        profile_certified = [row for row in certified if row.get("mtf_profile") == profile]
        profiles[profile] = {
            "total_rows": len(profile_rows),
            "certified_rows": len(profile_certified),
            "excluded_rows": len(profile_rows) - len(profile_certified),
        }
    return {
        "total_rows": len(rows),
        "certified_rows": len(certified),
        "excluded_rows": len(excluded),
        "excluded_by_reason": dict(sorted(excluded_reasons.items())),
        "profiles": profiles,
    }


def grouped(rows: list[dict[str, str]], field: str) -> dict[str, dict[str, Any]]:
    buckets: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        buckets[row.get(field) or "unknown"].append(row)
    return {key: summarize_rows(value) for key, value in sorted(buckets.items())}


def group_by_tuple(rows: list[dict[str, str]], fields: tuple[str, ...]) -> dict[str, dict[str, Any]]:
    buckets: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        key = " / ".join(row.get(field) or "unknown" for field in fields)
        buckets[key].append(row)
    return {key: summarize_rows(value) for key, value in sorted(buckets.items())}


def simulate_group(rows: list[dict[str, str]], seed: int, runs: int) -> dict[str, Any]:
    nets = [parse_float(row.get("net_pnl_usdt")) for row in rows]
    nets = [value for value in nets if value is not None]
    durations = [value for row in rows if (value := parse_float(row.get("holding_time_sec"))) is not None]
    if not nets:
        return {
            "status": "not_computable_no_certified_trades",
            "capital_usdt": CAPITAL_USDT,
            "trades_per_path": TRADES_PER_SIMULATION,
        }

    rng = random.Random(seed)
    finals: list[float] = []
    max_drawdowns: list[float] = []
    for _ in range(runs):
        capital = CAPITAL_USDT
        peak = capital
        drawdown = 0.0
        for _trade in range(TRADES_PER_SIMULATION):
            capital += rng.choice(nets)
            peak = max(peak, capital)
            drawdown = min(drawdown, capital - peak)
        finals.append(capital)
        max_drawdowns.append(drawdown)

    median_duration = median(durations)
    estimated_duration_days = None
    if median_duration is not None:
        estimated_duration_days = median_duration * TRADES_PER_SIMULATION / 86400

    return {
        "capital_usdt": CAPITAL_USDT,
        "trades_per_path": TRADES_PER_SIMULATION,
        "monte_carlo_runs": runs,
        "compounding_off": {
            "p05_final_capital_usdt": round_or_none(percentile(finals, 0.05)),
            "p50_final_capital_usdt": round_or_none(percentile(finals, 0.50)),
            "p95_final_capital_usdt": round_or_none(percentile(finals, 0.95)),
            "p05_max_drawdown_usdt": round_or_none(percentile(max_drawdowns, 0.05)),
            "p50_max_drawdown_usdt": round_or_none(percentile(max_drawdowns, 0.50)),
        },
        "compounding_on": {
            "status": "not_computable_missing_risk_policy",
            "reason": "position_trade_analysis_v2 exposes risk_usdt_at_entry, not the account equity or risk fraction needed for real compounding.",
        },
        "estimated_duration_days": round_or_none(estimated_duration_days),
    }


def build_baseline(input_csv: Path, seed: int = 132, monte_carlo_runs: int = 1000) -> dict[str, Any]:
    with input_csv.open(newline="", encoding="utf-8") as handle:
        rows = list(csv.DictReader(handle))

    certified = [row for row in rows if is_certified(row)]
    groups = {
        "profile": grouped(certified, "mtf_profile"),
        "direction": grouped(certified, "direction"),
        "symbol": grouped(certified, "symbol"),
        "timeframe": grouped(certified, "timeframe"),
        "profile_symbol": group_by_tuple(certified, ("mtf_profile", "symbol")),
        "profile_direction": group_by_tuple(certified, ("mtf_profile", "direction")),
        "profile_timeframe": group_by_tuple(certified, ("mtf_profile", "timeframe")),
    }
    simulation_profiles = {
        profile: simulate_group([row for row in certified if row.get("mtf_profile") == profile], seed, monte_carlo_runs)
        for profile in PROFILES
    }

    return {
        "source": {
            "input_csv": str(input_csv),
            "contract": "certified export rows only; source may be v2 or ledger when the SQL marks cost_completeness complete",
        },
        "population": summarize_population(rows, certified),
        "groups": groups,
        "simulation": {
            "profile": simulation_profiles,
            "all_certified": simulate_group(certified, seed, monte_carlo_runs),
        },
        "coverage_gaps": [
            "direction/side is not exposed by position_trade_analysis_v2; direction segmentation is not computed by this export.",
            "EntryZone distance and entry extension require trade_zone_events or lifecycle extra fields joined by decision_key; they are reported as a_valider unless exported separately.",
            "maker/taker requires fill ledger aggregation; it is not present in position_trade_analysis_v2.",
            "TP/SL recalculation causes are not certified by v2 and must be joined from lifecycle events before causal claims.",
            "Compounding ON needs real account equity or risk fraction per trade; v2 only exposes risk_usdt_at_entry.",
        ],
    }


def render_metric(value: Any) -> str:
    if value is None:
        return "n/a"
    if isinstance(value, float):
        return f"{value:.6g}"
    return str(value)


def append_group_table(lines: list[str], title: str, groups: dict[str, dict[str, Any]]) -> None:
    lines.extend(
        [
            "",
            f"## {title}",
            "",
            "| Cle | Trades | Winrate | Net expectancy USDT | Profit factor | Max DD USDT | Mean R net | Loss causes |",
            "| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |",
        ]
    )
    if not groups:
        lines.append("| n/a | 0 | n/a | n/a | n/a | n/a | n/a | n/a |")
        return
    for key, metrics in groups.items():
        causes = ", ".join(f"{cause}={count}" for cause, count in metrics["loss_causes"].items()) or "n/a"
        lines.append(
            "| `{key}` | {rows} | {winrate} | {expectancy} | {pf} | {dd} | {mean_r} | {causes} |".format(
                key=key,
                rows=metrics["rows"],
                winrate=render_metric(metrics["winrate"]),
                expectancy=render_metric(metrics["net_expectancy_usdt"]),
                pf=render_metric(metrics["profit_factor"]),
                dd=render_metric(metrics["max_drawdown_usdt"]),
                mean_r=render_metric(metrics["mean_realized_net_pnl_r"]),
                causes=causes,
            )
        )


def render_markdown(result: dict[str, Any]) -> str:
    lines = [
        "# Baseline bad trades certifiee v2",
        "",
        "Ce rapport est genere uniquement depuis `position_trade_analysis_v2` avec lignes certifiees.",
        "Les lignes partielles, inconnues, ambigues, legacy ou a couts incomplets sont segmentees et exclues des metriques nettes.",
        "",
        "## Population",
        "",
        "| Total | Certifiees | Exclues |",
        "| ---: | ---: | ---: |",
        f"| {result['population']['total_rows']} | {result['population']['certified_rows']} | {result['population']['excluded_rows']} |",
        "",
        "### Exclusions",
        "",
        "| Raison | Lignes |",
        "| --- | ---: |",
    ]
    exclusions = result["population"]["excluded_by_reason"]
    if exclusions:
        for reason, count in exclusions.items():
            lines.append(f"| `{reason}` | {count} |")
    else:
        lines.append("| n/a | 0 |")

    lines.extend(
        [
            "",
            "## Metriques par profil",
            "",
            "| Profil | Trades | Winrate | Wilson 95% | Net expectancy USDT | Profit factor | Max DD USDT | Mean R net | Median R net | Loss causes |",
            "| --- | ---: | ---: | --- | ---: | ---: | ---: | ---: | ---: | --- |",
        ]
    )
    for profile in PROFILES:
        metrics = result["groups"]["profile"].get(profile)
        if metrics is None:
            lines.append(f"| `{profile}` | 0 | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a |")
            continue
        wilson = metrics["wilson_95"]
        causes = ", ".join(f"{key}={value}" for key, value in metrics["loss_causes"].items()) or "n/a"
        lines.append(
            "| `{profile}` | {rows} | {winrate} | {low}..{high} | {expectancy} | {pf} | {dd} | {mean_r} | {median_r} | {causes} |".format(
                profile=profile,
                rows=metrics["rows"],
                winrate=render_metric(metrics["winrate"]),
                low=render_metric(wilson["low"]),
                high=render_metric(wilson["high"]),
                expectancy=render_metric(metrics["net_expectancy_usdt"]),
                pf=render_metric(metrics["profit_factor"]),
                dd=render_metric(metrics["max_drawdown_usdt"]),
                mean_r=render_metric(metrics["mean_realized_net_pnl_r"]),
                median_r=render_metric(metrics["median_realized_net_pnl_r"]),
                causes=causes,
            )
        )

    append_group_table(lines, "Metriques par direction", result["groups"]["direction"])
    append_group_table(lines, "Metriques par timeframe", result["groups"]["timeframe"])
    append_group_table(lines, "Metriques par symbole", result["groups"]["symbol"])

    lines.extend(
        [
            "",
            "## Simulation 100 trades",
            "",
            "| Profil | Capital | Compounding OFF p05/p50/p95 | Max DD p50 | Compounding ON | Duree estimee jours |",
            "| --- | ---: | --- | ---: | --- | ---: |",
        ]
    )
    for profile in PROFILES:
        simulation = result["simulation"]["profile"][profile]
        off = simulation.get("compounding_off", {})
        if simulation.get("status"):
            off_label = simulation["status"]
            dd = "n/a"
        else:
            off_label = "{}/{}/{}".format(
                render_metric(off.get("p05_final_capital_usdt")),
                render_metric(off.get("p50_final_capital_usdt")),
                render_metric(off.get("p95_final_capital_usdt")),
            )
            dd = render_metric(off.get("p50_max_drawdown_usdt"))
        lines.append(
            f"| `{profile}` | {render_metric(simulation['capital_usdt'])} | {off_label} | {dd} | {simulation.get('compounding_on', {}).get('status', 'n/a')} | {render_metric(simulation.get('estimated_duration_days'))} |"
        )

    lines.extend(
        [
            "",
            "## Limites de couverture",
            "",
        ]
    )
    for gap in result["coverage_gaps"]:
        lines.append(f"- {gap}")

    lines.extend(
        [
            "",
            "## Lecture recommandee",
            "",
            "- Toute cause suffixee `_candidate` est une correlation a valider, pas une preuve causale.",
            "- `costs_destroy_edge` est factuel lorsque le brut est positif et le net certifie est negatif ou nul.",
            "- Aucun trade exclu ne doit etre reintegre dans les ratios de winrate, expectancy, profit factor ou simulation.",
            "",
        ]
    )
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build the #132 bad trades baseline from certified v2 CSV exports.")
    parser.add_argument("--input", required=True, type=Path, help="CSV exported by bad-trades-baseline-v2.sql")
    parser.add_argument("--output-md", required=True, type=Path, help="Markdown report path")
    parser.add_argument("--output-json", required=True, type=Path, help="Machine-readable JSON summary path")
    parser.add_argument("--seed", type=int, default=132, help="Deterministic Monte Carlo seed")
    parser.add_argument("--monte-carlo-runs", type=int, default=1000, help="Number of Monte Carlo paths")
    args = parser.parse_args(argv)

    result = build_baseline(args.input, seed=args.seed, monte_carlo_runs=args.monte_carlo_runs)
    args.output_md.parent.mkdir(parents=True, exist_ok=True)
    args.output_json.parent.mkdir(parents=True, exist_ok=True)
    args.output_md.write_text(render_markdown(result), encoding="utf-8")
    args.output_json.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
