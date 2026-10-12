"""Command line of the v2 candidate-selection screen.

    python -m scripts.research_v2.cli prepare --dataset-root R --cache-root C
    python -m scripts.research_v2.cli grid --out grid.json
    python -m scripts.research_v2.cli screen --cache-root C --cost-path … --instrument-path … \
        --phase training --out screen-training.json [--workers 3] [--seeds 10] [--configs a,b|--configs-file F]
    python -m scripts.research_v2.cli report --screen screen-training.json --out report.md
"""

from __future__ import annotations

import argparse
import hashlib
import json
import multiprocessing
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import budgets as budgets_module
from . import controls as controls_module
from . import costs, dataset, grid as grid_module, stats
from .engine import OUTCOME_DTYPE, PairInputs, Window, run_configuration
from .features import compute_features

PHASES = {
    "training": Window(1_672_531_200_000, 1_735_689_600_000),  # 2023-01-01 -> 2025-01-01
    "validation": Window(1_735_689_600_000, dataset.CUT_MS),  # 2025-01-01 -> 2026-01-01
}
TRAINING_YEARS = (2023, 2024)

_STATE: dict = {}


def code_identity() -> dict:
    package = Path(__file__).parent
    hashes = {}
    for path in sorted(package.glob("*.py")):
        hashes[path.name] = hashlib.sha256(path.read_bytes()).hexdigest()
    combined = hashlib.sha256("".join(f"{name}:{digest}\n" for name, digest in hashes.items()).encode()).hexdigest()
    return {"files": hashes, "package_sha256": combined}


def load_pairs(cache_root: Path, instrument_path: Path, symbols: tuple[str, ...]) -> dict[str, PairInputs]:
    instruments = costs.load_instruments(instrument_path)
    pairs = {}
    for symbol in symbols:
        candles, _ = dataset.load_cached_candles(cache_root, symbol)
        funding = dataset.load_cached_funding(cache_root, symbol)
        four_hour = compute_features(candles, "4h", funding)
        fifteen = compute_features(candles, "15m", funding, context=four_hour)
        pairs[symbol] = PairInputs.build(candles, funding, {"4h": four_hour, "15m": fifteen}, instruments[symbol])
    return pairs


def _outcomes_path(out_dir: Path, configuration_id: str) -> Path:
    safe = configuration_id.replace("|", "__").replace(".", "_")
    return out_dir / f"{safe}.npz"


def evaluate(configuration_json: dict) -> dict:
    configuration = grid_module.parse_configuration(configuration_json["id"])
    pairs, profiles, window, seeds, instruments, out_dir, phase = (
        _STATE["pairs"],
        _STATE["profiles"],
        _STATE["window"],
        _STATE["seeds"],
        _STATE["instruments"],
        _STATE["outcomes_dir"],
        _STATE["phase"],
    )
    started = time.time()
    outcomes, counters = run_configuration(configuration, pairs, window, profiles)
    base = stats.summarize(outcomes, "base")
    adverse = stats.summarize(outcomes, "adverse")
    control = None
    if seeds > 0 and outcomes.size > 0:
        control = controls_module.control_distribution(configuration, pairs, window, profiles, outcomes, seeds)
    row = {
        "id": configuration.id,
        "setup_id": configuration.setup_id,
        "family": configuration.family,
        "cadence": configuration.cadence,
        "side": configuration.side_name,
        "variant": configuration.variant.id,
        "counters": counters,
        "baseline": base,
        "adverse": adverse,
        "control": control,
        "budgets": budgets_module.budget_study(outcomes, instruments, "base") if outcomes.size else None,
        "seconds": round(time.time() - started, 2),
    }
    if phase == "training":
        row["gate_reasons"] = stats.training_gates(base, adverse, configuration.cadence, TRAINING_YEARS, control)
        row["passes"] = not row["gate_reasons"]
    if out_dir is not None:
        np.savez_compressed(_outcomes_path(out_dir, configuration.id), outcomes=outcomes)
    return row


def _init_worker(state: dict) -> None:
    _STATE.update(state)


def command_prepare(args: argparse.Namespace) -> int:
    receipt = dataset.prepare(Path(args.dataset_root), Path(args.cache_root))
    print(json.dumps({symbol: value["candles"]["candles"] for symbol, value in receipt["symbols"].items()}))
    return 0


def command_grid(args: argparse.Namespace) -> int:
    document = grid_module.write_grid(Path(args.out), grid_module.build_grid())
    print(json.dumps({"count": document["count"], "by_family": document["by_family"], "by_cadence": document["by_cadence"]}))
    return 0


