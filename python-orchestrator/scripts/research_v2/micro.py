"""Memory-light driver for the 1-minute (micro-scalping) configurations.

The pooled screen keeps every pair's features in memory and forks workers; with the 1-minute
features of ten pairs that exhausted the host (first scalping run, 2026-10-12). This driver
walks the pairs one at a time: it builds one pair's 1-minute features, replays every requested
configuration and its random controls on that pair, keeps only the outcomes, and frees the
features before the next pair. Rows and the screen document have the pooled screen's schema.

    python -m scripts.research_v2.micro --cache-root C --cost-path … --instrument-path … \
        --phase training --out scalping/screen-training-1m.json [--seeds 10] [--configs-file F]
"""

from __future__ import annotations

import argparse
import gc
import json
import sys
import time
from pathlib import Path

import numpy as np

from . import cli, controls, costs, dataset, scalping, stats
from .engine import OUTCOME_DTYPE, PairInputs, run_pair
from .features import compute_features


def _concat(pieces: list[np.ndarray]) -> np.ndarray:
    if not pieces:
        return np.zeros(0, dtype=OUTCOME_DTYPE)
    outcomes = np.concatenate(pieces)
    return outcomes[np.argsort(outcomes["decision_ms"], kind="stable")]


def run_micro_screen(cache_root: Path, cost_path: Path, instrument_path: Path, phase: str, out: Path, seeds: int, symbols: tuple[str, ...], configurations: tuple) -> dict:
    window = cli.PHASES[phase]
    profiles = costs.load_cost_profiles(cost_path)
    profiles["doubled"] = costs.doubled_spread(profiles["baseline"])
    instruments = costs.load_instruments(instrument_path)
    started = time.time()
    outcomes_by_configuration: dict[str, list[np.ndarray]] = {configuration.id: [] for configuration in configurations}
    counters_by_configuration: dict[str, dict] = {configuration.id: {"signals": 0, "admitted": 0, "unfilled": 0, "unsized": 0, "filled": 0} for configuration in configurations}
    # Control ledgers are not kept (ten seeds × ten pairs × tens of thousands of rows per
    # configuration exhausted the memory of the first run); only the sums the control
    # distribution needs are accumulated per seed.
    controls_by_configuration: dict[str, list[_ControlSums]] = {configuration.id: [_ControlSums() for _ in range(seeds)] for configuration in configurations}
    for symbol in symbols:
        pair_started = time.time()
        candles, taker_buy = dataset.load_cached_candles(cache_root, symbol)
        funding = dataset.load_cached_funding(cache_root, symbol)
        four_hour = compute_features(candles, "4h", funding)
        fifteen = compute_features(candles, "15m", funding, context=four_hour)
        one_minute = scalping.compute_scalping_features(candles, taker_buy, "1m", funding, fifteen)
        inputs = PairInputs.build(candles, funding, {"1m": one_minute}, instruments[symbol])
        del four_hour, fifteen, taker_buy
        for configuration in configurations:
            run = run_pair(configuration, inputs, window, profiles)
            outcomes_by_configuration[configuration.id].append(run.outcomes)
            counters = counters_by_configuration[configuration.id]
            counters["signals"] += run.signals
            counters["admitted"] += run.admitted
            counters["unfilled"] += run.unfilled
            counters["unsized"] += run.unsized
            counters["filled"] += int(run.outcomes.size)
            if run.outcomes.size:
                for seed in range(seeds):
                    control = controls.run_control(configuration, {symbol: inputs}, window, profiles, run.outcomes, seed)
                    controls_by_configuration[configuration.id][seed].add(control)
                    del control
        del inputs, one_minute, candles, funding
        gc.collect()
        print(f"[{symbol}] {len(configurations)} configurations in {time.time() - pair_started:.0f}s", file=sys.stderr, flush=True)
    outcomes_dir = out.parent / f"{out.stem}-outcomes"
    outcomes_dir.mkdir(parents=True, exist_ok=True)
    rows = []
    for configuration in configurations:
        row_started = time.time()
        outcomes = _concat(outcomes_by_configuration[configuration.id])
        control = None
        if seeds > 0 and outcomes.size:
            control = _control_distribution(controls_by_configuration[configuration.id], seeds)
        row = cli.build_row(configuration, outcomes, counters_by_configuration[configuration.id], control, "scalping", phase, instruments, row_started)
        rows.append(row)
        np.savez_compressed(cli._outcomes_path(outcomes_dir, configuration.id), outcomes=outcomes)
        print(f"{configuration.id} trades={outcomes.size}", file=sys.stderr, flush=True)
    document = cli.screen_document("scalping", phase, window, rows, cost_path, instrument_path, cache_root, seeds, symbols, started, driver="micro_sequential")
    out.write_text(json.dumps(document, indent=1, sort_keys=True, default=cli._json_default) + "\n")
    return document


