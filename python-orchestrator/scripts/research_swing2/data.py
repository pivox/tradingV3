"""Universe roots for swing v2: 1m candles, 4h frames, funding and instruments, with a hard cut.

A root holds ``candles/<SYMBOL>.candles.bin`` (``exploration.CandleSeries``), ``features/<SYMBOL>.frame.json``
(``swing.build_frame`` on UTC 4h bars), ``funding.json`` ({symbol: [[ms, rate, null], ...]}),
``instrument-assumptions.json`` and ``cost-assumptions.json`` — the layout of the swing r1/r2 roots, so the
majors' training root is the r2 root unchanged.

``build_root`` makes a root from the monthly (and daily) 1m kline archives and monthly funding archives
converted by ``scripts.research_exec.acquire``: candles are concatenated, cut at ``end_ms`` (exclusive),
started at the first UTC midnight with data, and the rare missing minutes are filled with flat candles at
the previous close (counted in the receipt). ``load_market`` refuses any candle or funding event at or after
the cut it is given: the validation loader is called with 2026-01-01, so no 2026 value reaches a
validation computation.
"""

from __future__ import annotations

import hashlib
import json
from array import array
from pathlib import Path

import numpy as np

from app.backtesting.research import exploration as ex
from app.backtesting.research import swing as sw

from .engine import DAY, FOUR_HOURS, MINUTE, Market

VALIDATION_CUT_MS = 1_767_225_600_000  # 2026-01-01T00:00:00Z
HOLDOUT_END_MS = 1_791_525_600_000  # 2026-10-09T06:00:00Z, the frozen end of the 2026 holdout
MIDCAPS = ("ARBUSDT", "OPUSDT", "APTUSDT", "SUIUSDT", "NEARUSDT", "TIAUSDT", "SEIUSDT", "INJUSDT", "ATOMUSDT", "FILUSDT")


class DataError(RuntimeError):
    pass


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _kline_files(tape: Path, symbol: str) -> list[Path]:
    return sorted((tape / "klines" / symbol).glob(f"{symbol}-1m-*.npz"))


