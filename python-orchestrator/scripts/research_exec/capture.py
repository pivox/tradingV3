"""Read-only extraction of quotes and trades from a paper capture dataset.

A dataset is a directory with `manifest.json` (state, sha256 of the events file, symbols) and
`events.ndjson` (one JSON event per line: channel, symbol, exchange_timestamp,
received_timestamp, payload). Only `top_of_book`, `public_trade`, `instrument_metadata`,
`snapshot_boundary` and `connection_state` events are read; nothing is written into the
dataset directory. The events file is hashed while it is streamed and the hash must equal the
manifest's `events_file_sha256`, otherwise the extraction fails closed.

Timestamps are the exchange timestamps (microseconds since the epoch); the local receipt
timestamps are kept to report the capture latency. Quotes and trades are returned sorted by
exchange time with a stable sort, so events with equal timestamps keep their file order.
"""

from __future__ import annotations

import hashlib
import json
import math
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

try:  # orjson is optional: the CI runtime has only the standard library.
    import orjson as _orjson
except ImportError:  # pragma: no cover - exercised only where orjson is absent
    _orjson = None

QUOTE_DTYPE = np.dtype(
    [("ts", "<i8"), ("recv", "<i8"), ("bid", "<f8"), ("ask", "<f8"), ("bid_size", "<f8"), ("ask_size", "<f8")]
)
TRADE_DTYPE = np.dtype(
    [("ts", "<i8"), ("recv", "<i8"), ("price", "<f8"), ("size", "<f8"), ("taker_buy", "<i1"), ("live", "<i1")]
)

_TOP_OF_BOOK = b'"channel":"top_of_book"'
_PUBLIC_TRADE = b'"channel":"public_trade"'
_INSTRUMENT = b'"channel":"instrument_metadata"'
_BOUNDARY = b'"channel":"snapshot_boundary"'
_CONNECTION = b'"channel":"connection_state"'
_EPOCH = datetime(1970, 1, 1, tzinfo=timezone.utc)
_CHUNK = 1 << 18


def _loads(line: bytes) -> dict:
    if _orjson is not None:
        return _orjson.loads(line)
    return json.loads(line)


def parse_timestamp_us(value: str) -> int:
    """ISO-8601 UTC timestamp ('2026-10-02T13:33:15.458000Z') to microseconds since the epoch."""
    if value.endswith("Z"):
        value = value[:-1] + "+00:00"
    moment = datetime.fromisoformat(value)
    if moment.tzinfo is None:
        raise ValueError(f"timestamp without timezone: {value}")
    delta = moment - _EPOCH
    return delta.days * 86_400_000_000 + delta.seconds * 1_000_000 + delta.microseconds


@dataclass(frozen=True)
class Instrument:
    """Price grid and size rules of one captured instrument."""

    symbol: str
    native_symbol: str
    contract_value: float  # base units per contract (1.0 when sizes are already in base units)
    minimum_quantity: float  # in the venue's quantity unit (contracts or base units)
    quantity_step: float
    fixed_tick: float | None = None  # OKX: price_tick
    significant_digits: int | None = None  # Hyperliquid: price_precision_digits
    max_decimals: int | None = None  # Hyperliquid: price_max_decimals

    def tick(self, price: float) -> float:
        return float(self.tick_array(np.asarray([price], dtype=np.float64))[0])

    def tick_array(self, prices: np.ndarray) -> np.ndarray:
        """Tick size at each price: fixed, or derived from a significant-digit rule."""
        prices = np.asarray(prices, dtype=np.float64)
        if self.fixed_tick is not None:
            return np.full(prices.shape, self.fixed_tick, dtype=np.float64)
        if self.significant_digits is None:
            raise ValueError(f"{self.symbol}: no tick rule")
        exponent = np.floor(np.log10(prices)) + 1 - self.significant_digits
        ticks = np.power(10.0, exponent)
        if self.max_decimals is not None:
            ticks = np.maximum(ticks, 10.0 ** (-self.max_decimals))
        return ticks

    def minimum_notional(self, price: float) -> float:
        return self.minimum_quantity * self.contract_value * price

    def to_dict(self) -> dict:
        return {
            "symbol": self.symbol,
            "native_symbol": self.native_symbol,
            "contract_value": self.contract_value,
            "minimum_quantity": self.minimum_quantity,
            "quantity_step": self.quantity_step,
            "fixed_tick": self.fixed_tick,
            "significant_digits": self.significant_digits,
            "max_decimals": self.max_decimals,
        }

    @classmethod
    def from_dict(cls, data: dict) -> "Instrument":
        return cls(**data)


