"""scalp-q3-r2 driver (PREREGISTRATION-scalp-q3-r2.md): training windows only.

    python -m scripts.research_exec.q3r2_run prepare --tape-dir T --instruments I --funding-dir F --out DIR --workers 2 --prereg P
    python -m scripts.research_exec.q3r2_run analyse --out DIR --q3x-dir Q [--seeds 1000 --portfolio-seeds 200]

`prepare` (by series, worker processes): outcome table of every second (E1 entry, Z3 and
Z3-SL200 exits, funding), the four signals, the H1 pools, the candidate details (MAE/MFE,
minute marks), the latency-stress outcomes, and the `run_policy` replay + 10-seed historical
control of the four Z3 configurations (parity). `analyse` (parent, window by window): candidate
replays, parity checks, controls, portfolios, criteria and verdict → `results.json`.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import multiprocessing
import os
import shutil
import sys
import time
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import screen as sc
from .q3r2_engine import (CONFIG_DEPTH, CONFIG_RULES, CONFIG_SIGMA, CONFIGS, CROSSED, DEAD, FILLED, NONE, OUTCOME_FIELDS, PASSIVE, REMAINDER_FIELDS, STOP, UNFILLED, VARIANTS,
                          Funding, compute_outcomes, excursions, passive_through_qty, path_marks, standalone, trade_rows)
from .q3r2_portfolio import SCENARIOS, Book, Stream, run_window
from .q3r2_stats import (BOOT_RESAMPLES, band_state_draws, bootstrap_surplus, bootstrap_total, hour_of, hour_pools, max_drawdown, p_value, rng_for, shift_episodes,
                         signal_summary, strata, tails)
from .scalp_engine import TRADE_DTYPE, Policy
from .tick import SECOND_US, signal_mask

PROTOCOL = "scalp-q3-r2"
TRAINING_WINDOWS = ("february", "march", "april", "may", "june", "july", "august", "september", "october")
# Reserved or consumed elsewhere (DATA-EXPOSURE-scalp-q3-r2.md §3): never readable by this protocol.
FORBIDDEN_WINDOWS = ("january", "december-2024", "november", "december", "fwd-okx-w0", "fwd-okx-w1", "fwd-okx-w2", "m2409", "m2410", "m2411", "w2411a", "w2411b")
SYMBOLS = sc.R8_SYMBOLS
MAJORS = {"BTCUSDT", "ETHUSDT", "SOLUSDT", "XRPUSDT", "DOGEUSDT", "BNBUSDT", "ADAUSDT", "LTCUSDT", "LINKUSDT", "AVAXUSDT"}
FIRST_HALF = ("february", "march", "april", "may")
STRESS_LATENCY_US = 1_000_000
MIN_AVAILABLE_KB = 2 * 1024 * 1024
MIN_DISK_BYTES = 15 * 1024**3
HISTORICAL_SEEDS = 10
BASE = Policy("E1", "Z3")
STRESS = Policy("E1", "Z3", latency_us=STRESS_LATENCY_US)
FIELDS = tuple(name for name, _ in OUTCOME_FIELDS)
REMAINDER_NAMES = tuple(name for name, _ in REMAINDER_FIELDS)  # AMENDMENT-2: present in tables prepared after 2026-10-14 14:30Z


def check_phase(phase: str, windows: tuple[str, ...]) -> None:
    """§2: the training windows only; any reserved, consumed-elsewhere or unknown window is refused."""
    if phase != "training":
        raise SystemExit(f"{PROTOCOL} reads the training windows only (phase {phase!r} refused)")
    for window in windows:
        if window in FORBIDDEN_WINDOWS or window not in TRAINING_WINDOWS:
            raise SystemExit(f"{PROTOCOL}: window {window!r} is not a training window")


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def mem_available_kb() -> int:
    with open("/proc/meminfo") as handle:
        for line in handle:
            if line.startswith("MemAvailable:"):
                return int(line.split()[1])
    return 0


def guard(path: Path) -> None:
    """§10.2: refuse to start (or continue) below 2 GB of available memory or 15 GB of free disk."""
    avail = mem_available_kb()
    free = shutil.disk_usage(path).free
    if avail < MIN_AVAILABLE_KB or free < MIN_DISK_BYTES:
        raise SystemExit(f"resource guard: MemAvailable {avail // 1024} MB, disk free {free // 1024**3} GB")


def load_funding(funding_dir: Path, symbol: str, start_s: int, end_s: int) -> Funding:
    months = sorted({datetime.fromtimestamp(t, tz=timezone.utc).strftime("%Y-%m") for t in (start_s - 86400, start_s, end_s, end_s + 86400)})
    ts, rate, iv = [], [], []
    for month in months:
        path = funding_dir / "funding" / symbol / f"{symbol}-fundingRate-{month}.npz"
        if not path.exists():
            continue
        with np.load(path) as z:
            ts.append(z["ts_ms"].astype(np.int64) * 1000)
            rate.append(z["rate"].astype(np.float64))
            iv.append(z["interval_hours"].astype(np.float64))
    if not ts:
        return Funding(np.zeros(0, dtype=np.int64), np.zeros(0), np.zeros(0), start_s, end_s, 0.0)
    rates = np.concatenate(rate)
    return Funding(np.concatenate(ts), rates, np.concatenate(iv), start_s, end_s, float(np.nanmax(np.abs(rates))) if rates.size else 0.0)


# ----------------------------------------------------------------------------- prepare (workers)

_SERIES: list = []
_ARGS: dict = {}


def _init(series: list, args: dict) -> None:
    global _SERIES, _ARGS
    _SERIES, _ARGS = series, args


def candidate_detail(data, cols: dict, union: np.ndarray, policy: Policy) -> dict:
    f = data.features
    start_us = data.start_s * SECOND_US
    out = {"d_s": union, "d_z_cal": -f["band_dev_900"][union] / f["sigma_900"][union], "d_z_live": -f["band_dev_900"][union] / f["sigma_900_live"][union],
           "d_stale": f["stale_900"][union], "d_quote_age": f["quote_age"][union]}
    m = union.size
    for name in ("z_mae", "z_mfe", "s_mae", "s_mfe", "pass_thr", "bid_at_fill", "fill_delay_s"):
        out[f"d_{name}"] = np.full(m, np.nan)
    offsets, lengths, flat = np.zeros(m, dtype=np.int64), np.zeros(m, dtype=np.int64), []
    total = 0
    for k, s in enumerate(union.tolist()):
        if cols["status"][s] != FILLED:
            continue
        entry, fill_ts = float(cols["price"][s]), int(cols["fill_ts"][s])
        out["d_z_mae"][k], out["d_z_mfe"][k] = excursions(data, fill_ts, int(cols["z_exit_ts"][s]), entry)
        out["d_s_mae"][k], out["d_s_mfe"][k] = excursions(data, fill_ts, int(cols["s_exit_ts"][s]), entry)
        out["d_pass_thr"][k] = passive_through_qty(data, policy, fill_ts, int(cols["z_exit_ts"][s]), int(cols["z_reason"][s]))
        iq = int(np.searchsorted(data.qts, fill_ts, side="right")) - 1
        out["d_bid_at_fill"][k] = float(data.bid[iq]) if iq >= 0 else np.nan
        out["d_fill_delay_s"][k] = (fill_ts - (start_us + (s + 1) * SECOND_US + policy.latency_us)) / 1e6
        marks = path_marks(data, fill_ts, int(cols["z_exit_ts"][s]))
        offsets[k], lengths[k] = total, marks.size
        total += marks.size
        flat.append(marks)
    out["d_path_off"], out["d_path_len"] = offsets, lengths
    out["d_path"] = np.concatenate(flat) if flat else np.zeros(0)
    return out


def prepare_one(index: int) -> dict:
    started = time.time()
    if mem_available_kb() < MIN_AVAILABLE_KB:
        return {"index": index, "error": "memory guard"}
    series = _SERIES[index]
    out_path = Path(_ARGS["out"]) / "series" / f"{index:03d}.npz"
    if out_path.exists():
        return {"index": index, "skipped": True}
    _, symbol, window = series.name.split(":")
    data = series.data(index)
    funding = load_funding(Path(_ARGS["funding_dir"]), symbol, series.start_s, series.end_s)
    cols = compute_outcomes(data, BASE, None, funding, remainder=True)
    f = data.features
    payload: dict = {f"t_{name}": cols[name] for name in FIELDS + REMAINDER_NAMES}
    signals = {c: np.flatnonzero(signal_mask(CONFIG_RULES[c], f, 1)).astype(np.int64) for c in CONFIGS}
    for c, sig in signals.items():
        payload[f"sig_{c}"] = sig
    union = np.unique(np.concatenate(list(signals.values()))) if signals else np.zeros(0, dtype=np.int64)
    stress = compute_outcomes(data, STRESS, union, funding, remainder=True)
    payload["x_s"] = union
    for name in FIELDS + REMAINDER_NAMES:
        payload[f"x_{name}"] = stress[name]
    payload.update(candidate_detail(data, cols, union, BASE))
    hours = hour_of(series.start_s, data.n)
    with np.errstate(invalid="ignore", divide="ignore"):
        valid = np.isfinite(f["mid"]) & (f["quote_age"] <= BASE.stale_s)
        noflow = ~(f["fi_60"] > 0.0)
        for c in CONFIGS:
            sigma = f[CONFIG_SIGMA[c]]
            pool = (f["band_dev_900"] < -1.5 * sigma) & noflow & valid
            if CONFIG_DEPTH[c]:
                pool &= f["di_1"] > 0.1
            pool_s = np.flatnonzero(pool)
            payload[f"pool_{c}_s"] = pool_s
            payload[f"pool_{c}_st"] = strata(hours[pool_s], -f["band_dev_900"][pool_s] / sigma[pool_s], f["stale_900"][pool_s])
    # Parity: the historical replay (`screen._replay_series` = run_policy + 10-seed hour-matched control) of the Z3 configurations.
    hist = {}
    for c in CONFIGS:
        part = sc._replay_series(data, f"{CONFIG_RULES[c]}|long|E1|Z3", HISTORICAL_SEEDS)
        payload[f"rp_{c}"] = part["trades"]
        payload[f"hist_{c}"] = np.asarray(part["control_sums"], dtype=np.float64)
        hist[c] = part["counters"]
    meta = {"index": index, "name": series.name, "symbol": symbol, "window": window, "start_s": series.start_s, "end_s": series.end_s, "n": int(data.n),
            "step": series.instrument.quantity_step, "min_qty": series.instrument.minimum_quantity, "min_notional": series.min_notional,
            "funding_settlements": int(((funding.ts_us >= series.start_s * SECOND_US) & (funding.ts_us < series.end_s * SECOND_US)).sum()),
            "funding_gaps": [[int(a), int(b)] for a, b in funding.gaps], "rp_counters": hist,
            "status_counts": {str(k): int(v) for k, v in zip(*np.unique(cols["status"], return_counts=True))},
            "quiet_share": float((data.bars["n"] == 0).mean()), "elapsed_s": round(time.time() - started, 1)}
    payload["meta"] = np.frombuffer(json.dumps(meta).encode(), dtype=np.uint8)
    tmp = out_path.with_suffix(".tmp.npz")
    np.savez_compressed(tmp, **payload)
    tmp.rename(out_path)
    del data
    return meta


def load_series_list(tape_dir: Path, instruments: Path, windows: tuple[str, ...] | None = None) -> list:
    """Training windows by default; another window set only through `q3r2_c2.validation_windows_checked` (AMENDMENT-2)."""
    if windows is None:
        check_phase("training", TRAINING_WINDOWS)
        windows = TRAINING_WINDOWS
    series, missing = sc.load_binance_series(tape_dir, instruments, "training", symbols=SYMBOLS, windows=windows, depth=True)
    if missing:
        raise SystemExit(f"missing tape days: {missing[:10]}")
    return series


def prepare(args) -> int:
    out = Path(args.out)
    (out / "series").mkdir(parents=True, exist_ok=True)
    guard(out)
    series = load_series_list(Path(args.tape_dir), Path(args.instruments), getattr(args, "windows", None))
    manifest = {"protocol": PROTOCOL, "started_at": _now(), "series": [s.name for s in series], "tape_dir": args.tape_dir, "instruments": args.instruments,
                "instruments_sha256": hashlib.sha256(Path(args.instruments).read_bytes()).hexdigest(), "funding_dir": args.funding_dir,
                "prereg_sha256": hashlib.sha256(Path(args.prereg).read_bytes()).hexdigest() if args.prereg else None, "code": sc.code_identity(), "workers": args.workers}
    (out / "prepare-manifest.json").write_text(json.dumps(manifest, indent=1) + "\n")
    shared = {"out": str(out), "funding_dir": args.funding_dir}
    _init(series, shared)
    todo = list(range(len(series)))
    if args.limit:
        todo = todo[:args.limit]
    metas, errors = [], []
    context = multiprocessing.get_context("fork")
    with context.Pool(max(1, min(args.workers, 2)), initializer=_init, initargs=(series, shared), maxtasksperchild=4) as pool:
        for done, meta in enumerate(pool.imap_unordered(prepare_one, todo), start=1):
            if "error" in meta:
                errors.append(meta)
                print(f"{_now()} STOP {meta}", file=sys.stderr, flush=True)
                pool.terminate()
                break
            metas.append(meta)
            print(f"{_now()} {done}/{len(todo)} {meta.get('name', meta['index'])} {meta.get('elapsed_s', 'skip')} s", file=sys.stderr, flush=True)
            avail, free = mem_available_kb(), shutil.disk_usage(out).free
            if avail < MIN_AVAILABLE_KB or free < MIN_DISK_BYTES:
                errors.append({"guard": f"MemAvailable {avail // 1024} MB, disk {free // 1024**3} GB"})
                print(f"{_now()} STOP resource guard {errors[-1]}", file=sys.stderr, flush=True)
                pool.terminate()
                break
    manifest["finished_at"] = _now()
    manifest["errors"] = errors
    (out / "prepare-manifest.json").write_text(json.dumps(manifest, indent=1) + "\n")
    return 1 if errors else 0


# ----------------------------------------------------------------------------- analyse (parent)


def _load(path: Path) -> tuple[dict, dict]:
    with np.load(path, allow_pickle=False) as z:
        payload = {key: z[key] for key in z.files}
    meta = json.loads(payload.pop("meta").tobytes().decode())
    return meta, payload


def _table(payload: dict, prefix: str) -> dict:
    return {name: payload[f"{prefix}_{name}"] for name in FIELDS + REMAINDER_NAMES if f"{prefix}_{name}" in payload}


def _full_stress(payload: dict, n: int) -> dict:
    """Stress outcomes (computed on the candidate seconds only) laid out as a full-length table."""
    out = {}
    for name, dtype in OUTCOME_FIELDS + tuple(item for item in REMAINDER_FIELDS if f"x_{item[0]}" in payload):
        arr = np.zeros(n, dtype=dtype)
        if np.issubdtype(np.dtype(dtype), np.floating):
            arr[:] = np.nan
        arr[payload["x_s"]] = payload[f"x_{name}"]
        out[name] = arr
    return out


def _orders(cols: dict, signal: np.ndarray, variant: str, n: int) -> np.ndarray:
    """Decision seconds at which the one-per-pair replay places an order (for the H1 strata)."""
    status = cols["status"]
    busy_col = cols["z_busy" if variant == "Z3" else "s_busy"]
    sig = signal[status[signal] != NONE]
    out, idx, busy = [], 0, -1
    while idx < sig.size:
        s = int(sig[idx])
        out.append(s)
        st = int(status[s])
        busy = s + 6 if st == CROSSED else s + 31 if st == UNFILLED else n if st == DEAD else int(busy_col[s])
        idx = int(np.searchsorted(sig, busy, side="left"))
    return np.asarray(out, dtype=np.int64)


def _net(cols: dict, filled: np.ndarray, variant: str, maker: float = 2.0, taker: float = 5.0, slip: float = 0.0) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    p = "z" if variant == "Z3" else "s"
    entry = cols["price"][filled]
    gross = (cols[f"{p}_exit"][filled] - entry) / entry * 1e4
    taker_exit = cols[f"{p}_reason"][filled] != PASSIVE
    fees = maker + np.where(taker_exit, taker, maker)
    return gross - fees - np.where(taker_exit, slip, 0.0), gross, fees


class Accumulator:
    """Per-day sums and counts of net bp, per seed."""

    def __init__(self, seeds: int, days: int) -> None:
        self.sum = np.zeros((seeds, days))
        self.cnt = np.zeros((seeds, days))

    def add(self, seed: int, day_idx: np.ndarray, net: np.ndarray) -> None:
        if net.size:
            np.add.at(self.sum[seed], day_idx, net)
            np.add.at(self.cnt[seed], day_idx, 1.0)

    def means(self) -> np.ndarray:
        with np.errstate(invalid="ignore", divide="ignore"):
            return self.sum.sum(axis=1) / self.cnt.sum(axis=1)


def analyse(args) -> int:
    out = Path(args.out)
    files = sorted((out / "series").glob("[0-9][0-9][0-9].npz"))
    prep = json.loads((out / "prepare-manifest.json").read_text())
    names = prep["series"]
    if len(files) != len(names):
        raise SystemExit(f"{len(files)} series files for {len(names)} series")
    by_window: dict[str, list[int]] = defaultdict(list)
    for index, name in enumerate(names):
        window = name.split(":")[2]
        check_phase("training", (window,))
        by_window[window].append(index)
    all_days = sorted({(sc._day_s(sc.BINANCE_WINDOWS[w][0]) // 86400) + k for w in TRAINING_WINDOWS for k in range(5)})
    day_index = {d: i for i, d in enumerate(all_days)}
    R, RP = args.seeds, args.portfolio_seeds
    keys = [(c, v) for c in CONFIGS for v in VARIANTS]
    shift_acc = {key: Accumulator(R, len(all_days)) for key in keys}
    band_acc = {key: Accumulator(R, len(all_days)) for key in keys}
    band_unmatched = {key: 0 for key in keys}
    hist_sums = {c: np.zeros((HISTORICAL_SEEDS, 2)) for c in CONFIGS}
    cand: dict = {key: defaultdict(list) for key in keys}
    stress_cand: dict = {key: defaultdict(list) for key in keys}
    counters = {key: defaultdict(int) for key in keys}
    parity = {"rp_vs_table_mismatch_series": [], "rp_counters_mismatch": []}
    rp_trades = {c: [] for c in CONFIGS}
    ctrl_equity = {key: np.full(RP, Book().capital) for key in keys}
    ctrl_pnl_daily = {key: defaultdict(lambda: np.zeros(RP)) for key in keys}
    ctrl_trades = {key: np.zeros(RP) for key in keys}
    portfolios = {(c, v, sc_name): {"equity": Book().capital, "records": [], "items": [], "refusals": defaultdict(int), "orders": 0, "partial": 0, "partial_unfillable": 0, "daily": defaultdict(float), "window_marks": []}
                  for c in CONFIGS for v in VARIANTS for sc_name in ("base", "pessimistic", "stress")}
    metas = []
    started = time.time()
    for window in TRAINING_WINDOWS:
        guard(out)
        loaded = []
        for index in by_window[window]:
            meta, payload = _load(out / "series" / f"{index:03d}.npz")
            metas.append(meta)
            loaded.append((index, meta, payload, _table(payload, "t")))
        for index, meta, payload, cols in loaded:
            n, start_s = meta["n"], meta["start_s"]
            stress_cols = _full_stress(payload, n)
            detail_s = payload["d_s"]
            for c in CONFIGS:
                sig = payload[f"sig_{c}"]
                hist_sums[c] += payload[f"hist_{c}"]
                rp = payload[f"rp_{c}"]
                rp_trades[c].append(rp)
                for v in VARIANTS:
                    filled, cnt = standalone(cols, sig, v, n, BASE, count_decisions=True)
                    for k2, val in cnt.items():
                        counters[(c, v)][k2] += val
                    rows = trade_rows(cols, filled, v, index, start_s, BASE)
                    if v == "Z3":
                        mine = np.zeros(filled.size, dtype=TRADE_DTYPE)
                        for fld in TRADE_DTYPE.names:
                            mine[fld] = 1 if fld == "side" else rows[fld]
                        if mine.size != rp.size or any(not np.array_equal(mine[fld], rp[fld]) for fld in TRADE_DTYPE.names):
                            parity["rp_vs_table_mismatch_series"].append(meta["name"] + ":" + c)
                        if dict(cnt) != meta["rp_counters"][c]:
                            parity["rp_counters_mismatch"].append({"series": meta["name"], "config": c, "table": dict(cnt), "run_policy": meta["rp_counters"][c]})
                    pos = np.searchsorted(detail_s, filled)
                    p = "z" if v == "Z3" else "s"
                    rows.update({"symbol": np.array([meta["symbol"]] * filled.size), "window": np.array([window] * filled.size),
                                 "day": np.array([day_index[(start_s + s) // 86400] for s in filled.tolist()], dtype=np.int64),
                                 "mae": payload[f"d_{p}_mae"][pos], "mfe": payload[f"d_{p}_mfe"][pos], "z_cal": payload["d_z_cal"][pos], "z_live": payload["d_z_live"][pos],
                                 "stale": payload["d_stale"][pos], "quote_age": payload["d_quote_age"][pos], "fill_delay_s": payload["d_fill_delay_s"][pos],
                                 "bid_at_fill": payload["d_bid_at_fill"][pos], "pass_thr": payload["d_pass_thr"][pos],
                                 "z_net_same_order": _net(cols, filled, "Z3")[0]})
                    for k2, val in rows.items():
                        cand[(c, v)][k2].append(val)
                    sfilled, scnt = standalone(stress_cols, sig, v, n, STRESS, count_decisions=True)
                    srows = trade_rows(stress_cols, sfilled, v, index, start_s, STRESS)
                    srows["day"] = np.array([day_index[(start_s + s) // 86400] for s in sfilled.tolist()], dtype=np.int64)
                    srows["symbol"] = np.array([meta["symbol"]] * sfilled.size)
                    for k2, val in srows.items():
                        stress_cand[(c, v)][k2].append(val)
        # Controls: episode shift (signal level, R seeds; portfolio, RP seeds) and band state (R seeds).
        pools = {index: hour_pools(meta["start_s"], meta["n"]) for index, meta, _, _ in loaded}
        days_of = {index: ((meta["start_s"] + np.arange(meta["n"], dtype=np.int64)) // 86400) for index, meta, _, _ in loaded}
        day_map = {index: np.array([day_index.get(int(d), 0) for d in np.unique(days_of[index])]) for index in days_of}
        first_day = {index: int(np.unique(days_of[index])[0]) for index in days_of}

        def day_idx(index: int, secs: np.ndarray) -> np.ndarray:
            return day_map[index][(days_of[index][secs] - first_day[index])]

        orders_strata = {}
        for index, meta, payload, cols in loaded:
            for c in CONFIGS:
                z_key = "d_z_cal" if CONFIG_SIGMA[c] == "sigma_900" else "d_z_live"
                for v in VARIANTS:
                    orders = _orders(cols, payload[f"sig_{c}"], v, meta["n"])
                    pos = np.searchsorted(payload["d_s"], orders)
                    hours = (meta["start_s"] + orders) // 3600 % 24
                    orders_strata[(index, c, v)] = strata(hours, payload[z_key][pos], payload["d_stale"][pos])
        for ci, c in enumerate(CONFIGS):
            for seed in range(R):
                shifted = {}
                for index, meta, payload, cols in loaded:
                    sh = shift_episodes(payload[f"sig_{c}"], meta["start_s"], meta["n"], rng_for(1, ci, seed, index), pools[index])
                    sh = sh[cols["status"][sh] != NONE] if sh.size else sh
                    shifted[index] = sh
                    for v in VARIANTS:
                        filled, _ = standalone(cols, sh, v, meta["n"], BASE)
                        shift_acc[(c, v)].add(seed, day_idx(index, filled), _net(cols, filled, v)[0])
                        draws, unmatched = band_state_draws(payload[f"pool_{c}_s"], payload[f"pool_{c}_st"], orders_strata[(index, c, v)], rng_for(2, ci, VARIANTS.index(v), seed, index))
                        if seed == 0:
                            band_unmatched[(c, v)] += unmatched
                        draws = draws[cols["status"][draws] != NONE] if draws.size else draws
                        bfilled, _ = standalone(cols, draws, v, meta["n"], BASE)
                        band_acc[(c, v)].add(seed, day_idx(index, bfilled), _net(cols, bfilled, v)[0])
                if seed < RP:
                    streams = [Stream(meta["symbol"], meta["start_s"], meta["n"], meta["step"], meta["min_qty"], meta["min_notional"], cols, shifted[index], index)
                               for index, meta, payload, cols in loaded]
                    for v in VARIANTS:
                        res = run_window(streams, v, SCENARIOS["base"], Book(), float(ctrl_equity[(c, v)][seed]))
                        ctrl_equity[(c, v)][seed] = res.equity
                        ctrl_trades[(c, v)][seed] += res.trades
                        for d, val in res.daily.items():
                            ctrl_pnl_daily[(c, v)][int(d)][seed] += val
        # Candidate portfolios.
        for c in CONFIGS:
            for v in VARIANTS:
                for sc_name in ("base", "pessimistic", "stress"):
                    state = portfolios[(c, v, sc_name)]
                    costs = SCENARIOS["base" if sc_name == "base" else "pessimistic"]
                    streams = []
                    for index, meta, payload, cols in loaded:
                        table = _full_stress(payload, meta["n"]) if sc_name == "stress" else cols
                        sig = payload[f"sig_{c}"]
                        sig = sig[table["status"][sig] != NONE]
                        streams.append(Stream(meta["symbol"], meta["start_s"], meta["n"], meta["step"], meta["min_qty"], meta["min_notional"], table, sig, index))
                    book = Book(latency_us=STRESS_LATENCY_US) if sc_name == "stress" else Book()
                    equity0 = state["equity"]
                    res = run_window(streams, v, costs, book, equity0, record=True)
                    for rec in res.records:
                        payload = next(p for i, _, p, _ in loaded if i == rec["series_id"])
                        k = int(np.searchsorted(payload["d_s"], rec["s"]))
                        if sc_name != "stress" and k < payload["d_s"].size and payload["d_s"][k] == rec["s"]:
                            off, ln = int(payload["d_path_off"][k]), int(payload["d_path_len"][k])
                            rec["path"] = payload["d_path"][off:off + ln].tolist()
                            rec["pass_thr"] = float(payload["d_pass_thr"][k])
                        rec["window"] = window
                    state["records"].extend(res.records)
                    state["items"].extend(res.items)
                    state["window_marks"].append((window, equity0, res.equity))
                    state["equity"] = res.equity
                    state["orders"] += res.orders
                    state["partial"] += res.partial
                    state["partial_unfillable"] += res.partial_unfillable
                    for key2, val in res.refusals.items():
                        state["refusals"][key2] += val
                    for d, val in res.daily.items():
                        state["daily"][int(d)] += val
        print(f"{_now()} window {window} done ({time.time() - started:.0f} s)", file=sys.stderr, flush=True)
        del loaded
    results = summarise(args, out, metas, cand, stress_cand, counters, parity, rp_trades, hist_sums, shift_acc, band_acc, band_unmatched, ctrl_equity, ctrl_pnl_daily,
                        ctrl_trades, portfolios, all_days)
    results["elapsed_seconds"] = round(time.time() - started, 1)
    (out / "results.json").write_text(json.dumps(results, indent=1, default=_json_default) + "\n")
    print(json.dumps({"verdict": results["verdict"]["verdict"], "parity_ok": results["parity"]["ok"]}))
    return 0


def _json_default(value):
    if isinstance(value, np.integer):
        return int(value)
    if isinstance(value, np.floating):
        return None if not np.isfinite(value) else float(value)
    if isinstance(value, np.bool_):
        return bool(value)
    if isinstance(value, np.ndarray):
        return value.tolist()
    raise TypeError(type(value))


def _cat(parts: dict) -> dict:
    return {k: np.concatenate(v) if v else np.zeros(0) for k, v in parts.items()}


def _fv(x):
    x = float(x)
    return x if np.isfinite(x) else None


def _group_sums(keys: np.ndarray, net: np.ndarray) -> dict:
    out = {}
    for key in np.unique(keys):
        sel = keys == key
        out[str(key)] = {"trades": int(sel.sum()), "mean_bp": float(net[sel].mean()), "sum_bp": float(net[sel].sum())}
    return out


def portfolio_metrics(state: dict, all_days: list[int], capital: float) -> dict:
    recs = state["records"]
    pnl = np.array([r["pnl"] for r in recs]) if recs else np.zeros(0)
    exit_ts = np.array([r["exit_ts"] for r in recs], dtype=np.int64) if recs else np.zeros(0, dtype=np.int64)
    order = np.argsort(exit_ts, kind="stable")
    realised = capital + np.concatenate([[0.0], np.cumsum(pnl[order])])
    dd_real, dd_real_rel = max_drawdown(realised)
    # Mark-to-market equity at every minute of every window (bid proxy; entry fee counted while open).
    mtm_curve, exposure_n, gross_n, engaged = [], [], [], []
    equity_hourly = []
    cash_before = capital
    for window, eq0, eq1 in state["window_marks"]:
        w_recs = [r for r in recs if r["window"] == window]
        first, last = sc.BINANCE_WINDOWS[window]
        t0 = sc._day_s(first) * SECOND_US
        t1 = (sc._day_s(last) + 86400 + 7200) * SECOND_US
        grid = np.arange(t0, t1, 60 * SECOND_US, dtype=np.int64)
        eq = np.full(grid.size, eq0)
        openn = np.zeros(grid.size)
        gross = np.zeros(grid.size)
        for r in w_recs:
            done = grid >= r["exit_ts"]
            eq[done] += r["pnl"]
            live = (grid >= r["fill_ts"]) & ~done
            if live.any():
                idx = (grid[live] - r["fill_ts"]) // (60 * SECOND_US) - 1
                path = np.asarray(r.get("path") or [], dtype=np.float64)
                mark = np.where((idx >= 0) & (idx < path.size), path[np.clip(idx, 0, max(path.size - 1, 0))] if path.size else r["entry"], r["entry"])
                mark = np.where(np.isfinite(mark), mark, r["entry"])
                eq[live] += r["qty"] * (mark - r["entry"]) - r["fee_in"]
                openn[live] += 1
                gross[live] += r["notional"]
        items = [it for it in state["items"] if t0 <= it[0] < t1]
        eng = np.zeros(grid.size)
        for dec, end, notional, _ in items:
            eng[(grid >= dec) & (grid < end)] += notional
        mtm_curve.append(eq)
        exposure_n.append(openn)
        gross_n.append(gross)
        engaged.append(eng)
        equity_hourly.extend([(int(g // SECOND_US), float(e)) for g, e in zip(grid[::60], eq[::60])])
        cash_before = eq1
    mtm = np.concatenate(mtm_curve) if mtm_curve else np.array([capital])
    dd_mtm, dd_mtm_rel = max_drawdown(np.concatenate([[capital], mtm]))
    openn = np.concatenate(exposure_n) if exposure_n else np.zeros(1)
    grossn = np.concatenate(gross_n) if gross_n else np.zeros(1)
    eng = np.concatenate(engaged) if engaged else np.zeros(1)
    daily = state["daily"]
    worst_day = min(daily.values()) if daily else 0.0
    nominal = np.array([r["notional"] for r in recs]) * Book().risk_unit(SCENARIOS["base"]) if recs else np.zeros(0)
    ratio = (-pnl / nominal) if recs else np.zeros(0)
    losing = pnl[order] < 0
    from .q3r2_stats import longest_streak
    sorted_pnl = np.sort(pnl)
    k = max(1, int(round(0.05 * pnl.size))) if pnl.size else 0
    stops = [r for r in recs if r["reason"] == STOP]
    return {
        "final_equity": float(state["equity"]), "pnl_usdt": float(state["equity"] - capital), "trades": int(len(recs)), "orders": int(state["orders"]),
        "refusals": dict(state["refusals"]), "partial_fills": int(state["partial"]), "partial_unfillable": int(state["partial_unfillable"]),
        "fees_usdt": float(sum(r["fee_in"] + r["fee_out"] for r in recs)), "slippage_usdt": float(sum(r["slip"] for r in recs)), "funding_usdt": float(sum(r["funding"] for r in recs)),
        "funding_missing_trades": int(sum(r["fund_missing"] for r in recs)),
        "max_dd_mtm_usdt": dd_mtm, "max_dd_mtm_rel": dd_mtm_rel, "max_dd_realised_usdt": dd_real, "max_dd_realised_rel": dd_real_rel,
        "worst_trade_usdt": _fv(pnl.min()) if pnl.size else None, "best_trade_usdt": _fv(pnl.max()) if pnl.size else None,
        "worst_trade_risk_multiple": _fv(ratio.max()) if ratio.size else None, "loss_p95_usdt": _fv(np.quantile(-pnl[pnl < 0], 0.95)) if (pnl < 0).any() else None,
        "loss_p99_usdt": _fv(np.quantile(-pnl[pnl < 0], 0.99)) if (pnl < 0).any() else None,
        "worst_day_usdt": float(worst_day), "best_day_usdt": float(max(daily.values())) if daily else 0.0, "days_negative": int(sum(1 for x in daily.values() if x < 0)), "days": len(daily),
        "longest_loss_streak": longest_streak(losing), "top5_sum_usdt": float(sorted_pnl[-k:].sum()) if k else 0.0, "bottom5_sum_usdt": float(sorted_pnl[:k].sum()) if k else 0.0,
        "without_top5_usdt": float(sorted_pnl[:-k].sum()) if k else 0.0,
        "mean_open_positions": float(openn.mean()), "max_open_positions": int(openn.max()), "max_gross_usdt": float(grossn.max()), "mean_gross_usdt": float(grossn.mean()),
        "max_engaged_usdt": float(eng.max()), "mean_engaged_usdt": float(eng.mean()), "min_available_usdt": float((mtm - eng).min()) if mtm.size == eng.size else None,
        "mean_notional_usdt": _fv(np.mean([r["notional"] for r in recs])) if recs else None,
        "stops": len(stops), "stop_pnl_usdt": float(sum(r["pnl"] for r in stops)),
        "passive_thr_short": int(sum(1 for r in recs if r["reason"] == PASSIVE and r.get("pass_thr") is not None and np.isfinite(r["pass_thr"]) and r["pass_thr"] < r["qty"])),
        "passive_exits": int(sum(1 for r in recs if r["reason"] == PASSIVE)),
        "equity_hourly": equity_hourly, "daily_usdt": {str(k2): float(v2) for k2, v2 in sorted(daily.items())},
    }


def summarise(args, out, metas, cand, stress_cand, counters, parity, rp_trades, hist_sums, shift_acc, band_acc, band_unmatched, ctrl_equity, ctrl_pnl_daily, ctrl_trades,
              portfolios, all_days) -> dict:
    capital = Book().capital
    res: dict = {"protocol": PROTOCOL, "generated_at": _now(), "seeds": args.seeds, "portfolio_seeds": args.portfolio_seeds, "bootstrap_resamples": BOOT_RESAMPLES,
                 "code": sc.code_identity(), "series": len(metas), "funding_gaps": {m["name"]: m["funding_gaps"] for m in metas if m["funding_gaps"]},
                 "quiet_share": {m["name"]: m["quiet_share"] for m in metas}}
    # Parity with q3x.
    q3x = Path(args.q3x_dir) / "screen-training-outcomes"
    q3x_doc = json.loads((Path(args.q3x_dir) / "screen-training.json").read_text())
    q3x_rows = {row["id"]: row for row in q3x_doc["rows"]}
    checks = {}
    for c in CONFIGS:
        identifier = f"{CONFIG_RULES[c]}|long|E1|Z3"
        mine = np.concatenate(rp_trades[c]) if rp_trades[c] else np.zeros(0, dtype=TRADE_DTYPE)
        mine = mine[np.lexsort((mine["decision_s"], mine["series"]))]  # q3x order: series id, then time
        table = _cat(cand[(c, "Z3")])
        item = {"trades": int(mine.size), "mean_net_bp": _fv(mine["net_bp"].mean()) if mine.size else None, "table_trades": int(table["net_bp"].size),
                "table_equals_run_policy": bool(table["net_bp"].size == mine.size and np.array_equal(np.sort(table["net_bp"]), np.sort(mine["net_bp"])))}
        control_means = [s / k for s, k in hist_sums[c] if k > 0]
        item["historical_control_median_bp"] = _fv(np.median(control_means)) if control_means else None
        path = q3x / (identifier.replace("|", "__") + ".npz")
        if path.exists():
            with np.load(path) as z:
                ref = z["trades"]
            item["q3x_trades"] = int(ref.size)
            item["identical_to_q3x"] = bool(ref.size == mine.size and all(np.array_equal(ref[f], mine[f]) for f in TRADE_DTYPE.names))
            item["q3x_control_median_bp"] = q3x_rows[identifier]["control"]["median"]
            # Same seeds and draws; only the order of the floating-point sums differs (windows outer here, series outer in q3x).
            item["control_identical_to_q3x"] = bool(np.isclose(item["historical_control_median_bp"], item["q3x_control_median_bp"], rtol=1e-9, atol=1e-9))
        checks[c] = item
    parity["configs"] = checks
    parity["ok"] = (not parity["rp_vs_table_mismatch_series"] and not parity["rp_counters_mismatch"]
                    and all(v.get("identical_to_q3x", True) and v.get("control_identical_to_q3x", True) and v["table_equals_run_policy"] for v in checks.values())
                    and checks["C2"].get("q3x_trades") == 1130)
    res["parity"] = parity
    days = np.asarray(all_days)
    window_of_day = np.repeat(np.arange(len(TRAINING_WINDOWS)), 5)
    rows = {}
    for (c, v), parts in cand.items():
        t = _cat(parts)
        net, gross, fees = t["net_bp"], t["gross_bp"], t["fees_bp"]
        taker = t["exit_reason"] != PASSIVE
        net_pess = gross - 1.5 * fees - np.where(taker, 3.0, 0.0)
        net_fund = net - np.nan_to_num(t["fund_bp"], nan=0.0)
        st = _cat(stress_cand[(c, v)])
        st_net = st["gross_bp"] - 1.5 * st["fees_bp"] - np.where(st["exit_reason"] != PASSIVE, 3.0, 0.0) if st["gross_bp"].size else np.zeros(0)
        day_sum = np.bincount(t["day"].astype(np.int64), weights=net, minlength=len(all_days)) if net.size else np.zeros(len(all_days))
        day_cnt = np.bincount(t["day"].astype(np.int64), minlength=len(all_days)).astype(float) if net.size else np.zeros(len(all_days))
        cnt = dict(counters[(c, v)])
        row = {"id": f"{c}-{v}", "rule": CONFIG_RULES[c], "exit": "Z3" if v == "Z3" else "Z3-SL200", "eligible": v == "SL", "counters": cnt,
               "fill_rate": cnt["filled"] / cnt["orders"] if cnt.get("orders") else None, "signal": signal_summary(net, gross, fees),
               "signal_pessimistic": signal_summary(net_pess, gross, 1.5 * fees), "signal_with_funding": signal_summary(net_fund, gross, fees),
               "signal_stress": signal_summary(st_net, st["gross_bp"], 1.5 * st["fees_bp"]) if st_net.size else {"trades": 0},
               "stress_counters_trades": int(st_net.size), "mean_funding_bp": _fv(np.nanmean(t["fund_bp"])) if net.size else None,
               "tails": tails(net, t["exit_ts"]), "exit_reasons": {str(k): int(x) for k, x in zip(*np.unique(t["exit_reason"], return_counts=True))},
               "by_pair": _group_sums(t["symbol"], net), "by_window": _group_sums(t["window"], net),
               "majors_mean_bp": _fv(net[np.isin(t["symbol"], list(MAJORS))].mean()) if np.isin(t["symbol"], list(MAJORS)).any() else None,
               "midcaps_mean_bp": _fv(net[~np.isin(t["symbol"], list(MAJORS))].mean()) if (~np.isin(t["symbol"], list(MAJORS))).any() else None,
               "first_half_mean_bp": _fv(net[np.isin(t["window"], FIRST_HALF)].mean()) if np.isin(t["window"], FIRST_HALF).any() else None,
               "second_half_mean_bp": _fv(net[~np.isin(t["window"], FIRST_HALF)].mean()) if (~np.isin(t["window"], FIRST_HALF)).any() else None,
               "mae_quantiles_bp": {str(q): _fv(np.nanquantile(t["mae"], q / 100)) for q in (1, 5, 25, 50)} if net.size else {},
               "mfe_quantiles_bp": {str(q): _fv(np.nanquantile(t["mfe"], q / 100)) for q in (50, 75, 95, 99)} if net.size else {},
               "share_mae_below": {str(x): _fv((t["mae"] < -x).mean()) for x in (100, 200)} if net.size else {},
               "quote_age_at_decision_s": {str(q): _fv(np.nanquantile(t["quote_age"], q / 100)) for q in (50, 90, 99)} if net.size else {},
               "fill_delay_s": {str(q): _fv(np.nanquantile(t["fill_delay_s"], q / 100)) for q in (50, 90)} if net.size else {},
               "bid_move_to_fill_bp": {str(q): _fv(np.nanquantile((t["bid_at_fill"] / t["entry"] - 1) * 1e4, q / 100)) for q in (10, 50, 90)} if net.size else {},
               "day_sum": day_sum, "day_cnt": day_cnt}
        best_day = int(np.argmax(day_sum)) if net.size else 0
        row["without_best_day_sum_bp"] = _fv(day_sum.sum() - day_sum[best_day]) if net.size else None
        if v == "SL":
            stopped = t["exit_reason"] == STOP
            z_same = t["z_net_same_order"]
            row["stop"] = {"stops": int(stopped.sum()), "share": _fv(stopped.mean()) if net.size else None, "stopped_net_sum_bp": _fv(net[stopped].sum()),
                           "stopped_mean_bp": _fv(net[stopped].mean()) if stopped.any() else None, "same_orders_z3_mean_bp": _fv(z_same[stopped].mean()) if stopped.any() else None,
                           "losses_avoided_bp": _fv(np.maximum(net[stopped] - z_same[stopped], 0).sum()), "gains_forgone_bp": _fv(np.maximum(z_same[stopped] - net[stopped], 0).sum()),
                           "winners_interrupted": int((stopped & (z_same > 0)).sum()), "winners_interrupted_z3_sum_bp": _fv(z_same[stopped & (z_same > 0)].sum()),
                           "slippage_bp": {str(q): _fv(np.nanquantile(t["slip_bp"][stopped], q / 100)) for q in (50, 90, 99)} if stopped.any() else {},
                           "max_slippage_bp": _fv(np.nanmax(t["slip_bp"][stopped])) if stopped.any() else None, "worst_stopped_bp": _fv(net[stopped].min()) if stopped.any() else None}
        # Controls.
        sh = shift_acc[(c, v)]
        sh_means = sh.means()
        ctrl_day_sum, ctrl_day_cnt = sh.sum.sum(axis=0), sh.cnt.sum(axis=0)
        row["_ctrl"] = (ctrl_day_sum, ctrl_day_cnt)
        mean_net = row["signal"]["mean_net_bp"]
        row["control_shift"] = {"seeds": int(sh_means.size), "mean_bp": _fv(np.nanmean(sh_means)), "median_bp": _fv(np.nanmedian(sh_means)), "p95_bp": _fv(np.nanquantile(sh_means, 0.95)),
                                "trades_per_seed": _fv(sh.cnt.sum(axis=1).mean()), "p_value": p_value(mean_net, sh_means) if mean_net is not None else None,
                                "surplus_day_blocks": bootstrap_surplus(day_sum, day_cnt, ctrl_day_sum, ctrl_day_cnt),
                                "surplus_window_blocks": bootstrap_surplus(day_sum, day_cnt, ctrl_day_sum, ctrl_day_cnt, blocks=window_of_day)}
        bd = band_acc[(c, v)]
        bd_means = bd.means()
        row["control_band_state"] = {"seeds": int(bd_means.size), "mean_bp": _fv(np.nanmean(bd_means)), "median_bp": _fv(np.nanmedian(bd_means)), "p95_bp": _fv(np.nanquantile(bd_means, 0.95)),
                                     "trades_per_seed": _fv(bd.cnt.sum(axis=1).mean()), "unmatched_orders_seed0": band_unmatched[(c, v)],
                                     "p_value": p_value(mean_net, bd_means) if mean_net is not None else None,
                                     "surplus_day_blocks": bootstrap_surplus(day_sum, day_cnt, bd.sum.sum(axis=0), bd.cnt.sum(axis=0))}
        if v == "Z3":
            control_means = [s / k for s, k in hist_sums[c] if k > 0]
            row["control_historical"] = {"seeds": len(control_means), "median_bp": _fv(np.median(control_means)) if control_means else None,
                                         "p95_bp": _fv(np.quantile(control_means, 0.95)) if control_means else None}
        # Portfolios.
        row["portfolio"] = {}
        for sc_name in ("base", "pessimistic", "stress"):
            row["portfolio"][sc_name] = portfolio_metrics(portfolios[(c, v, sc_name)], all_days, capital)
        pnl_ctrl = ctrl_equity[(c, v)] - capital
        cand_pnl = row["portfolio"]["base"]["pnl_usdt"]
        cand_daily = row["portfolio"]["base"]["daily_usdt"]
        ctrl_daily = ctrl_pnl_daily[(c, v)]
        day_keys = sorted(set(int(k2) for k2 in cand_daily) | set(ctrl_daily))
        diffs = np.array([cand_daily.get(str(k2), 0.0) - (ctrl_daily[k2].mean() if k2 in ctrl_daily else 0.0) for k2 in day_keys])
        row["portfolio_control"] = {"seeds": int(pnl_ctrl.size), "mean_pnl_usdt": _fv(pnl_ctrl.mean()), "median_pnl_usdt": _fv(np.median(pnl_ctrl)),
                                    "p95_pnl_usdt": _fv(np.quantile(pnl_ctrl, 0.95)), "mean_trades": _fv(ctrl_trades[(c, v)].mean()),
                                    "p_value": p_value(cand_pnl, pnl_ctrl), "surplus_usdt": _fv(cand_pnl - pnl_ctrl.mean()), "surplus_day_blocks": bootstrap_total(diffs)}
        rows[(c, v)] = row
    res["rows"] = {f"{c}-{v}": {k2: val for k2, val in row.items() if k2 not in ("day_sum", "day_cnt") and not k2.startswith("_")} for (c, v), row in rows.items()}
    res["comparisons"] = comparisons(rows, window_of_day)
    res["criteria"], res["verdict"] = criteria(rows)
    return res


def _diff_boot(a: dict, b: dict, ctrl: bool, blocks=None) -> dict:
    """Day-block bootstrap of (surplus or mean of a) − (surplus or mean of b), same day draws."""
    from .q3r2_stats import SEED_BASE
    m = a["day_sum"].size
    rng = np.random.default_rng(SEED_BASE + 7)
    draws = rng.integers(0, m, size=(BOOT_RESAMPLES, m))
    w = np.zeros((BOOT_RESAMPLES, m))
    np.add.at(w, (np.repeat(np.arange(BOOT_RESAMPLES), m), draws.ravel()), 1.0)

    def stat(row):
        with np.errstate(invalid="ignore", divide="ignore"):
            value = (w @ row["day_sum"]) / (w @ row["day_cnt"])
            point = row["day_sum"].sum() / row["day_cnt"].sum()
            if ctrl:
                acc = row["_ctrl"]
                value = value - (w @ acc[0]) / (w @ acc[1])
                point = point - acc[0].sum() / acc[1].sum()
        return value, point

    va, pa = stat(a)
    vb, pb = stat(b)
    d = (va - vb)[np.isfinite(va - vb)]
    return {"point": _fv(pa - pb), "ci90": [_fv(np.quantile(d, 0.05)), _fv(np.quantile(d, 0.95))] if d.size else [None, None]}


def comparisons(rows: dict, window_of_day) -> dict:
    """§11 answers: S7 against Q3 (H1, portfolio and signal surplus), active against calendar sigma (H2), cost of the stop (H3)."""
    out: dict = {"s7_vs_q3": {}, "active_vs_calendar": {}, "stop_cost": {}}

    def daily_diff(a: dict, b: dict) -> dict:
        da, db = a["portfolio"]["base"]["daily_usdt"], b["portfolio"]["base"]["daily_usdt"]
        keys = sorted(set(da) | set(db), key=int)
        return bootstrap_total(np.array([da.get(k, 0.0) - db.get(k, 0.0) for k in keys]))

    for v in VARIANTS:
        for s7, q3 in (("C1", "C2"), ("C3", "C4")):
            a, b = rows[(s7, v)], rows[(q3, v)]
            portfolio = daily_diff(a, b)
            lo, hi = portfolio["ci90"]
            verdict = "S7 better" if lo is not None and lo > 0 else "Q3 better" if hi is not None and hi < 0 else "no difference resolved"
            out["s7_vs_q3"][f"{s7}-{v} vs {q3}-{v}"] = {"portfolio_pnl_usdt": [a["portfolio"]["base"]["pnl_usdt"], b["portfolio"]["base"]["pnl_usdt"]],
                                                        "portfolio_daily_diff": portfolio, "signal_surplus_diff": _diff_boot(a, b, ctrl=True),
                                                        "mean_net_bp": [a["signal"]["mean_net_bp"], b["signal"]["mean_net_bp"]], "trades": [a["signal"]["trades"], b["signal"]["trades"]],
                                                        "reading": verdict}
        for active, calendar in (("C3", "C1"), ("C4", "C2")):
            a, b = rows[(active, v)], rows[(calendar, v)]
            diff = _diff_boot(a, b, ctrl=True)
            ta, tb = a["signal"]["trades"], b["signal"]["trades"]
            share = lambda r: (sum(1 for p in r["by_pair"].values() if p["sum_bp"] > 0) / max(len(r["by_pair"]), 1))
            win = lambda r: sum(1 for w in r["by_window"].values() if w["sum_bp"] > 0)
            not_worse = share(a) >= share(b) and win(a) >= win(b) and a["portfolio"]["base"]["pnl_usdt"] >= b["portfolio"]["base"]["pnl_usdt"]
            lo, hi = diff["ci90"]
            if lo is not None and lo > 0 and not_worse:
                reading = "improves robustness"
            elif tb and ta <= 0.7 * tb and lo is not None and lo <= 0 <= hi:
                reading = "selects fewer trades only"
            else:
                reading = "inconclusive"
            out["active_vs_calendar"][f"{active}-{v} vs {calendar}-{v}"] = {"surplus_diff": diff, "trades": [ta, tb], "trade_change": (ta - tb) / tb if tb else None,
                                                                             "pairs_positive_share": [share(a), share(b)], "windows_positive": [win(a), win(b)],
                                                                             "portfolio_pnl_usdt": [a["portfolio"]["base"]["pnl_usdt"], b["portfolio"]["base"]["pnl_usdt"]],
                                                                             "reading": reading}
    for c in CONFIGS:
        a, b = rows[(c, "SL")], rows[(c, "Z3")]
        out["stop_cost"][c] = {"mean_diff_bp": _diff_boot(a, b, ctrl=False), "mean_net_bp": [a["signal"]["mean_net_bp"], b["signal"]["mean_net_bp"]],
                               "portfolio_pnl_usdt": [a["portfolio"]["base"]["pnl_usdt"], b["portfolio"]["base"]["pnl_usdt"]],
                               "portfolio_dd_mtm_usdt": [a["portfolio"]["base"]["max_dd_mtm_usdt"], b["portfolio"]["base"]["max_dd_mtm_usdt"]],
                               "worst_trade_usdt": [a["portfolio"]["base"]["worst_trade_usdt"], b["portfolio"]["base"]["worst_trade_usdt"]],
                               "portfolio_daily_diff": daily_diff(a, b)}
    return out


def criteria(rows: dict) -> tuple[dict, dict]:
    crit: dict = {}
    fatal: dict = {}
    for c in CONFIGS:
        row = rows[(c, "SL")]
        sig = row["signal"]
        port = row["portfolio"]
        mean = sig["mean_net_bp"] if sig["trades"] else None
        shift = row["control_shift"]
        band = row["control_band_state"]
        pairs = row["by_pair"]
        pos_pairs = [p for p in pairs.values() if p["sum_bp"] > 0]
        pos_windows = sum(1 for w in row["by_window"].values() if w["sum_bp"] > 0)
        trimmed = row["tails"].get("trimmed_5_5_mean_bp")
        base = port["base"]
        worst_ratio = base["worst_trade_risk_multiple"]
        t = {
            "T1_trades": sig["trades"] >= 300,
            "T2_mean": mean is not None and mean >= 2.0,
            "T3_surplus": shift["p_value"] is not None and shift["p_value"] <= 0.01 and (shift["surplus_day_blocks"]["ci90"][0] or -1) > 0,
            "T4_flow": band["p_value"] is not None and band["p_value"] <= 0.05 and (band["surplus_day_blocks"]["point"] or -1) > 0,
            "T5_portfolio": base["pnl_usdt"] > 0 and base["trades"] >= 150 and (row["portfolio_control"]["p_value"] or 1) <= 0.05,
            "T6_pessimistic": (row["signal_pessimistic"].get("mean_net_bp") or -1) > 0 and port["pessimistic"]["pnl_usdt"] > 0,
            "T7_stress": (row["signal_stress"].get("mean_net_bp") or -1) > 0,
            "T8_consistency": len(pairs) >= 10 and len(pos_pairs) / max(len(pairs), 1) >= 0.60 and pos_windows >= 5,
            "T9_tails": trimmed is not None and trimmed > 0 and (row["without_best_day_sum_bp"] or -1) > 0,
            "T10_risk": base["max_dd_mtm_usdt"] <= 50.0 and (worst_ratio is None or worst_ratio <= 2.0) and base["worst_day_usdt"] >= -15.0,
            "T11_fill": (row["fill_rate"] or 0) >= 0.20,
        }
        f = {
            "F1_mean_nonpositive": mean is None or mean <= 0,
            "F2_portfolio_nonpositive": base["pnl_usdt"] <= 0,
            "F3_surplus_p_gt_0.10": shift["p_value"] is None or shift["p_value"] > 0.10,
            "F4_flow_surplus_nonpositive": (band["surplus_day_blocks"]["point"] or 0) <= 0,
            "F5_trimmed_nonpositive": trimmed is None or trimmed <= 0,
            "F6_portfolio_trades_lt_150": base["trades"] < 150,
        }
        crit[f"{c}-SL"] = {"criteria": t, "passes": all(t.values()), "fatal": f, "any_fatal": any(f.values()),
                           "values": {"pairs_with_trades": len(pairs), "pairs_positive": len(pos_pairs), "windows_positive": pos_windows, "trimmed_mean_bp": trimmed}}
        fatal[c] = any(f.values())
    passing = [k for k, v in crit.items() if v["passes"]]
    if passing:
        verdict = "RESEARCH_CANDIDATE"
    elif all(fatal.values()):
        verdict = "NO_GO"
    else:
        verdict = "INSUFFICIENT_EVIDENCE"
    return crit, {"verdict": verdict, "passing": passing, "forwarded_to_phase2": passing}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_exec.q3r2_run")
    sub = parser.add_subparsers(dest="command", required=True)
    p = sub.add_parser("prepare")
    p.add_argument("--tape-dir", required=True)
    p.add_argument("--instruments", required=True)
    p.add_argument("--funding-dir", required=True)
    p.add_argument("--out", required=True)
    p.add_argument("--workers", type=int, default=2)
    p.add_argument("--prereg", default="")
    p.add_argument("--limit", type=int, default=0)
    a = sub.add_parser("analyse")
    a.add_argument("--out", required=True)
    a.add_argument("--q3x-dir", required=True)
    a.add_argument("--seeds", type=int, default=1000)
    a.add_argument("--portfolio-seeds", type=int, default=1000)  # AMENDMENT-1
    args = parser.parse_args(argv)
    if args.command == "prepare":
        return prepare(args)
    return analyse(args)


if __name__ == "__main__":
    sys.exit(main())