def assemble_candles(files: list[Path], symbol: str, end_ms: int) -> tuple[ex.CandleSeries, dict]:
    """Concatenate converted 1m kline files into one contiguous series [first UTC midnight, end_ms)."""
    parts = {name: [] for name in ("ts_s", "open", "high", "low", "close")}
    for path in files:
        with np.load(path) as data:
            for name in parts:
                parts[name].append(data[name])
    if not parts["ts_s"]:
        raise DataError(f"{symbol}: no kline file")
    ts_ms = np.concatenate(parts["ts_s"]).astype(np.int64) * 1000
    order = np.argsort(ts_ms, kind="stable")
    ts_ms = ts_ms[order]
    columns = {name: np.concatenate(parts[name])[order] for name in ("open", "high", "low", "close")}
    keep = np.ones(ts_ms.size, dtype=bool)
    keep[:-1] = ts_ms[1:] != ts_ms[:-1]  # duplicate minutes across files: the last wins
    ts_ms = ts_ms[keep]
    columns = {name: values[keep] for name, values in columns.items()}
    inside = ts_ms < end_ms
    ts_ms = ts_ms[inside]
    columns = {name: values[inside] for name, values in columns.items()}
    start = -(-int(ts_ms[0]) // DAY) * DAY
    begin = int(np.searchsorted(ts_ms, start))
    ts_ms = ts_ms[begin:]
    columns = {name: values[begin:] for name, values in columns.items()}
    if ts_ms.size == 0:
        raise DataError(f"{symbol}: no candle after the first midnight")
    last = int(ts_ms[-1])
    count = (last - start) // MINUTE + 1
    slots = (ts_ms - start) // MINUTE
    present = np.zeros(count, dtype=bool)
    present[slots] = True
    filled = {name: np.full(count, np.nan) for name in columns}
    for name, values in columns.items():
        filled[name][slots] = values
    # Forward fill the missing minutes with flat candles at the previous close.
    missing = np.flatnonzero(~present)
    for slot in missing:
        previous = filled["close"][slot - 1]
        for name in filled:
            filled[name][slot] = previous
    series = ex.CandleSeries(symbol, start, *(array("d", filled[name].tolist()) for name in ("open", "high", "low", "close")))
    receipt = {"symbol": symbol, "files": [p.name for p in files], "start_ms": start, "last_open_ms": last, "candles": int(count),
               "missing_minutes_filled": int(missing.size), "dropped_before_first_midnight": int(begin), "end_ms_exclusive": end_ms}
    return series, receipt


def assemble_funding(tape: Path, symbol: str, start_ms: int, end_ms: int) -> list[list]:
    events: dict[int, float] = {}
    for path in sorted((tape / "funding" / symbol).glob(f"{symbol}-fundingRate-*.npz")):
        with np.load(path) as data:
            for ts, rate in zip(data["ts_ms"].tolist(), data["rate"].tolist()):
                if start_ms <= ts < end_ms:
                    events[int(ts)] = float(rate)
    return [[ts, events[ts], None] for ts in sorted(events)]


def build_root(tape: Path, out: Path, symbols: tuple[str, ...], end_ms: int, instruments: Path, costs: Path) -> dict:
    """Write a universe root from converted archives; returns the receipt (also written to the root)."""
    (out / "candles").mkdir(parents=True, exist_ok=True)
    (out / "features").mkdir(exist_ok=True)
    instrument_doc = json.loads(instruments.read_text())
    by_symbol = {item["symbol"]: item for item in instrument_doc["symbols"]}
    missing = [s for s in symbols if s not in by_symbol]
    if missing:
        raise DataError(f"instruments missing for {missing}")
    receipt = {"end_ms_exclusive": end_ms, "symbols": {}, "instruments_sha256": sha256_file(instruments), "costs_sha256": sha256_file(costs)}
    funding_doc = {}
    for symbol in symbols:
        series, candle_receipt = assemble_candles(_kline_files(tape, symbol), symbol, end_ms)
        series.write(out / "candles" / f"{symbol}.candles.bin", {"source": str(tape), "end_ms_exclusive": end_ms})
        frame = sw.build_frame(symbol, sw.aggregate(series, FOUR_HOURS))
        (out / "features" / f"{symbol}.frame.json").write_text(json.dumps(frame.to_json()))
        funding_doc[symbol] = assemble_funding(tape, symbol, series.start_ms, end_ms)
        candle_receipt["funding_events"] = len(funding_doc[symbol])
        candle_receipt["bars_4h"] = frame.count
        candle_receipt["first_decision_ms"] = frame.close_ms(sw.WINDOW - 1) if frame.count >= sw.WINDOW else None
        receipt["symbols"][symbol] = candle_receipt
    (out / "funding.json").write_text(json.dumps(funding_doc))
    (out / "instrument-assumptions.json").write_text(json.dumps({**instrument_doc, "symbols": [by_symbol[s] for s in symbols]}, indent=1))
    (out / "cost-assumptions.json").write_text(costs.read_text())
    (out / "build-receipt.json").write_text(json.dumps(receipt, indent=1, sort_keys=True) + "\n")
    return receipt


def load_market(root: Path, symbols: tuple[str, ...], cut_ms: int) -> tuple[Market, dict]:
    """Load a root for ``symbols``; refuses any candle or funding event at or after ``cut_ms``."""
    candles, frames, blocks, funding = {}, {}, {}, {}
    funding_doc = json.loads((root / "funding.json").read_text())
    for symbol in symbols:
        series = ex.CandleSeries.read(root / "candles" / f"{symbol}.candles.bin")
        if series.start_ms % DAY:
            raise DataError(f"{symbol}: candles must start on a UTC day")
        last_open = series.start_ms + (series.count - 1) * MINUTE
        if last_open >= cut_ms:
            series = _truncate(series, cut_ms)
        candles[symbol] = series
        frame = sw.Frame.from_json(json.loads((root / "features" / f"{symbol}.frame.json").read_text()))
        bars = -((series.start_ms - cut_ms) // FOUR_HOURS) if frame.close_ms(frame.count - 1) > cut_ms else frame.count
        if bars < frame.count:
            frame = sw.Frame(frame.symbol, frame.step, frame.start_ms, {k: v[:bars] for k, v in frame.columns.items()})
        frames[symbol] = frame
        blocks[symbol] = sw.extremes(series)
        funding[symbol] = ex.FundingSeries.from_events(e for e in funding_doc[symbol] if e[0] < cut_ms)
    instruments = {i["symbol"]: i for i in json.loads((root / "instrument-assumptions.json").read_text())["symbols"]}
    profiles = json.loads((root / "cost-assumptions.json").read_text())["profiles"]
    for symbol in symbols:
        last_open = candles[symbol].start_ms + (candles[symbol].count - 1) * MINUTE
        if last_open >= cut_ms or (funding[symbol].timestamps and funding[symbol].timestamps[-1] >= cut_ms):
            raise DataError(f"{symbol}: data at or after the cut")  # pragma: no cover - guarded above
    return Market(frames, candles, blocks, funding, instruments), profiles


def _truncate(series: ex.CandleSeries, cut_ms: int) -> ex.CandleSeries:
    keep = (cut_ms - series.start_ms) // MINUTE
    return ex.CandleSeries(series.symbol, series.start_ms, series.open[:keep], series.high[:keep], series.low[:keep], series.close[:keep])