def instrument_from_payload(symbol: str, payload: dict) -> Instrument:
    fixed_tick = float(payload["price_tick"]) if payload.get("price_tick") is not None else None
    digits = payload.get("price_precision_digits")
    decimals = payload.get("price_max_decimals")
    if fixed_tick is None and digits is None:
        raise ValueError(f"{symbol}: instrument metadata without a price grid")
    return Instrument(
        symbol=symbol,
        native_symbol=str(payload["native_symbol"]),
        contract_value=float(payload.get("contract_value", "1")),
        minimum_quantity=float(payload["minimum_quantity"]),
        quantity_step=float(payload["quantity_step"]),
        fixed_tick=fixed_tick,
        significant_digits=int(digits) if digits is not None else None,
        max_decimals=int(decimals) if decimals is not None else None,
    )


class _Buffer:
    """Append-only structured buffer growing by fixed chunks."""

    def __init__(self, dtype: np.dtype) -> None:
        self.dtype = dtype
        self.chunks: list[np.ndarray] = []
        self.current = np.empty(_CHUNK, dtype=dtype)
        self.fill = 0

    def append(self, row: tuple) -> None:
        if self.fill == _CHUNK:
            self.chunks.append(self.current)
            self.current = np.empty(_CHUNK, dtype=self.dtype)
            self.fill = 0
        self.current[self.fill] = row
        self.fill += 1

    def finish(self) -> np.ndarray:
        parts = self.chunks + [self.current[: self.fill]]
        rows = np.concatenate(parts) if len(parts) > 1 else parts[0].copy()
        return rows[np.argsort(rows["ts"], kind="stable")]


@dataclass
class Extract:
    venue: str
    dataset_id: str
    quotes: dict[str, np.ndarray]
    trades: dict[str, np.ndarray]
    instruments: dict[str, Instrument]
    receipt: dict


def read_manifest(dataset_root: Path) -> dict:
    manifest = json.loads((dataset_root / "manifest.json").read_text())
    if manifest.get("state") != "complete":
        raise ValueError(f"dataset {manifest.get('dataset_id')} is not complete: {manifest.get('state')}")
    if manifest.get("sequence_gaps"):
        raise ValueError(f"dataset {manifest.get('dataset_id')} declares sequence gaps")
    return manifest