class _ControlSums:
    """Running sums of one control seed across pairs: enough for the mean net R and the PF."""

    def __init__(self) -> None:
        self.trades = 0
        self.sum_r_base = 0.0
        self.sum_r_adverse = 0.0
        self.gains = 0.0
        self.losses = 0.0

    def add(self, outcomes: np.ndarray) -> None:
        if outcomes.size == 0:
            return
        net = outcomes["net_base"]
        self.trades += int(outcomes.size)
        self.sum_r_base += float(np.sum(net / outcomes["risk_base"]))
        self.sum_r_adverse += float(np.sum(outcomes["net_adverse"] / outcomes["risk_adverse"]))
        self.gains += float(net[net > 0].sum())
        self.losses += float(-net[net < 0].sum())


def _control_distribution(per_seed: list[_ControlSums], seeds: int) -> dict:
    mean_r, mean_r_adverse, profit_factors, trades = [], [], [], []
    for sums in per_seed:
        trades.append(sums.trades)
        if sums.trades == 0:
            continue
        mean_r.append(sums.sum_r_base / sums.trades)
        mean_r_adverse.append(sums.sum_r_adverse / sums.trades)
        profit_factors.append(sums.gains / sums.losses if sums.losses else None)
    values = np.asarray(mean_r)
    return {
        "seeds": seeds,
        "trades": trades,
        "mean_r": mean_r,
        "mean_r_adverse": mean_r_adverse,
        "profit_factor": profit_factors,
        "median": float(np.median(values)) if values.size else None,
        "p95": float(np.quantile(values, 0.95)) if values.size else None,
        "max": float(values.max()) if values.size else None,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_v2.micro")
    parser.add_argument("--cache-root", required=True)
    parser.add_argument("--cost-path", required=True)
    parser.add_argument("--instrument-path", required=True)
    parser.add_argument("--phase", choices=sorted(cli.PHASES), required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--seeds", type=int, default=10)
    parser.add_argument("--configs-file", default="")
    parser.add_argument("--symbols", default="")
    args = parser.parse_args(argv)
    configurations = tuple(configuration for configuration in scalping.build_scalping_grid() if configuration.cadence == "1m")
    if args.configs_file:
        wanted = [line.strip() for line in Path(args.configs_file).read_text().splitlines() if line.strip()]
        configurations = tuple(scalping.parse_scalp_configuration(identifier) for identifier in dict.fromkeys(wanted))
        if any(configuration.cadence != "1m" for configuration in configurations):
            raise SystemExit("the micro driver only runs 1-minute configurations")
    symbols = tuple(args.symbols.split(",")) if args.symbols else dataset.SYMBOLS
    document = run_micro_screen(Path(args.cache_root), Path(args.cost_path), Path(args.instrument_path), args.phase, Path(args.out), args.seeds, symbols, configurations)
    print(json.dumps({"configurations": document["configurations"], "passes": sum(1 for row in document["rows"] if row.get("passes")), "elapsed_seconds": document["elapsed_seconds"]}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