def command_screen(args: argparse.Namespace) -> int:
    phase = args.phase
    window = PHASES[phase]
    cache_root = Path(args.cache_root)
    profiles = costs.load_cost_profiles(Path(args.cost_path))
    instruments = costs.load_instruments(Path(args.instrument_path))
    symbols = tuple(args.symbols.split(",")) if args.symbols else dataset.SYMBOLS
    configurations = grid_module.build_grid()
    if args.configs or args.configs_file:
        wanted = [item for item in args.configs.split(",") if item] if args.configs else []
        if args.configs_file:
            wanted += [line.strip() for line in Path(args.configs_file).read_text().splitlines() if line.strip()]
        try:
            configurations = tuple(grid_module.parse_configuration(identifier) for identifier in dict.fromkeys(wanted))
        except KeyError as error:
            raise SystemExit(f"unknown configuration: {error}") from error
    out = Path(args.out)
    outcomes_dir = out.parent / f"{out.stem}-outcomes"
    outcomes_dir.mkdir(parents=True, exist_ok=True)
    started = time.time()
    print(f"loading {len(symbols)} pairs…", file=sys.stderr, flush=True)
    pairs = load_pairs(cache_root, Path(args.instrument_path), symbols)
    state = {"pairs": pairs, "profiles": profiles, "window": window, "seeds": args.seeds, "instruments": instruments, "outcomes_dir": outcomes_dir, "phase": phase}
    print(f"loaded in {time.time() - started:.0f}s; {len(configurations)} configurations, {args.workers} workers", file=sys.stderr, flush=True)
    jobs = [configuration.to_json() for configuration in configurations]
    rows = []
    if args.workers > 1:
        context = multiprocessing.get_context("fork")
        with context.Pool(args.workers, initializer=_init_worker, initargs=(state,)) as pool:
            for index, row in enumerate(pool.imap_unordered(evaluate, jobs), 1):
                rows.append(row)
                print(f"[{index}/{len(jobs)}] {row['id']} trades={row['baseline'].get('trades', 0)} {row['seconds']}s", file=sys.stderr, flush=True)
    else:
        _init_worker(state)
        for index, job in enumerate(jobs, 1):
            row = evaluate(job)
            rows.append(row)
            print(f"[{index}/{len(jobs)}] {row['id']} trades={row['baseline'].get('trades', 0)} {row['seconds']}s", file=sys.stderr, flush=True)
    rows.sort(key=lambda row: row["id"])
    document = {
        "schema": "research-v2-screen.v1",
        "phase": phase,
        "window": {"start_ms": window.start_ms, "end_ms": window.end_ms},
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "code": code_identity(),
        "cost_assumptions_sha256": dataset.sha256_file(Path(args.cost_path)),
        "instrument_assumptions_sha256": dataset.sha256_file(Path(args.instrument_path)),
        "prepare_receipt_sha256": dataset.sha256_file(cache_root / "prepare-receipt.json"),
        "seeds": args.seeds,
        "symbols": list(symbols),
        "configurations": len(rows),
        "elapsed_seconds": round(time.time() - started, 1),
        "rows": rows,
    }
    out.write_text(json.dumps(document, indent=1, sort_keys=True, default=_json_default) + "\n")
    passes = sum(1 for row in rows if row.get("passes"))
    print(json.dumps({"configurations": len(rows), "passes": passes, "elapsed_seconds": document["elapsed_seconds"]}))
    return 0


def _json_default(value):
    if isinstance(value, (np.integer,)):
        return int(value)
    if isinstance(value, (np.floating,)):
        return float(value)
    if isinstance(value, np.ndarray):
        return value.tolist()
    raise TypeError(f"not serializable: {type(value)!r}")


def command_report(args: argparse.Namespace) -> int:
    from .report import render_report

    document = json.loads(Path(args.screen).read_text())
    Path(args.out).write_text(render_report(document))
    print(args.out)
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_v2")
    sub = parser.add_subparsers(dest="command", required=True)
    prepare = sub.add_parser("prepare")
    prepare.add_argument("--dataset-root", required=True)
    prepare.add_argument("--cache-root", required=True)
    prepare.set_defaults(func=command_prepare)
    grid = sub.add_parser("grid")
    grid.add_argument("--out", required=True)
    grid.set_defaults(func=command_grid)
    screen = sub.add_parser("screen")
    screen.add_argument("--cache-root", required=True)
    screen.add_argument("--cost-path", required=True)
    screen.add_argument("--instrument-path", required=True)
    screen.add_argument("--phase", choices=sorted(PHASES), required=True)
    screen.add_argument("--out", required=True)
    screen.add_argument("--workers", type=int, default=max(1, min(3, (os.cpu_count() or 2) - 1)))
    screen.add_argument("--seeds", type=int, default=10)
    screen.add_argument("--configs", default="")
    screen.add_argument("--configs-file", default="")
    screen.add_argument("--symbols", default="")
    screen.set_defaults(func=command_screen)
    report = sub.add_parser("report")
    report.add_argument("--screen", required=True)
    report.add_argument("--out", required=True)
    report.set_defaults(func=command_report)
    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
