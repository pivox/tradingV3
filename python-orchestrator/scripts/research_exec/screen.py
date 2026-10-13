"""Driver of the tick-data scalping screen (part B of the 2026-10-12 pre-registration).

    python -m scripts.research_exec.screen screen --source captures --phase training --extract-dir X --out DIR
    python -m scripts.research_exec.screen screen --source binance --phase training --tape-dir T --instruments I --out DIR
    python -m scripts.research_exec.screen select --screen DIR/screen.json --out DIR/validation-list.json
    python -m scripts.research_exec.screen verdict --training T.json --validation V.json --out verdict.json
    python -m scripts.research_exec.screen budgets --screen V.json --out budgets.json

Sources: `captures` (the OKX and Hyperliquid extracts: first 12 h training, last 12 h
validation) and `binance` (aggTrades tape: March and July 2025 training, November 2025
validation, quotes proxied from the prints). Rules, policies, gates, selection and validation
follow the frozen pre-registration; constants below are those numbers.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import multiprocessing
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import capture
from .capture import QUOTE_DTYPE, TRADE_DTYPE, Instrument
from .scalp_engine import ENTRIES, EXITS, Policy, SeriesData, run_policy
from .scalp_engine import TRADE_DTYPE as ROW_DTYPE
from .stats import block_bootstrap_lower_bound
from .tick import SECOND_US, build_bars, compute_features, depth_rule_catalogue, family_of, rule_catalogue, signal_mask

CAPTURE_SPLIT_S = 12 * 3600
BINANCE_WINDOWS = {
    "february": ("2025-02-10", "2025-02-14"), "march": ("2025-03-10", "2025-03-14"), "april": ("2025-04-14", "2025-04-18"), "may": ("2025-05-12", "2025-05-16"),
    "june": ("2025-06-16", "2025-06-20"), "july": ("2025-07-14", "2025-07-18"), "august": ("2025-08-11", "2025-08-15"), "september": ("2025-09-15", "2025-09-19"),
    "october": ("2025-10-13", "2025-10-17"), "november": ("2025-11-10", "2025-11-14"), "december": ("2025-12-08", "2025-12-12"),
    "january": ("2025-01-13", "2025-01-17"), "december-2024": ("2024-12-09", "2024-12-13"),  # r8 validation and holdout, never read before r8
}
R5_FEATURES = ("mid", "quote_age", "band_dev_900", "sigma_900", "fi_60", "sigma_60")
BINANCE_PHASES = {"training": ("march", "july"), "validation": ("november",), "holdout": ("december",)}
# r1: the 2026-10-12 pre-registration (PREREGISTRATION.md); r2: PREREGISTRATION-r2.md (intraday holds).
R8_RULES, R8_BOTH_RULES = depth_rule_catalogue()
# r8: majors and mid-caps interleaved so that the by-series workers do not load three dense majors at once.
R8_SYMBOLS = ("BTCUSDT", "ARBUSDT", "ETHUSDT", "OPUSDT", "SOLUSDT", "APTUSDT", "XRPUSDT", "SUIUSDT", "DOGEUSDT", "NEARUSDT",
              "BNBUSDT", "TIAUSDT", "ADAUSDT", "SEIUSDT", "LTCUSDT", "INJUSDT", "LINKUSDT", "ATOMUSDT", "AVAXUSDT", "FILUSDT")
PROTOCOLS = {
    "r8": {"entries": ("E1",), "exits": ("Y1", "Y3", "Z3"), "sides": (1, -1), "training_windows": ("february", "march", "april", "may", "june", "july", "august", "september", "october"),
           "phases": {"validation": ("january",), "holdout": ("december-2024",)}, "rules": tuple(R8_RULES), "both_rules": tuple(R8_BOTH_RULES), "by_series": True, "depth": True, "symbols": R8_SYMBOLS,
           "gates": {"min_trades": {"captures": 300, "binance": 300}, "min_mean_bp": 2.0, "min_pf": 1.15, "control_margin_bp": 3.0, "min_series_share": 0.70, "fee_multiplier": 1.5, "min_fill_rate": 0.20}, "validation": {"min_trades": {"captures": 100, "binance": 100}, "min_pf": 1.05, "min_series_share": 0.5}},
    "r1": {"entries": ("E1", "E2"), "exits": ("X1", "X2", "X3", "X4"), "gates": {"min_trades": {"captures": 100, "binance": 300}, "min_mean_bp": 1.0, "min_pf": 1.15, "control_margin_bp": 2.0, "min_series_share": 0.75, "fee_multiplier": 1.5, "min_fill_rate": 0.20}, "validation": {"min_trades": {"captures": 50, "binance": 150}, "min_pf": 1.05, "min_series_share": 0.5}},
    "r6": {"entries": ("E1",), "exits": ("X2", "Y1", "Y3", "Z3"), "sides": (1, -1), "training_windows": ("february", "march", "april", "may", "june", "july", "august", "september", "october"),
           "rules": "catalogue+S7", "both_rules": ("S7-W300-K1.5", "S7-W300-K2.0", "S7-W900-K1.5", "S7-W900-K2.0"), "by_series": True,
           "symbols": ("ARBUSDT", "OPUSDT", "APTUSDT", "SUIUSDT", "NEARUSDT", "TIAUSDT", "SEIUSDT", "INJUSDT", "ATOMUSDT", "FILUSDT"),
           "gates": {"min_trades": {"captures": 300, "binance": 300}, "min_mean_bp": 2.0, "min_pf": 1.15, "control_margin_bp": 3.0, "min_series_share": 0.70, "fee_multiplier": 1.5, "min_fill_rate": 0.20}, "validation": {"min_trades": {"captures": 100, "binance": 100}, "min_pf": 1.05, "min_series_share": 0.5}},
    "r5": {"entries": ("E1",), "exits": ("Z3", "Z1"), "sides": (0,), "training_windows": ("march", "july", "may", "september", "february", "april", "june", "august", "october"),
           "rules": ("S7-W900-K2.0",), "features": R5_FEATURES, "by_series": True,
           "gates": {"min_trades": {"captures": 200, "binance": 200}, "min_mean_bp": 2.0, "min_pf": 1.15, "control_margin_bp": 3.0, "min_series_share": 0.70, "fee_multiplier": 1.5, "min_fill_rate": 0.20}, "validation": {"min_trades": {"captures": 50, "binance": 50}, "min_pf": 1.05, "min_series_share": 0.5}},
    "r4": {"entries": ("E1",), "exits": ("Z1", "Z3"), "sides": (0,), "training_windows": ("march", "july", "may", "september"),
           "rules": ("S7-W900-K1.5", "S7-W900-K2.0"),
           "gates": {"min_trades": {"captures": 200, "binance": 200}, "min_mean_bp": 2.0, "min_pf": 1.15, "control_margin_bp": 3.0, "min_series_share": 0.70, "fee_multiplier": 1.5, "min_fill_rate": 0.20}, "validation": {"min_trades": {"captures": 100, "binance": 100}, "min_pf": 1.05, "min_series_share": 0.5}},
    "r3": {"entries": ("E1",), "exits": ("Z1", "Z2", "Z3", "Z4"), "training_windows": ("march", "july", "may", "september"),
           "rules": ("S7-W300-K1.5", "S7-W300-K2.0", "S7-W900-K1.5", "S7-W900-K2.0", "S2-W60-K2.5", "S2-W60-K3.5", "S2-W300-K2.5", "S2-W300-K3.5"),
           "gates": {"min_trades": {"captures": 200, "binance": 200}, "min_mean_bp": 2.0, "min_pf": 1.15, "control_margin_bp": 3.0, "min_series_share": 0.70, "fee_multiplier": 1.5, "min_fill_rate": 0.20}, "validation": {"min_trades": {"captures": 100, "binance": 100}, "min_pf": 1.05, "min_series_share": 0.5}},
    "r2": {"entries": ("E1",), "exits": ("Y1", "Y2", "Y3", "Y4"), "gates": {"min_trades": {"captures": 200, "binance": 200}, "min_mean_bp": 2.0, "min_pf": 1.15, "control_margin_bp": 3.0, "min_series_share": 0.70, "fee_multiplier": 1.5, "min_fill_rate": 0.20}, "validation": {"min_trades": {"captures": 100, "binance": 100}, "min_pf": 1.05, "min_series_share": 0.5}},
}
BINANCE_SYMBOLS = ("BTCUSDT", "ETHUSDT", "SOLUSDT", "XRPUSDT", "DOGEUSDT", "BNBUSDT", "ADAUSDT", "LTCUSDT", "LINKUSDT", "AVAXUSDT")
SEEDS = {"captures": 20, "binance": 10}
GATES = dict(PROTOCOLS["r1"]["gates"])
VALIDATION = dict(PROTOCOLS["r1"]["validation"])


def use_protocol(name: str) -> None:
    """Select the gates and validation thresholds of a pre-registration (r1 default)."""
    GATES.clear()
    GATES.update(PROTOCOLS[name]["gates"])
    VALIDATION.clear()
    VALIDATION.update(PROTOCOLS[name]["validation"])
MAX_PER_FAMILY = 3
BUDGETS = (500, 1000, 2000, 5000, 10000, 20000, 50000, 100000)
REFERENCE_NOTIONAL = 250.0


def code_identity() -> dict[str, str]:
    package = Path(__file__).resolve().parent
    return {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in sorted(package.glob("*.py"))}


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _json_default(value):
    if isinstance(value, np.integer):
        return int(value)
    if isinstance(value, np.floating):
        return None if np.isnan(value) else float(value)
    if isinstance(value, np.bool_):
        return bool(value)
    if isinstance(value, np.ndarray):
        return value.tolist()
    raise TypeError(f"not serialisable: {type(value)}")


def _f(value) -> float | None:
    value = float(value)
    return value if np.isfinite(value) else None


# ----------------------------------------------------------------------------- configurations


SIDE_NAMES = {1: "long", -1: "short", 0: "both"}


def configuration_id(rule: str, side: int, entry: str, exit_: str) -> str:
    return f"{rule}|{SIDE_NAMES[side]}|{entry}|{exit_}"


def parse_configuration(identifier: str) -> tuple[str, int, str, str]:
    """`side` is +1 (long), -1 (short) or 0 (both: the long and the short streams pooled)."""
    rule, side, entry, exit_ = identifier.split("|")
    if side not in ("long", "short", "both") or entry not in ENTRIES or exit_ not in EXITS:
        raise ValueError(f"bad configuration {identifier}")
    return rule, {"long": 1, "short": -1, "both": 0}[side], entry, exit_


S7_RULES = ("S7-W300-K1.5", "S7-W300-K2.0", "S7-W900-K1.5", "S7-W900-K2.0")


def build_grid(book: bool, protocol: str = "r1") -> list[str]:
    spec = PROTOCOLS[protocol]
    rules = spec.get("rules") or rule_catalogue(book)
    if rules == "catalogue+S7":
        rules = tuple(rule_catalogue(book)) + S7_RULES
    sides = spec.get("sides", (1, -1))
    grid = [configuration_id(rule, side, entry, exit_) for rule in rules for side in sides for entry in spec["entries"] for exit_ in spec["exits"]]
    grid += [configuration_id(rule, 0, entry, exit_) for rule in spec.get("both_rules", ()) for entry in spec["entries"] for exit_ in spec["exits"]]
    return grid


# ----------------------------------------------------------------------------- series


class Series:
    """One series: arrays given eagerly (captures) or produced by `loader` when `data()` runs
    (Binance tape: ninety series of structured prints do not fit the host at once)."""

    def __init__(self, name: str, quotes: np.ndarray | None, trades: np.ndarray | None, instrument: Instrument, start_s: int, end_s: int, book: bool, min_notional: float, day_key: bool, loader=None, depth: dict | None = None) -> None:
        self.name, self.quotes, self.trades, self.instrument = name, quotes, trades, instrument
        self.start_s, self.end_s, self.book, self.min_notional, self.day_key = int(start_s), int(end_s), book, min_notional, day_key
        self.loader = loader
        self.depth = depth  # r8: bookDepth snapshots (`ts_s`, `bid`, `ask`), or None

    def data(self, series_id: int, keep_features: tuple[str, ...] | None = None) -> SeriesData:
        if self.quotes is None and self.loader is not None:
            loaded = self.loader()
            self.quotes, self.trades = loaded[0], loaded[1]
            if len(loaded) > 2:
                self.depth = loaded[2]
        bars = build_bars(self.quotes, self.trades, self.start_s, self.end_s)
        features = compute_features(bars, self.instrument, self.depth)
        if keep_features is not None:
            # Memory-light mode (r5): only the arrays the rule and the engine read are kept.
            features = {name: features[name] for name in keep_features}
            bars = {name: bars[name] for name in ("ts", "mid", "bid", "ask", "quote_age", "bid_size")}
        data = SeriesData(series_id, bars, features, self.quotes, self.trades, self.instrument, self.start_s)
        self.quotes = self.trades = None  # the engine keeps contiguous copies; free the structured arrays
        return data


def load_capture_series(extract_dir: Path, phase: str) -> list[Series]:
    series = []
    for venue in ("okx", "hl"):
        extracted = capture.load_extract(extract_dir / f"{venue}.npz")
        for symbol in extracted.quotes:
            quotes, trades = extracted.quotes[symbol], extracted.trades[symbol]
            first_s = -(-int(max(quotes["ts"][0], trades["ts"][0])) // SECOND_US)
            last_s = int(min(quotes["ts"][-1], trades["ts"][-1])) // SECOND_US
            split = first_s + CAPTURE_SPLIT_S
            start_s, end_s = (first_s, split) if phase == "training" else (split, last_s)
            instrument = extracted.instruments[symbol]
            price = float(np.median(quotes["bid"]))
            min_notional = 10.0 if venue == "hl" else instrument.minimum_notional(price)
            series.append(Series(f"{venue}:{symbol}", quotes, trades, instrument, start_s, end_s, True, min_notional, False))
    return series


def load_binance_instruments(path: Path) -> dict[str, tuple[Instrument, float]]:
    document = json.loads(path.read_text())
    out = {}
    for item in document["symbols"]:
        instrument = Instrument(item["symbol"], item["symbol"], float(item.get("contract_size", 1.0)), float(item["min_quantity"]), float(item["quantity_step"]), fixed_tick=float(item["tick_size"]))
        out[item["symbol"]] = (instrument, float(item.get("min_notional", 0.0)))
    return out


def proxy_quotes(trades: np.ndarray, tick: float, start_s: int, end_s: int) -> np.ndarray:
    """One quote per second from the prints: bid = last print whose taker sold, ask = last print
    whose taker bought, forced one tick apart (the more recent print wins) when they cross."""
    n = end_s - start_s
    sec = trades["ts"] // SECOND_US - start_s
    keep = (sec >= 0) & (sec < n)
    sec, price, buy, ts = sec[keep], trades["price"][keep], trades["taker_buy"][keep].astype(bool), trades["ts"][keep]
    bid = np.full(n, np.nan)
    ask = np.full(n, np.nan)
    bid_ts = np.full(n, -1, dtype=np.int64)
    ask_ts = np.full(n, -1, dtype=np.int64)
    bid[sec[~buy]] = price[~buy]
    bid_ts[sec[~buy]] = ts[~buy]
    ask[sec[buy]] = price[buy]
    ask_ts[sec[buy]] = ts[buy]

    def ffill(values, fill):
        idx = np.where(np.isfinite(values) if values.dtype.kind == "f" else values >= 0, np.arange(n), -1)
        idx = np.maximum.accumulate(idx)
        out = np.where(idx >= 0, values[np.maximum(idx, 0)], fill)
        return out

    bid, ask = ffill(bid, np.nan), ffill(ask, np.nan)
    bid_ts, ask_ts = ffill(bid_ts, -1), ffill(ask_ts, -1)
    crossed = np.isfinite(bid) & np.isfinite(ask) & (bid >= ask - tick * 1e-6)
    newer_bid = bid_ts >= ask_ts
    ask = np.where(crossed & newer_bid, bid + tick, ask)
    bid = np.where(crossed & ~newer_bid, ask - tick, bid)
    valid = np.isfinite(bid) & np.isfinite(ask)
    quotes = np.zeros(int(valid.sum()), dtype=QUOTE_DTYPE)
    end_us = (start_s + np.flatnonzero(valid) + 1) * SECOND_US - 1
    quotes["ts"], quotes["recv"] = end_us, end_us
    quotes["bid"], quotes["ask"] = bid[valid], ask[valid]
    quotes["bid_size"], quotes["ask_size"] = np.nan, np.nan
    return quotes


def _day_s(date: str) -> int:
    return int(datetime.strptime(date, "%Y-%m-%d").replace(tzinfo=timezone.utc).timestamp())


def load_binance_series(tape_dir: Path, instruments_path: Path, phase: str, symbols: tuple[str, ...] = BINANCE_SYMBOLS, windows: tuple[str, ...] | None = None, depth: bool = False) -> tuple[list[Series], list[str]]:
    """`tape_dir` holds the training and validation windows; the holdout window is read from
    the sibling holdout directory when `phase == "holdout"`. With `depth`, the bookDepth npz of
    each day (`depth/<symbol>/<symbol>-<date>.npz`) is loaded with the prints (r8)."""
    instruments = load_binance_instruments(instruments_path)
    series, missing = [], []
    for symbol in symbols:
        for window in windows or BINANCE_PHASES[phase]:
            first, last = BINANCE_WINDOWS[window]
            start_s, end_s = _day_s(first), _day_s(last) + 86400
            paths, depth_paths = [], []
            for day in range((end_s - start_s) // 86400):
                date = datetime.fromtimestamp(start_s + day * 86400, tz=timezone.utc).strftime("%Y-%m-%d")
                path = tape_dir / "npz" / symbol / f"{symbol}-{date}.npz"
                if not path.exists():
                    missing.append(f"{symbol}:{date}")
                    continue
                paths.append(path)
                if depth:
                    depth_path = tape_dir / "depth" / symbol / f"{symbol}-{date}.npz"
                    if depth_path.exists():
                        depth_paths.append(depth_path)
                    else:
                        missing.append(f"depth:{symbol}:{date}")
            if not paths:
                continue
            instrument, min_notional = instruments[symbol]
            loader = _tape_loader(tuple(paths), instrument.fixed_tick, start_s, end_s, tuple(depth_paths) if depth else None)
            series.append(Series(f"binance:{symbol}:{window}", None, None, instrument, start_s, end_s, False, min_notional, True, loader=loader))
    return series, missing


def _tape_loader(paths: tuple[Path, ...], tick: float, start_s: int, end_s: int, depth_paths: tuple[Path, ...] | None = None):
    """Deferred loading of one series' days: prints concatenated and sorted, quotes proxied; with
    `depth_paths` (r8), the bookDepth snapshots concatenated and sorted as a third item."""

    def load() -> tuple:
        pieces = []
        for path in paths:
            with np.load(path) as data:
                rows = np.zeros(data["ts_us"].size, dtype=TRADE_DTYPE)
                rows["ts"], rows["recv"] = data["ts_us"], data["ts_us"]
                rows["price"], rows["size"], rows["taker_buy"], rows["live"] = data["price"], data["size"], data["taker_buy"], 1
            pieces.append(rows)
        trades = np.concatenate(pieces)
        trades = trades[np.argsort(trades["ts"], kind="stable")]
        quotes = proxy_quotes(trades, tick, start_s, end_s)
        if depth_paths is None:
            return quotes, trades
        snaps, bids, asks = [], [], []
        for path in depth_paths:
            with np.load(path) as data:
                snaps.append(data["ts_s"].astype(np.int64))
                bids.append(data["bid"].astype(np.float64))
                asks.append(data["ask"].astype(np.float64))
        if snaps:
            ts_s = np.concatenate(snaps)
            order = np.argsort(ts_s, kind="stable")
            depth = {"ts_s": ts_s[order], "bid": np.concatenate(bids)[order], "ask": np.concatenate(asks)[order]}
        else:
            depth = {"ts_s": np.zeros(0, dtype=np.int64), "bid": np.zeros((0, 5)), "ask": np.zeros((0, 5))}
        return quotes, trades, depth

    return load


# ----------------------------------------------------------------------------- replay


_DATA: list[SeriesData] = []
_SEEDS = 0
_SOURCE = ""
_DAY_KEY = False
_OUTCOMES: Path | None = None


def _control_decisions(data: SeriesData, trades: np.ndarray, seed: int) -> np.ndarray:
    """Random decision seconds with the trades' count per hour of the day (bar indices)."""
    rng = np.random.default_rng(seed * 1_000_003 + data.series_id)
    hours = (trades["decision_s"] // 3600) % 24
    bar_hours = (data.bars["ts"] // 3600) % 24
    picks = []
    for hour, count in zip(*np.unique(hours, return_counts=True)):
        candidates = np.flatnonzero(bar_hours == hour)
        if candidates.size:
            picks.append(rng.choice(candidates, size=int(count), replace=True))
    if not picks:
        return np.zeros(0, dtype=np.int64)
    return np.unique(np.concatenate(picks))


def _replay_series(data: SeriesData, identifier: str, seeds: int) -> dict | None:
    """One configuration on one series: trades, counters, control sums per seed."""
    rule, side, entry, exit_ = parse_configuration(identifier)
    policy = Policy(entry, exit_)
    if rule.startswith("S3") and not np.isfinite(data.bars["bid_size"]).any():
        return None
    if rule.startswith("Q") and "di_1" not in data.features:
        return None  # depth rules need the bookDepth snapshots (r8)
    counters = {"decisions": 0, "orders": 0, "crossed": 0, "unfilled": 0, "filled": 0}
    control_sums = [[0.0, 0] for _ in range(seeds)]
    series_trades, signals = [], 0
    for stream in ((1, -1) if side == 0 else (side,)):
        decisions = np.flatnonzero(signal_mask(rule, data.features, stream))
        trades, count = run_policy(data, stream, decisions, policy)
        series_trades.append(trades)
        signals += int(decisions.size)
        for key in counters:
            counters[key] += getattr(count, key)
        if trades.size:
            for seed in range(seeds):
                control, _ = run_policy(data, stream, _control_decisions(data, trades, seed), policy)
                if control.size:
                    control_sums[seed][0] += float(control["net_bp"].sum())
                    control_sums[seed][1] += int(control.size)
    trades = np.concatenate(series_trades)
    return {"trades": trades, "counters": counters, "signals": signals, "control_sums": control_sums}


def _merge(identifier: str, parts: list[dict], seeds: int, started: float) -> dict:
    """Assemble one configuration's result from its per-series parts (ordered by series id)."""
    pieces, counters, per_series = [], {"decisions": 0, "orders": 0, "crossed": 0, "unfilled": 0, "filled": 0}, {}
    control_sums = [[0.0, 0] for _ in range(seeds)]
    for series_id, part in parts:
        if part is None:
            continue
        pieces.append(part["trades"])
        for key in counters:
            counters[key] += part["counters"][key]
        for seed in range(seeds):
            control_sums[seed][0] += part["control_sums"][seed][0]
            control_sums[seed][1] += part["control_sums"][seed][1]
        trades = part["trades"]
        per_series[series_id] = {"signals": part["signals"], "trades": int(trades.size), "net_bp_sum": _f(trades["net_bp"].sum()) if trades.size else 0.0}
    trades = np.concatenate(pieces) if pieces else np.zeros(0, dtype=ROW_DTYPE)
    control_means = [s / n for s, n in control_sums if n > 0]
    return {"id": identifier, "trades": trades, "counters": counters, "per_series": per_series, "control_means": control_means, "elapsed": time.time() - started}


def _replay(identifier: str) -> dict:
    started = time.time()
    parts = [(data.series_id, _replay_series(data, identifier, _SEEDS)) for data in _DATA]
    return _merge(identifier, parts, _SEEDS, started)


_SERIES: list = []
_CONFIGS: list[str] = []
_KEEP: tuple | None = None


def _init_series_worker(series: list, configurations: list[str], seeds: int, keep) -> None:
    global _SERIES, _CONFIGS, _SEEDS, _KEEP
    _SERIES, _CONFIGS, _SEEDS, _KEEP = series, configurations, seeds, keep


def _replay_one_series(index: int) -> tuple[int, dict, float]:
    """Worker-side, by-series mode: load one series, replay every configuration on it, free it."""
    started = time.time()
    data = _SERIES[index].data(index, _KEEP)
    parts = {identifier: _replay_series(data, identifier, _SEEDS) for identifier in _CONFIGS}
    del data
    return index, parts, time.time() - started


def _summary(result: dict, source: str, day_key: bool) -> dict:
    trades = result["trades"]
    counters = result["counters"]
    row = {"id": result["id"], "family": family_of(result["id"].split("|")[0]), "trades": int(trades.size), "counters": counters, "per_series": result["per_series"], "elapsed_seconds": round(result["elapsed"], 2)}
    fill_rate = counters["filled"] / counters["orders"] if counters["orders"] else None
    row["fill_rate"] = fill_rate
    control = np.asarray(result["control_means"], dtype=np.float64)
    row["control"] = {"seeds": int(control.size), "median": _f(np.median(control)) if control.size else None, "p95": _f(np.quantile(control, 0.95)) if control.size else None, "max": _f(control.max()) if control.size else None}
    if trades.size == 0:
        row.update({"mean_net_bp": None, "median_net_bp": None, "win_rate": None, "profit_factor": None, "lb90_bp": None, "net_usdt": 0.0, "without_best_group_bp": None, "fee_x_net_bp": None, "series_positive_share": 0.0, "exit_reasons": {}, "gates": {}, "passes": False})
        return row
    net = trades["net_bp"]
    gains, losses = net[net > 0].sum(), -net[net < 0].sum()
    moments = (trades["decision_s"] + trades["series"].astype(np.int64) * 10**9) * SECOND_US
    group = (trades["decision_s"] // (86400 if day_key else 3600)) + trades["series"].astype(np.int64) * 10**6
    group_sums = {int(g): float(net[group == g].sum()) for g in np.unique(group)}
    series_sums = {int(s): float(net[trades["series"] == s].sum()) for s in np.unique(trades["series"])}
    row.update({
        "mean_net_bp": _f(net.mean()), "median_net_bp": _f(np.median(net)), "win_rate": _f((net > 0).mean()),
        "profit_factor": _f(gains / losses) if losses > 0 else None, "lb90_bp": block_bootstrap_lower_bound(net, moments),
        "net_usdt": _f(net.sum() / 1e4 * REFERENCE_NOTIONAL), "mean_gross_bp": _f(trades["gross_bp"].mean()), "mean_fees_bp": _f(trades["fees_bp"].mean()),
        "hold_s_p50": _f(np.median(trades["hold_s"])), "without_best_group_bp": _f(net.sum() - max(group_sums.values())),
        "fee_x_net_bp": _f((trades["gross_bp"] - GATES["fee_multiplier"] * trades["fees_bp"]).sum()),
        "series_positive_share": _f(np.mean([value > 0 for value in series_sums.values()])), "series_count": len(series_sums),
        "exit_reasons": {int(k): int(v) for k, v in zip(*np.unique(trades["exit_reason"], return_counts=True))},
    })
    control_ok = row["control"]["p95"] is not None and row["mean_net_bp"] > row["control"]["p95"] and row["mean_net_bp"] >= row["control"]["median"] + GATES["control_margin_bp"]
    gates = {
        "G1_trades": trades.size >= GATES["min_trades"][source],
        "G2_mean_lb": row["mean_net_bp"] >= GATES["min_mean_bp"] and (row["lb90_bp"] or -1) > 0,
        "G3_pf": (row["profit_factor"] or 0) >= GATES["min_pf"],
        "G4_control": bool(control_ok),
        "G5_series": row["series_positive_share"] >= GATES["min_series_share"],
        "G6_best_group": row["without_best_group_bp"] > 0,
        "G7_fees": row["fee_x_net_bp"] > 0,
        "G8_fill": (fill_rate or 0) >= GATES["min_fill_rate"],
    }
    row["gates"] = gates
    row["passes"] = all(gates.values())
    return row


def _init_worker(data: list[SeriesData], seeds: int, source: str, day_key: bool, outcomes_dir: Path) -> None:
    global _DATA, _SEEDS, _SOURCE, _DAY_KEY, _OUTCOMES
    _DATA, _SEEDS, _SOURCE, _DAY_KEY, _OUTCOMES = data, seeds, source, day_key, outcomes_dir


def _replay_and_summarize(identifier: str) -> dict:
    """Worker-side: replay, save the outcomes, return only the summary row (the parent never
    holds the trades of every configuration — the first pooled runs showed why)."""
    result = _replay(identifier)
    np.savez_compressed(_OUTCOMES / (identifier.replace("|", "__") + ".npz"), trades=result["trades"])
    return _summary(result, _SOURCE, _DAY_KEY)


def run_screen(series: list[Series], source: str, phase: str, out_dir: Path, configurations: list[str], seeds: int, workers: int, extra: dict) -> dict:
    started = time.time()
    out_dir.mkdir(parents=True, exist_ok=True)
    keep = extra.get("features")
    day_key = any(item.day_key for item in series)
    outcomes_dir = out_dir / f"screen-{phase}-outcomes"
    outcomes_dir.mkdir(exist_ok=True)
    if extra.get("by_series"):
        # Series-outer mode: each worker loads one series at a time, replays every configuration
        # on it and frees it; only the small per-series parts travel back to the parent.
        print(f"{len(series)} series, {len(configurations)} configurations, by-series mode, {workers} workers", file=sys.stderr, flush=True)
        _init_series_worker(series, configurations, seeds, keep)
        parts_by_configuration: dict[str, list] = {identifier: [] for identifier in configurations}
        context = multiprocessing.get_context("fork")
        with context.Pool(max(workers, 1), initializer=_init_series_worker, initargs=(series, configurations, seeds, keep)) as pool:
            for index, parts, elapsed in _progress(pool.imap_unordered(_replay_one_series, range(len(series))), len(series)):
                for identifier, part in parts.items():
                    parts_by_configuration[identifier].append((index, part))
        rows = []
        for identifier in configurations:
            result = _merge(identifier, sorted(parts_by_configuration[identifier], key=lambda item: item[0]), seeds, started)
            np.savez_compressed(outcomes_dir / (identifier.replace("|", "__") + ".npz"), trades=result["trades"])
            rows.append(_summary(result, source, day_key))
    else:
        data = [item.data(index, keep) for index, item in enumerate(series)]
        print(f"{len(data)} series prepared in {time.time() - started:.0f}s; {len(configurations)} configurations, {workers} workers", file=sys.stderr, flush=True)
        _init_worker(data, seeds, source, day_key, outcomes_dir)
        if workers > 1:
            context = multiprocessing.get_context("fork")
            with context.Pool(workers, initializer=_init_worker, initargs=(data, seeds, source, day_key, outcomes_dir)) as pool:
                iterator = pool.imap_unordered(_replay_and_summarize, configurations, chunksize=2)
                rows = list(_progress(iterator, len(configurations)))
        else:
            rows = list(_progress(map(_replay_and_summarize, configurations), len(configurations)))
    rows.sort(key=lambda row: row["id"])
    document = {
        "source": source, "phase": phase, "generated_at": _now(), "elapsed_seconds": round(time.time() - started, 1), "seeds": seeds,
        "series": [{"id": index, "name": item.name, "start": datetime.fromtimestamp(item.start_s, tz=timezone.utc).isoformat(), "end": datetime.fromtimestamp(item.end_s, tz=timezone.utc).isoformat(), "seconds": item.end_s - item.start_s, "book": item.book, "min_notional": item.min_notional} for index, item in enumerate(series)],
        "gates": GATES, "configurations": len(configurations), "passes": sum(1 for row in rows if row["passes"]), "rows": rows, "code": code_identity(), **extra,
    }
    (out_dir / f"screen-{phase}.json").write_text(json.dumps(document, indent=1, sort_keys=True, default=_json_default) + "\n")
    return document


def _progress(iterator, total: int):
    done = 0
    for item in iterator:
        done += 1
        if done % 25 == 0 or done == total:
            print(f"{done}/{total} configurations ({_now()})", file=sys.stderr, flush=True)
        yield item


# ----------------------------------------------------------------------------- selection, verdict, budgets


def select(screen: dict, max_per_family: int = MAX_PER_FAMILY) -> list[dict]:
    chosen = []
    for family in sorted({row["family"] for row in screen["rows"]}):
        passing = [row for row in screen["rows"] if row["family"] == family and row["passes"]]
        passing.sort(key=lambda row: -(row["lb90_bp"] or -1e9))
        chosen.extend({"id": row["id"], "family": family, "lb90_bp": row["lb90_bp"], "mean_net_bp": row["mean_net_bp"], "trades": row["trades"]} for row in passing[:max_per_family])
    return chosen


def verdict(training: dict, validation: dict) -> dict:
    source = validation["source"]
    results = {}
    for row in validation["rows"]:
        control_median = row["control"]["median"]
        checks = {
            "trades": row["trades"] >= VALIDATION["min_trades"][source],
            "net_positive": (row["mean_net_bp"] or 0) > 0,
            "pf": (row["profit_factor"] or 0) >= VALIDATION["min_pf"],
            "beats_control_median": control_median is not None and (row["mean_net_bp"] or 0) > control_median,
            "series": (row["series_positive_share"] or 0) >= VALIDATION["min_series_share"],
        }
        results[row["id"]] = {"checks": checks, "passes": all(checks.values()), "trades": row["trades"], "mean_net_bp": row["mean_net_bp"], "profit_factor": row["profit_factor"], "lb90_bp": row["lb90_bp"], "control_median": control_median, "net_usdt": row["net_usdt"]}
    candidates = sorted(identifier for identifier, item in results.items() if item["passes"])
    return {"source": source, "training_passes": training["passes"], "validated": len(validation["rows"]), "candidates": candidates, "outcome": "candidate" if candidates else "no_eligible_candidate", "results": results, "generated_at": _now()}


def budgets(screen: dict, identifiers: list[str] | None = None) -> dict:
    """Restate configurations by budget: notional at 1× and 2× the budget against the venue
    minimums, fees per round trip, projected net per day from the screen's trades per day."""
    rows = {row["id"]: row for row in screen["rows"]}
    wanted = identifiers or [row["id"] for row in sorted(screen["rows"], key=lambda r: -(r["lb90_bp"] or -1e9))[:3]]
    seconds = sum(item["seconds"] for item in screen["series"])
    days = seconds / 86400.0
    table = []
    for identifier in wanted:
        row = rows[identifier]
        if row["trades"] == 0:
            continue
        per_day = row["trades"] / days
        for budget in BUDGETS:
            for leverage in (1.0, 2.0):
                notional = budget * leverage
                min_notional = max(item["min_notional"] for item in screen["series"])
                table.append({
                    "id": identifier, "budget_usdt": budget, "leverage": leverage, "notional_usdt": notional, "tradable": notional >= min_notional,
                    "fees_per_round_trip_usdt": _f(notional * (row["mean_fees_bp"] or 0) / 1e4), "trades_per_day": _f(per_day),
                    "projected_net_per_day_usdt": _f(per_day * notional * (row["mean_net_bp"] or 0) / 1e4),
                })
    return {"source": screen["source"], "phase": screen["phase"], "days": days, "rows": table}


# ----------------------------------------------------------------------------- CLI


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_exec.screen")
    sub = parser.add_subparsers(dest="command", required=True)
    screen = sub.add_parser("screen")
    screen.add_argument("--source", choices=("captures", "binance"), required=True)
    screen.add_argument("--phase", choices=("training", "validation", "holdout"), required=True)
    screen.add_argument("--protocol", choices=tuple(PROTOCOLS), default="r1")
    screen.add_argument("--out", required=True)
    screen.add_argument("--extract-dir", default="")
    screen.add_argument("--tape-dir", default="")
    screen.add_argument("--instruments", default="")
    screen.add_argument("--configs-file", default="")
    screen.add_argument("--symbols", default="")
    screen.add_argument("--workers", type=int, default=3)
    screen.add_argument("--seeds", type=int, default=-1)
    screen.add_argument("--prereg", default="")
    sel = sub.add_parser("select")
    sel.add_argument("--screen", required=True)
    sel.add_argument("--out", required=True)
    ver = sub.add_parser("verdict")
    ver.add_argument("--training", required=True)
    ver.add_argument("--validation", required=True)
    ver.add_argument("--out", required=True)
    ver.add_argument("--protocol", choices=tuple(PROTOCOLS), default="r1")
    bud = sub.add_parser("budgets")
    bud.add_argument("--screen", required=True)
    bud.add_argument("--configs", default="")
    bud.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    if args.command == "screen":
        use_protocol(args.protocol)
        extra = {"protocol": args.protocol}
        if PROTOCOLS[args.protocol].get("features"):
            extra["features"] = tuple(PROTOCOLS[args.protocol]["features"])
        if PROTOCOLS[args.protocol].get("by_series"):
            extra["by_series"] = True
        if args.source == "captures":
            if args.phase == "holdout":
                raise SystemExit("the captures have no holdout phase")
            series = load_capture_series(Path(args.extract_dir), args.phase)
            book = True
        else:
            spec = PROTOCOLS[args.protocol]
            symbols = tuple(args.symbols.split(",")) if args.symbols else tuple(spec.get("symbols", BINANCE_SYMBOLS))
            windows = spec.get("training_windows") if args.phase == "training" else spec.get("phases", {}).get(args.phase)
            series, missing = load_binance_series(Path(args.tape_dir), Path(args.instruments), args.phase, symbols, windows, depth=bool(spec.get("depth")))
            extra["missing_days"] = missing
            book = False
        if args.configs_file:
            configurations = [line.strip() for line in Path(args.configs_file).read_text().splitlines() if line.strip()]
        else:
            configurations = build_grid(book, args.protocol)
        seeds = SEEDS[args.source] if args.seeds < 0 else args.seeds
        if args.prereg:
            extra["prereg_sha256"] = hashlib.sha256(Path(args.prereg).read_bytes()).hexdigest()
        document = run_screen(series, args.source, args.phase, Path(args.out), configurations, seeds, args.workers, extra)
        print(json.dumps({"source": args.source, "phase": args.phase, "configurations": document["configurations"], "passes": document["passes"], "elapsed_seconds": document["elapsed_seconds"]}))
        return 0
    if args.command == "select":
        chosen = select(json.loads(Path(args.screen).read_text()))
        Path(args.out).write_text(json.dumps({"selected": chosen, "generated_at": _now()}, indent=1) + "\n")
        Path(args.out).with_suffix(".txt").write_text("".join(item["id"] + "\n" for item in chosen))
        print(json.dumps({"selected": len(chosen)}))
        return 0
    if args.command == "verdict":
        use_protocol(args.protocol)
        result = verdict(json.loads(Path(args.training).read_text()), json.loads(Path(args.validation).read_text()))
        Path(args.out).write_text(json.dumps(result, indent=1, sort_keys=True) + "\n")
        print(json.dumps({"outcome": result["outcome"], "candidates": result["candidates"]}))
        return 0
    identifiers = [line.strip() for line in Path(args.configs).read_text().splitlines() if line.strip()] if args.configs else None
    result = budgets(json.loads(Path(args.screen).read_text()), identifiers)
    Path(args.out).write_text(json.dumps(result, indent=1) + "\n")
    print(json.dumps({"rows": len(result["rows"])}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
