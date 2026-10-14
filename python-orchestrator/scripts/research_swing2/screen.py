"""Swing v2 screen driver: run a phase, select, verdict, budgets, and the one-shot holdout claim.

    python -m scripts.research_swing2.screen run --root R --symbols A,B --phase training --out screen-training.json [--configs FILE] [--seeds 20] [--workers 2]
    python -m scripts.research_swing2.screen select --training screen-training.json --out selection.json
    python -m scripts.research_swing2.screen verdict --phase validation --screen screen-validation.json --selection selection.json --out verdict-validation.json
    python -m scripts.research_swing2.screen claim --campaign DIR --selection verdict-validation.json
    python -m scripts.research_swing2.screen budgets --root R --symbols … --phase validation --configs FILE --out budgets.json

Every window is read through ``data.load_market`` with the phase's cut; the holdout phase refuses to run
without the campaign's claim file and refuses to overwrite an existing holdout output.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys
import time
from collections import Counter, defaultdict
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

from . import engine as en
from . import stats as st
from .data import HOLDOUT_END_MS, OKX_END_MS, VALIDATION_CUT_MS, load_market

OKX_LAST_DECISION_MS = 1_790_611_200_000  # 2026-09-28T16:00:00Z: 14 full days before the end of the OKX data
PHASES = {
    "training": ((1_672_531_200_000, 1_735_689_600_000), 1_735_689_600_000, ("2023", "2024")),
    "validation": ((1_672_531_200_000, VALIDATION_CUT_MS), VALIDATION_CUT_MS, ("2023", "2024", "2025")),
    "holdout": ((VALIDATION_CUT_MS, HOLDOUT_END_MS), HOLDOUT_END_MS, ("2026",)),
    # OKX campaign (owner, 2026-10-14): one configuration, the monthly universe, 2023-01-01 to the end of the archive.
    # Decisions until 2026-09-28T16:00Z (14 full days before the end of the data), positions until the data end.
    "okx": ((1_672_531_200_000, OKX_LAST_DECISION_MS), OKX_END_MS, ("2023", "2024", "2025", "2026")),
}
OKX_SELECTION_P = 0.10
DEFAULT_SEEDS = 100
CLAIM_NAME = "holdout-claim.json"
PACKAGE_FILES = ("engine.py", "data.py", "stats.py", "screen.py")


def code_hashes() -> dict:
    here = Path(__file__).resolve().parent
    research = here.parent.parent / "app" / "backtesting" / "research"
    files = [here / name for name in PACKAGE_FILES] + [research / "swing.py", research / "exploration.py"]
    return {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in files}


def _now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def _log(message: str) -> None:
    print(f"{_now()} {message}", file=sys.stderr, flush=True)


# --------------------------------------------------------------------------- workers
_STATE: dict = {}


def _init(root: str, symbols: tuple[str, ...], cut: int, universe: str | None = None) -> None:
    if universe is None:
        market, profiles = load_market(Path(root), symbols, cut)
    else:  # the OKX campaign: candles kept from the first month of membership to the last one + the holding horizon
        eligible = eligibility(Path(universe))
        gaps = json.loads((Path(root) / "gaps.json").read_text())
        keep = {}
        for symbol, months in eligible.items():
            begin = en_month_start(min(months)) - en.DAY  # one day of margin: a settlement at 00:00 needs a prior close
            end = en_month_start(_next_month(max(months))) + 15 * en.DAY
            if gaps.get(symbol):  # a deadline may move past an interruption: keep everything after
                end = cut
            keep[symbol] = (begin, min(end, cut))
        market, profiles = load_market(Path(root), symbols, cut, keep=keep, light=True)
        market.eligible = eligible
        market.cadence_aware = True
    _STATE.update(market=market, profiles=profiles)


def en_month_start(month: str) -> int:
    from datetime import datetime, timezone

    year, number = map(int, month.split("-"))
    return int(datetime(year, number, 1, tzinfo=timezone.utc).timestamp() * 1000)


def _next_month(month: str) -> str:
    year, number = map(int, month.split("-"))
    return f"{year + (number == 12):04d}-{number % 12 + 1:02d}"


def eligibility(path: Path) -> dict[str, frozenset]:
    """symbol -> months open to new entries, from a monthly universe file ({"months": {"YYYY-MM": {"universe": [...]}}})."""
    months: dict[str, set] = defaultdict(set)
    for month, entry in json.loads(path.read_text())["months"].items():
        for symbol in entry["universe"]:
            months[symbol].add(month)
    return {symbol: frozenset(values) for symbol, values in months.items()}


def _compact(run: en.StreamRun, profiles, phase: str, years) -> dict:
    document = {"signals": run.signals, "admitted": run.admitted, "unfilled": run.unfilled, "unsized": run.unsized,
                "rejections": dict(sorted(run.rejections.items()))}
    for profile in profiles:
        document[profile] = st.summarize(run.trades, profile)
        document[profile]["gate_reasons"] = st.gate_reasons(document[profile], phase, profile, years)
        if phase == "okx":
            name = f"{profile}_conservative"
            document[name] = st.summarize(run.trades, profile, conservative=True)
            document[name]["gate_reasons"] = st.gate_reasons(document[name], phase, profile, years)
    return document


def _scenarios(phase: str, profiles) -> list[str]:
    return [*profiles, *(f"{p}_conservative" for p in profiles)] if phase == "okx" else list(profiles)


def evaluate(config: en.Config, phase: str, seeds: int, regime=None) -> dict:
    market, profiles = _STATE["market"], _STATE["profiles"]
    window, cut, years = PHASES[phase]
    rows = en.signals(market, config, window)
    run = en.run_stream(market, rows, {config.side: config.geometry}, window, profiles, phase_end=cut)
    document = {"id": config.id, "side": config.side, "lookback": config.lookback, "strength": config.strength,
                "geometry": config.geometry.id, **_compact(run, profiles, phase, years)}
    scenarios = _scenarios(phase, profiles)
    document["passes_gates"] = all(not document[s]["gate_reasons"] for s in scenarios)
    if phase == "okx":
        document["trades"] = [{"symbol": t.symbol, "decision_ms": t.decision_ms, "fill_ms": t.fill_ms, "exit_ms": t.exit_ms,
                               "entry": t.entry, "stop": t.stop, "exit_price": t.exit_price, "reason": t.reason, "event": t.event,
                               "quantity": t.quantity, "net": t.net, "risk": t.risk, "risk_conservative": t.risk_conservative,
                               "funding": t.funding, "funding_imputed": t.funding_imputed} for t in run.trades]
    counts = Counter(row[2] for row in rows)
    controls = []
    for seed in range(seeds):
        crow = en.control_signals(market, config, window, seed, regime, counts)
        crun = en.run_stream(market, crow, {config.side: config.geometry}, window, profiles, phase_end=cut)
        compact = _compact(crun, profiles, phase, years)
        controls.append({"seed": seed, "trades": compact["baseline"]["trades"], "unfilled": compact["unfilled"],
                         "mean_net_r": {p: compact[p]["mean_net_r"] for p in scenarios},
                         "net_quote": {p: compact[p]["net_quote"] for p in scenarios},
                         "profit_factor": {p: compact[p]["profit_factor"] for p in scenarios},
                         "passes_gates": all(not compact[s]["gate_reasons"] for s in scenarios)})
    document["controls"] = controls
    document["control"] = {p: st.control_position(document[p]["mean_net_r"], [c["mean_net_r"][p] for c in controls])
                           for p in scenarios}
    return document


def evaluate_pool(long_config: en.Config, short_config: en.Config, phase: str) -> dict:
    """Information: the long and short streams of the same parameters sharing one portfolio."""
    market, profiles = _STATE["market"], _STATE["profiles"]
    window, _, years = PHASES[phase]
    rows = sorted(en.signals(market, long_config, window) + en.signals(market, short_config, window))
    run = en.run_stream(market, rows, {"long": long_config.geometry, "short": short_config.geometry}, window, profiles)
    document = {"id": long_config.id.replace("|long|", "|pool|"), **_compact(run, profiles, phase, years)}
    document["passes_gates"] = st.passes(document, phase, years)
    document["by_side"] = {side: {p: st.summarize([t for t in run.trades if t.side == side], p)["mean_net_r"] for p in profiles}
                           | {"trades": sum(1 for t in run.trades if t.side == side)} for side in en.SIDES}
    return document


def _job(arguments) -> list[dict]:
    kind, phase, seeds, ids = arguments
    market = _STATE["market"]
    if kind == "configs":
        configs = [en.Config.parse(i) for i in ids]
        regime = en.regime_bars(market, configs[0].side, PHASES[phase][0])
        out = [evaluate(c, phase, seeds, regime) for c in configs]
    else:
        out = [evaluate_pool(en.Config.parse(a), en.Config.parse(b), phase) for a, b in ids]
    market.paths.clear()
    return out


def _mirror(config: en.Config) -> en.Config:
    g = config.geometry
    other = "short" if g.side == "long" else "long"
    return en.Config(config.lookback, config.strength, en.Geometry(other, g.entry, g.stop_atr, g.exit, g.hold_days, g.target_r))


def run_phase(root: Path, symbols: tuple[str, ...], phase: str, configs: list[en.Config], seeds: int, workers: int,
              pools: bool, universe: str | None = None) -> dict:
    window, cut, years = PHASES[phase]
    groups: dict = defaultdict(list)
    for config in configs:
        groups[config.geometry].append(config.id)
    jobs = [("configs", phase, seeds, ids) for ids in groups.values()]
    if pools:
        longs = [c for c in configs if c.side == "long"]
        pairs = [(c.id, _mirror(c).id) for c in longs]
        by_geometry: dict = defaultdict(list)
        for long_id, short_id in pairs:
            by_geometry[en.Config.parse(long_id).geometry].append((long_id, short_id))
        jobs += [("pools", phase, 0, items) for items in by_geometry.values()]
    started = time.time()
    results: list[dict] = []
    pooled: list[dict] = []
    with ProcessPoolExecutor(max_workers=max(1, workers), initializer=_init, initargs=(str(root), symbols, cut, universe)) as pool:
        for count, (job, out) in enumerate(zip(jobs, pool.map(_job, jobs)), start=1):
            (results if job[0] == "configs" else pooled).extend(out)
            _log(f"{phase}: job {count}/{len(jobs)} done ({len(results)} configurations, {len(pooled)} pools, {time.time() - started:.0f} s)")
    results.sort(key=lambda r: r["id"])
    pooled.sort(key=lambda r: r["id"])
    document = {"phase": phase, "window": list(window), "cut_ms": cut, "years": list(years), "root": str(root),
                "symbols": list(symbols), "seeds": seeds, "universe_file": universe, "reference_equity": en.REFERENCE_EQUITY,
                "code_sha256": code_hashes(), "receipt_sha256": _receipt_hash(root), "started_at": _now(),
                "elapsed_seconds": round(time.time() - started, 1), "configurations": results, "pools": pooled}
    finalize(document)
    return document


def _receipt_hash(root: Path) -> str | None:
    for name in ("build-receipt.json", "prepare-report.json"):
        path = root / name
        if path.exists():
            return hashlib.sha256(path.read_bytes()).hexdigest()
    return None


def finalize(document: dict) -> None:
    """Chance baseline, BH q-values and fill information over the whole run."""
    rows = document["configurations"]
    phase = document["phase"]
    for side in ("all", *en.SIDES):
        subset = [r for r in rows if side == "all" or r["side"] == side]
        q = st.benjamini_hochberg({r["id"]: r["control"]["baseline"]["p_empirical"] for r in subset})
        for r in subset:
            r.setdefault("bh_q", {})[side] = q[r["id"]]
    chance: dict = {}
    for side in ("all", *en.SIDES):
        subset = [r for r in rows if side == "all" or r["side"] == side]
        if not subset:
            continue
        seeds = document["seeds"]
        control_pass = sum(1 for r in subset for c in r["controls"] if c["passes_gates"])
        control_select = 0
        for r in subset:
            for c in r["controls"]:
                others = [o for o in r["controls"] if o is not c]
                pseudo = {"passes_gates": c["passes_gates"], "control": {
                    p: st.control_position(c["mean_net_r"][p], [o["mean_net_r"][p] for o in others]) for p in st.PROFILES}}
                control_select += st.selectable(pseudo)
        chance[side] = {
            "configurations": len(subset),
            "pass_gates": sum(1 for r in subset if r["passes_gates"]),
            "selectable": sum(1 for r in subset if st.selectable(r)),
            "expected_pass_gates_by_chance": control_pass / seeds if seeds else None,
            "expected_selectable_by_chance": control_select / seeds if seeds else None,
            "control_pass_rate": control_pass / (seeds * len(subset)) if seeds else None,
        }
    document["chance"] = chance
    fills: dict = defaultdict(lambda: {"admitted": 0, "unfilled": 0})
    for r in rows:
        key = f"{r['side']}|B{r['lookback']}|{r['geometry'].split('|')[1]}"
        fills[key]["admitted"] += r["admitted"]
        fills[key]["unfilled"] += r["unfilled"]
    document["unfilled_entries"] = {k: {**v, "rate": v["unfilled"] / v["admitted"] if v["admitted"] else None}
                                    for k, v in sorted(fills.items())}
    if phase == "training":
        document["passing"] = [r["id"] for r in rows if r["passes_gates"]]
        document["selection"] = st.select(rows)


def verdict(screen: dict, selected: list[str]) -> dict:
    """Decision for the selected configurations; every other row is information (not selectable)."""
    phase = screen["phase"]
    out = {"phase": phase, "selected": selected, "rows": [], "validated": []}
    for r in screen["configurations"]:
        checks = {
            "gates": r["passes_gates"],
            "surplus_baseline": (r["control"]["baseline"]["surplus"] or 0) > 0,
            "surplus_adverse": (r["control"]["adverse"]["surplus"] or 0) > 0,
        }
        if phase == "okx":  # every scenario: both cost profiles, main and conservative
            for scenario in st.SCENARIOS:
                position = r["control"].get(scenario, {})
                checks[f"surplus_{scenario}"] = (position.get("surplus") or 0) > 0
                p = position.get("p_empirical")
                checks[f"p_{scenario}"] = p is not None and p <= OKX_SELECTION_P
        passed = all(checks.values())
        decided = r["id"] in selected
        out["rows"].append({"id": r["id"], "selected": decided, "passes": passed, "checks": checks,
                            "gate_reasons": {p: r[p]["gate_reasons"] for p in st.PROFILES},
                            "mean_net_r": {p: r[p]["mean_net_r"] for p in st.PROFILES},
                            "trades": r["baseline"]["trades"], "control": r["control"]})
        if decided and passed:
            out["validated"].append(r["id"])
    out["status"] = "validated" if out["validated"] else "no_eligible_candidate"
    return out


# --------------------------------------------------------------------------- budgets
def budgets(root: Path, symbols: tuple[str, ...], phase: str, ids: list[str], equities: list[float], universe: str | None = None) -> dict:
    window, cut, _ = PHASES[phase]
    _init(str(root), symbols, cut, universe)
    market, profiles = _STATE["market"], _STATE["profiles"]
    days = (window[1] - window[0]) / en.DAY
    out = {"phase": phase, "equities": equities, "rows": []}
    for config_id in ids:
        config = en.Config.parse(config_id)
        rows = en.signals(market, config, window)
        for equity in equities:
            run = en.run_stream(market, rows, {config.side: config.geometry}, window, profiles, equity=equity, phase_end=cut)
            entry = {"id": config_id, "equity": equity, "unsized": run.unsized, "trades": len(run.trades)}
            for profile in st.PROFILES:
                summary = st.summarize(run.trades, profile, equity)
                entry[profile] = {"net_quote": summary["net_quote"], "net_per_day": summary["net_quote"] / days,
                                  "drawdown_quote": summary["drawdown_quote"], "return_pct": 100 * summary["net_quote"] / equity}
            entry["min_notional"] = min((t.quantity * t.entry * float(market.instruments[t.symbol]["contract_size"]) for t in run.trades), default=None)
            out["rows"].append(entry)
    return out


# --------------------------------------------------------------------------- holdout claim
def claim(campaign: Path, validation_verdict: Path | None = None, posthoc: list[str] | None = None,
          prereg: Path | None = None, status: str = "posthoc_indicative_not_validated") -> dict:
    """Create the one-shot holdout claim (exclusive create); refuses when one exists or nothing validated.

    ``posthoc``: an indicative read of configurations chosen after a validation (owner's decision), bound
    to a frozen pre-registration (its ``.sha256`` file must exist); the claim says so and lists them under
    ``validated`` only so that the holdout guard can check the run against it.
    """
    if posthoc is not None:
        if not posthoc or prereg is None or not prereg.with_suffix(".sha256").exists():
            raise SystemExit("post-hoc claim refused: configurations and a frozen pre-registration are required")
        payload = {"claimed_at": _now(), "validated": posthoc, "status": status,
                   "prereg": str(prereg), "prereg_sha256_file": prereg.with_suffix(".sha256").read_text().split()[0]}
    else:
        document = json.loads(validation_verdict.read_text())
        if document.get("phase") != "validation" or not document.get("validated"):
            raise SystemExit("no validated configuration: the holdout stays closed")
        payload = {"claimed_at": _now(), "validated": document["validated"],
                   "validation_verdict_sha256": hashlib.sha256(validation_verdict.read_bytes()).hexdigest()}
    path = campaign / CLAIM_NAME
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o444)
    with os.fdopen(descriptor, "w") as handle:
        handle.write(json.dumps(payload, indent=1) + "\n")
    return payload


def _holdout_guard(campaign: Path, out: Path, ids: list[str]) -> None:
    path = campaign / CLAIM_NAME
    if not path.exists():
        raise SystemExit("holdout refused: no claim")
    claimed = json.loads(path.read_text())["validated"]
    if sorted(ids) != sorted(claimed):
        raise SystemExit("holdout refused: configurations differ from the claim")
    if out.exists():
        raise SystemExit("holdout refused: the output exists (one read only)")


# --------------------------------------------------------------------------- CLI
def _ids(path: str | None) -> list[str]:
    if path is None:
        return [c.id for c in en.grid()]
    text = Path(path).read_text()
    if text.lstrip().startswith(("{", "[")):
        document = json.loads(text)
        return list(document if isinstance(document, list) else document.get("validated") or document.get("ids"))
    return [line.strip() for line in text.splitlines() if line.strip() and not line.startswith("#")]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_swing2.screen")
    sub = parser.add_subparsers(dest="command", required=True)
    run_p = sub.add_parser("run")
    run_p.add_argument("--root", required=True)
    run_p.add_argument("--symbols", required=True)
    run_p.add_argument("--phase", choices=tuple(PHASES), required=True)
    run_p.add_argument("--configs")
    run_p.add_argument("--seeds", type=int, default=DEFAULT_SEEDS)
    run_p.add_argument("--workers", type=int, default=2)
    run_p.add_argument("--no-pools", action="store_true")
    run_p.add_argument("--campaign")
    run_p.add_argument("--universe", help="monthly universe file: membership gates new entries")
    run_p.add_argument("--out", required=True)
    sel_p = sub.add_parser("select")
    sel_p.add_argument("--training", required=True)
    sel_p.add_argument("--out", required=True)
    ver_p = sub.add_parser("verdict")
    ver_p.add_argument("--screen", required=True)
    ver_p.add_argument("--selection", required=True)
    ver_p.add_argument("--out", required=True)
    claim_p = sub.add_parser("claim")
    claim_p.add_argument("--campaign", required=True)
    claim_p.add_argument("--verdict")
    claim_p.add_argument("--posthoc", help="file of configuration ids (indicative post-hoc read)")
    claim_p.add_argument("--prereg", help="the frozen pre-registration of the post-hoc read")
    claim_p.add_argument("--status", default="posthoc_indicative_not_validated")
    bud_p = sub.add_parser("budgets")
    bud_p.add_argument("--root", required=True)
    bud_p.add_argument("--symbols", required=True)
    bud_p.add_argument("--phase", choices=tuple(PHASES), required=True)
    bud_p.add_argument("--configs", required=True)
    bud_p.add_argument("--equities", default="500,1000,2500,5000,10000")
    bud_p.add_argument("--universe")
    bud_p.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    if args.command == "run":
        out = Path(args.out)
        ids = _ids(args.configs)
        if args.phase in ("holdout", "okx"):
            if not args.campaign:
                raise SystemExit("holdout refused: --campaign is required")
            _holdout_guard(Path(args.campaign), out, ids)
        configs = [en.Config.parse(i) for i in ids]
        document = run_phase(Path(args.root), tuple(args.symbols.split(",")), args.phase, configs, args.seeds, args.workers,
                             not args.no_pools, args.universe)
        out.write_text(json.dumps(document, indent=1, sort_keys=True, default=_json_default) + "\n")
        _log(f"{args.phase}: wrote {out}")
    elif args.command == "select":
        training = json.loads(Path(args.training).read_text())
        document = {"selected": training["selection"], "passing": training["passing"], "chance": training["chance"],
                    "training_sha256": hashlib.sha256(Path(args.training).read_bytes()).hexdigest()}
        Path(args.out).write_text(json.dumps(document, indent=1) + "\n")
        print(json.dumps({"selected": document["selected"], "passing": len(document["passing"])}))
    elif args.command == "verdict":
        selection = json.loads(Path(args.selection).read_text())
        selected = selection["selected"] if "selected" in selection else selection["validated"]
        document = verdict(json.loads(Path(args.screen).read_text()), selected)
        Path(args.out).write_text(json.dumps(document, indent=1, default=_json_default) + "\n")
        print(json.dumps({"status": document["status"], "validated": document["validated"]}))
    elif args.command == "claim":
        if args.posthoc:
            print(json.dumps(claim(Path(args.campaign), posthoc=_ids(args.posthoc), prereg=Path(args.prereg) if args.prereg else None,
                                   status=args.status)))
        elif args.verdict:
            print(json.dumps(claim(Path(args.campaign), Path(args.verdict))))
        else:
            raise SystemExit("claim needs --verdict or --posthoc")
    elif args.command == "budgets":
        document = budgets(Path(args.root), tuple(args.symbols.split(",")), args.phase, _ids(args.configs),
                           [float(x) for x in args.equities.split(",")], args.universe)
        Path(args.out).write_text(json.dumps(document, indent=1, default=_json_default) + "\n")
    return 0


def _json_default(value):
    if isinstance(value, float):
        return None
    raise TypeError(type(value))


if __name__ == "__main__":
    raise SystemExit(main())