def extract(dataset_root: Path, symbols: tuple[str, ...] | None = None) -> Extract:
    """Stream the events file once, verify its hash, and collect quotes and trades per symbol."""
    manifest = read_manifest(dataset_root)
    wanted = tuple(symbols) if symbols else tuple(sorted(manifest["symbols"]))
    quotes = {symbol: _Buffer(QUOTE_DTYPE) for symbol in wanted}
    trades = {symbol: _Buffer(TRADE_DTYPE) for symbol in wanted}
    instruments: dict[str, Instrument] = {}
    counts: dict[str, int] = {}
    origins: dict[str, int] = {}
    boundaries: list[dict] = []
    connections: list[dict] = []
    synthetic_quotes = 0
    crossed_quotes = 0
    latency_quotes = _Buffer(np.dtype([("ts", "<i8")]))
    latency_trades = _Buffer(np.dtype([("ts", "<i8")]))
    digest = hashlib.sha256()
    lines = 0
    with (dataset_root / "events.ndjson").open("rb") as handle:
        for line in handle:
            digest.update(line)
            lines += 1
            if _TOP_OF_BOOK in line:
                event = _loads(line)
                symbol = event["symbol"]
                counts["top_of_book"] = counts.get("top_of_book", 0) + 1
                payload = event["payload"]
                origin = payload.get("origin", "")
                origins[f"top_of_book:{origin}"] = origins.get(f"top_of_book:{origin}", 0) + 1
                if symbol not in quotes:
                    continue
                if payload.get("synthetic"):
                    synthetic_quotes += 1
                    continue
                bid = float(payload["bid_price"])
                ask = float(payload["ask_price"])
                if bid >= ask:
                    crossed_quotes += 1
                    continue
                ts = parse_timestamp_us(event["exchange_timestamp"])
                recv = parse_timestamp_us(event["received_timestamp"])
                bid_size = float(payload.get("bid_size_contracts", payload.get("bid_size", "0")))
                ask_size = float(payload.get("ask_size_contracts", payload.get("ask_size", "0")))
                quotes[symbol].append((ts, recv, bid, ask, bid_size, ask_size))
                if origin.startswith("ws_"):
                    latency_quotes.append((recv - ts,))
            elif _PUBLIC_TRADE in line:
                event = _loads(line)
                symbol = event["symbol"]
                counts["public_trade"] = counts.get("public_trade", 0) + 1
                payload = event["payload"]
                origin = payload.get("origin", "")
                origins[f"public_trade:{origin}"] = origins.get(f"public_trade:{origin}", 0) + 1
                if symbol not in trades:
                    continue
                ts = parse_timestamp_us(event["exchange_timestamp"])
                recv = parse_timestamp_us(event["received_timestamp"])
                taker = payload.get("taker_side", payload.get("side"))
                size = float(payload.get("size_contracts", payload.get("size", "0")))
                live = 1 if origin.startswith("ws_") else 0
                trades[symbol].append((ts, recv, float(payload["price"]), size, 1 if taker == "buy" else 0, live))
                if live:
                    latency_trades.append((recv - ts,))
            elif _INSTRUMENT in line:
                event = _loads(line)
                counts["instrument_metadata"] = counts.get("instrument_metadata", 0) + 1
                if event["symbol"] in wanted and event["symbol"] not in instruments:
                    instruments[event["symbol"]] = instrument_from_payload(event["symbol"], event["payload"])
            elif _BOUNDARY in line or _CONNECTION in line:
                event = _loads(line)
                counts[event["channel"]] = counts.get(event["channel"], 0) + 1
                record = {
                    "symbol": event["symbol"],
                    "ts": parse_timestamp_us(event["exchange_timestamp"]),
                    **{key: value for key, value in event["payload"].items() if key in ("reason", "state", "connection_epoch", "source_epoch")},
                }
                (boundaries if event["channel"] == "snapshot_boundary" else connections).append(record)
    actual = digest.hexdigest()
    if actual != manifest["events_file_sha256"]:
        raise ValueError(f"events file hash mismatch: manifest {manifest['events_file_sha256']} actual {actual}")
    if lines != int(manifest["event_count"]):
        raise ValueError(f"event count mismatch: manifest {manifest['event_count']} actual {lines}")
    missing = [symbol for symbol in wanted if symbol not in instruments]
    if missing:
        raise ValueError(f"instrument metadata missing for {missing}")
    quote_arrays = {symbol: buffer.finish() for symbol, buffer in quotes.items()}
    trade_arrays = {symbol: buffer.finish() for symbol, buffer in trades.items()}
    for symbol in wanted:
        # Contract sizes become base units so that notionals are price × size.
        value = instruments[symbol].contract_value
        if value != 1.0:
            quote_arrays[symbol]["bid_size"] *= value
            quote_arrays[symbol]["ask_size"] *= value
            trade_arrays[symbol]["size"] *= value
    receipt = {
        "dataset_id": manifest["dataset_id"],
        "venue": manifest["source_venue"],
        "network": manifest.get("source_network"),
        "events_file_sha256": actual,
        "event_count": lines,
        "symbols": list(wanted),
        "counts": counts,
        "origins": origins,
        "synthetic_quotes_skipped": synthetic_quotes,
        "crossed_quotes_skipped": crossed_quotes,
        "snapshot_boundaries": boundaries,
        "connection_states": connections,
        "latency_ms": {
            "quotes": _percentiles(latency_quotes.finish()["ts"] / 1000.0),
            "trades": _percentiles(latency_trades.finish()["ts"] / 1000.0),
        },
        "per_symbol": {
            symbol: _symbol_summary(quote_arrays[symbol], trade_arrays[symbol], instruments[symbol]) for symbol in wanted
        },
    }
    return Extract(manifest["source_venue"], manifest["dataset_id"], quote_arrays, trade_arrays, instruments, receipt)


