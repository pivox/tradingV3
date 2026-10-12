"""Loader for the research dataset ``20261009T060000Z-10pairs-20221101`` with a hard 2026 cut.

The dataset is a manifest plus the official Binance monthly archives (1-minute klines and
funding rates). This loader:

- reads only the archives the manifest lists, verifies each archive's sha256 against the
  manifest before opening it,
- parses the CSVs (header optional, timestamps in ms or µs),
- drops every candle and funding event at or after ``CUT_MS`` (2026-01-01T00:00Z): the 2026
  window is a holdout and must never reach a v2 computation,
- caches the arrays as ``.npz`` files next to a JSON receipt (archives, hashes, counts, gaps).

Volumes are kept (the earlier ``candles.bin`` format dropped them) because the v2 catalogue
has VWAP and volume filters.
"""

from __future__ import annotations

import hashlib
import io
import json
import zipfile
from dataclasses import dataclass
from pathlib import Path

import numpy as np

from .bars import MINUTE_MS, Candles

CUT_MS = 1_767_225_600_000  # 2026-01-01T00:00:00Z, exclusive end of every v2 computation
SYMBOLS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "XRPUSDT", "ADAUSDT", "DOGEUSDT", "SOLUSDT", "LTCUSDT", "LINKUSDT", "AVAXUSDT")

KLINE_COLUMNS = ("open_time", "open", "high", "low", "close", "volume", "close_time", "quote_volume", "count", "taker_buy_volume", "taker_buy_quote_volume", "ignore")
FUNDING_COLUMNS = ("calc_time", "funding_interval_hours", "last_funding_rate")


class DatasetError(RuntimeError):
    pass


@dataclass(frozen=True)
class Funding:
    symbol: str
    time_ms: np.ndarray  # int64, sorted
    rate: np.ndarray  # float64


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _parse_ms(text: str) -> int:
    value = int(text)
    return value // 1000 if value > 100_000_000_000_000 else value  # µs archives (2025+) -> ms


def _iso_to_ms(text: str) -> int:
    from datetime import datetime, timezone

    return int(datetime.fromisoformat(text.replace("Z", "+00:00")).astimezone(timezone.utc).timestamp() * 1000)


def _manifest_entries(dataset_root: Path, symbol: str, kind: str) -> list[dict]:
    manifest = json.loads((dataset_root / "manifest.json").read_text())
    entries = [
        entry
        for entry in manifest["sources"]
        if entry.get("symbol") == symbol and entry.get("kind") == kind and entry.get("status") == "ok" and _iso_to_ms(entry["start"]) < CUT_MS
    ]
    if not entries:
        raise DatasetError(f"no {kind} archives for {symbol} in the manifest")
    return sorted(entries, key=lambda entry: entry["start"])


def _read_csv_rows(dataset_root: Path, entry: dict, expected_columns: int) -> list[list[str]]:
    path = dataset_root / entry["raw_path"]
    actual = sha256_file(path)
    if actual != entry["sha256"]:
        raise DatasetError(f"sha256 mismatch for {path.name}: manifest {entry['sha256'][:12]}…, file {actual[:12]}…")
    rows: list[list[str]] = []
    with zipfile.ZipFile(path) as archive:
        names = [name for name in archive.namelist() if name.endswith(".csv")]
        if len(names) != 1:
            raise DatasetError(f"{path.name} must hold exactly one CSV, found {names}")
        with archive.open(names[0]) as handle:
            text = io.TextIOWrapper(handle, encoding="utf-8")
            for line in text:
                line = line.strip()
                if not line:
                    continue
                fields = line.split(",")
                if not fields[0].isdigit():
                    continue  # header
                if len(fields) < expected_columns:
                    raise DatasetError(f"{path.name}: short row {line[:80]!r}")
                rows.append(fields)
    return rows


def load_candles_from_archives(dataset_root: Path, symbol: str) -> tuple[Candles, np.ndarray, dict]:
    """Candles of ``symbol`` before the cut, the taker-buy volumes, and a receipt."""
    entries = _manifest_entries(dataset_root, symbol, "klines")
    columns: dict[str, list[float]] = {name: [] for name in ("open_ms", "open", "high", "low", "close", "volume", "taker_buy")}
    archives = []
    for entry in entries:
        rows = _read_csv_rows(dataset_root, entry, 10)
        kept = 0
        for fields in rows:
            open_ms = _parse_ms(fields[0])
            if open_ms >= CUT_MS:
                continue
            columns["open_ms"].append(open_ms)
            columns["open"].append(float(fields[1]))
            columns["high"].append(float(fields[2]))
            columns["low"].append(float(fields[3]))
            columns["close"].append(float(fields[4]))
            columns["volume"].append(float(fields[5]))
            columns["taker_buy"].append(float(fields[9]))
            kept += 1
        archives.append({"filename": entry["filename"], "sha256": entry["sha256"], "rows": len(rows), "kept": kept})
    open_ms = np.asarray(columns["open_ms"], dtype=np.int64)
    order = np.argsort(open_ms, kind="stable")
    open_ms = open_ms[order]
    unique_mask = np.concatenate(([True], np.diff(open_ms) > 0))
    arrays = {name: np.asarray(values, dtype=np.float64)[order][unique_mask] for name, values in columns.items() if name != "open_ms"}
    open_ms = open_ms[unique_mask]
    if open_ms.size == 0:
        raise DatasetError(f"no candles before the cut for {symbol}")
    if int(open_ms[-1]) >= CUT_MS:
        raise DatasetError("cut violated")  # pragma: no cover - guarded above
    expected = (int(open_ms[-1]) - int(open_ms[0])) // MINUTE_MS + 1
    candles = Candles(symbol, open_ms, arrays["open"], arrays["high"], arrays["low"], arrays["close"], arrays["volume"])
    receipt = {
        "symbol": symbol,
        "archives": archives,
        "candles": int(open_ms.size),
        "duplicates_dropped": int((~unique_mask).sum()),
        "first_open_ms": int(open_ms[0]),
        "last_open_ms": int(open_ms[-1]),
        "missing_minutes": int(expected - open_ms.size),
        "cut_ms": CUT_MS,
    }
    return candles, arrays["taker_buy"], receipt