def _percentiles(values: np.ndarray) -> dict:
    if values.size == 0:
        return {"count": 0}
    quantiles = np.quantile(values, [0.5, 0.9, 0.99])
    return {
        "count": int(values.size),
        "p50": float(quantiles[0]),
        "p90": float(quantiles[1]),
        "p99": float(quantiles[2]),
        "max": float(values.max()),
    }


def _symbol_summary(quotes: np.ndarray, trades: np.ndarray, instrument: Instrument) -> dict:
    summary: dict = {"quotes": int(quotes.size), "trades": int(trades.size)}
    if quotes.size:
        ticks = instrument.tick_array(quotes["bid"])
        spread_ticks = (quotes["ask"] - quotes["bid"]) / ticks
        mid = (quotes["ask"] + quotes["bid"]) / 2.0
        summary.update(
            {
                "first_quote": _iso(int(quotes["ts"][0])),
                "last_quote": _iso(int(quotes["ts"][-1])),
                "spread_ticks_p50": float(np.median(spread_ticks)),
                "spread_ticks_p90": float(np.quantile(spread_ticks, 0.9)),
                "spread_bp_mean": float(np.mean((quotes["ask"] - quotes["bid"]) / mid * 1e4)),
                "tick_bp_at_median_price": float(instrument.tick(float(np.median(mid))) / float(np.median(mid)) * 1e4),
                "quote_gap_s_p99": float(np.quantile(np.diff(quotes["ts"]) / 1e6, 0.99)) if quotes.size > 1 else None,
                "quote_gap_s_max": float(np.max(np.diff(quotes["ts"])) / 1e6) if quotes.size > 1 else None,
            }
        )
    if trades.size:
        summary.update(
            {
                "first_trade": _iso(int(trades["ts"][0])),
                "last_trade": _iso(int(trades["ts"][-1])),
                "live_share": float(np.mean(trades["live"])),
                "taker_buy_share": float(np.mean(trades["taker_buy"])),
                "notional_quote": float(np.sum(trades["price"] * trades["size"])),
                "trade_gap_s_max": float(np.max(np.diff(trades["ts"])) / 1e6) if trades.size > 1 else None,
            }
        )
    return summary


def _iso(ts_us: int) -> str:
    return datetime.fromtimestamp(ts_us / 1e6, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ")


def save_extract(extract: Extract, path: Path) -> None:
    arrays: dict[str, np.ndarray] = {}
    for symbol in extract.quotes:
        arrays[f"quotes_{symbol}"] = extract.quotes[symbol]
        arrays[f"trades_{symbol}"] = extract.trades[symbol]
    meta = {
        "venue": extract.venue,
        "dataset_id": extract.dataset_id,
        "symbols": list(extract.quotes),
        "instruments": {symbol: instrument.to_dict() for symbol, instrument in extract.instruments.items()},
    }
    arrays["meta"] = np.frombuffer(json.dumps(meta, sort_keys=True).encode(), dtype=np.uint8)
    np.savez(path, **arrays)


def load_extract(path: Path) -> Extract:
    with np.load(path) as data:
        meta = json.loads(bytes(data["meta"]).decode())
        quotes = {symbol: data[f"quotes_{symbol}"] for symbol in meta["symbols"]}
        trades = {symbol: data[f"trades_{symbol}"] for symbol in meta["symbols"]}
    instruments = {symbol: Instrument.from_dict(item) for symbol, item in meta["instruments"].items()}
    return Extract(meta["venue"], meta["dataset_id"], quotes, trades, instruments, {})