def load_funding_from_archives(dataset_root: Path, symbol: str) -> tuple[Funding, dict]:
    entries = _manifest_entries(dataset_root, symbol, "funding")
    times: list[int] = []
    rates: list[float] = []
    archives = []
    for entry in entries:
        rows = _read_csv_rows(dataset_root, entry, 3)
        kept = 0
        for fields in rows:
            time_ms = _parse_ms(fields[0])
            if time_ms >= CUT_MS:
                continue
            times.append(time_ms)
            rates.append(float(fields[2]))
            kept += 1
        archives.append({"filename": entry["filename"], "sha256": entry["sha256"], "rows": len(rows), "kept": kept})
    time_ms = np.asarray(times, dtype=np.int64)
    order = np.argsort(time_ms, kind="stable")
    time_ms = time_ms[order]
    rate = np.asarray(rates, dtype=np.float64)[order]
    unique_mask = np.concatenate(([True], np.diff(time_ms) > 0))
    funding = Funding(symbol, time_ms[unique_mask], rate[unique_mask])
    receipt = {"symbol": symbol, "archives": archives, "events": int(funding.time_ms.size), "cut_ms": CUT_MS}
    return funding, receipt


def prepare(dataset_root: Path, cache_root: Path, symbols: tuple[str, ...] = SYMBOLS) -> dict:
    """Build the ``.npz`` caches and receipts for every symbol; returns the global receipt."""
    cache_root.mkdir(parents=True, exist_ok=True)
    (cache_root / "candles").mkdir(exist_ok=True)
    (cache_root / "funding").mkdir(exist_ok=True)
    receipt = {"dataset_root": str(dataset_root), "manifest_sha256": sha256_file(dataset_root / "manifest.json"), "cut_ms": CUT_MS, "symbols": {}}
    for symbol in symbols:
        candles, taker_buy, candle_receipt = load_candles_from_archives(dataset_root, symbol)
        funding, funding_receipt = load_funding_from_archives(dataset_root, symbol)
        np.savez(
            cache_root / "candles" / f"{symbol}.npz",
            open_ms=candles.open_ms,
            open=candles.open,
            high=candles.high,
            low=candles.low,
            close=candles.close,
            volume=candles.volume,
            taker_buy=taker_buy,
        )
        np.savez(cache_root / "funding" / f"{symbol}.npz", time_ms=funding.time_ms, rate=funding.rate)
        (cache_root / "candles" / f"{symbol}.json").write_text(json.dumps(candle_receipt, indent=2, sort_keys=True) + "\n")
        (cache_root / "funding" / f"{symbol}.json").write_text(json.dumps(funding_receipt, indent=2, sort_keys=True) + "\n")
        receipt["symbols"][symbol] = {"candles": candle_receipt, "funding": funding_receipt}
    (cache_root / "prepare-receipt.json").write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n")
    return receipt


def load_cached_candles(cache_root: Path, symbol: str) -> tuple[Candles, np.ndarray]:
    with np.load(cache_root / "candles" / f"{symbol}.npz") as data:
        open_ms = data["open_ms"]
        if open_ms.size and int(open_ms[-1]) >= CUT_MS:
            raise DatasetError(f"cache for {symbol} holds candles at or after the 2026 cut")
        candles = Candles(symbol, open_ms, data["open"], data["high"], data["low"], data["close"], data["volume"])
        return candles, data["taker_buy"]


def load_cached_funding(cache_root: Path, symbol: str) -> Funding:
    with np.load(cache_root / "funding" / f"{symbol}.npz") as data:
        time_ms = data["time_ms"]
        if time_ms.size and int(time_ms[-1]) >= CUT_MS:
            raise DatasetError(f"funding cache for {symbol} holds events at or after the 2026 cut")
        return Funding(symbol, time_ms, data["rate"])


def funding_marks(candles: Candles, funding: Funding) -> np.ndarray:
    """The 1-minute close at (or just before) each funding timestamp, used as the mark price."""
    index = np.searchsorted(candles.open_ms, funding.time_ms, side="right") - 1
    index = np.clip(index, 0, candles.size - 1)
    return candles.close[index]
