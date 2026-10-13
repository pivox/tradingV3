"""Forward capture of OKX USDT perpetual public data into the research tape layout (fwd-okx-r1).

    python -m scripts.research_exec.forward_okx capture --out DIR [--symbols BTCUSDT,…] [--depth-every 30]

Public data only (no credential, no private endpoint, no order): the `trades` and `bbo-tbt`
channels of the public WebSocket, the full book over REST every `--depth-every` seconds, the
instrument list once. Everything is stamped with the exchange timestamps. One UTC day of one
symbol gives three npz files in the layout of the Binance tapes, so `screen.py` replays them
unchanged (`--source binance --protocol fwd-okx-r1`):

- prints → `npz/<SYMBOL>/<SYMBOL>-<date>.npz` with `ts_us`, `price`, `size` (base units),
  `taker_buy`, `agg_trade_id` (OKX `tradeId`), sorted by (ts_us, id), unique ids;
- top of book → `book/<SYMBOL>/<SYMBOL>-<date>.npz`: one record per second with at least one
  update (`ts_s`, `bid`, `ask`, `bid_qty`, `ask_qty` in base units, `updates`, `qi_mean`), the
  reduction of `acquire.convert_bookticker`;
- depth → `depth/<SYMBOL>/<SYMBOL>-<date>.npz`: `ts_s`, `bid` and `ask` (n × 5, cumulative
  notional in USDT within 1 … 5 % of the mid), the layout of the Binance `bookDepth` archive,
  plus `coverage_bid_pct` / `coverage_ask_pct` (how far the returned book reaches: the REST
  endpoint returns at most 5,000 levels per side, which truncates the ±1 % band of BTC).

Symbols are the Binance-style names of the tapes (`BTCUSDT` ↔ `BTC-USDT-SWAP`). Sizes are
converted from contracts with the instrument's `ctVal`. The current day is checkpointed every
`--checkpoint-every` seconds (files overwritten, `partial: true` in the manifest) and finalized
after midnight UTC; `manifest-forward.json` records every file (rows, sha256), every WebSocket
disconnect, every REST failure. On reconnect the last 500 prints of each symbol are fetched
over REST and the unseen ones merged (short gaps recovered; longer ones recorded).
"""

from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
import sys
import time
import urllib.request
from array import array
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

try:
    import orjson as _json_fast
except ImportError:  # pragma: no cover - orjson is optional
    _json_fast = None

REST = "https://www.okx.com"
WS_PUBLIC = "wss://ws.okx.com:8443/ws/v5/public"
LEVELS = (1, 2, 3, 4, 5)
DEPTH_BOOK_SIZE = 5000  # maximum of /api/v5/market/books-full
DEFAULT_SYMBOLS = ("BTCUSDT", "ARBUSDT", "ETHUSDT", "OPUSDT", "SOLUSDT", "APTUSDT", "XRPUSDT", "SUIUSDT", "DOGEUSDT", "NEARUSDT",
                   "BNBUSDT", "TIAUSDT", "ADAUSDT", "SEIUSDT", "LTCUSDT", "INJUSDT", "LINKUSDT", "ATOMUSDT", "AVAXUSDT", "FILUSDT")
DAY_S = 86400
FINALIZE_GRACE_S = 90  # a day is closed this long after midnight (late prints of the day are rare but possible)
WS_PING_S = 20
WS_RECONNECT_MAX_S = 30
REST_SPACING_S = 0.3  # /market/books-full allows 10 requests per 2 s per IP


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _log(message: str) -> None:
    print(f"{_now()} {message}", file=sys.stderr, flush=True)


def _loads(raw):
    return _json_fast.loads(raw) if _json_fast is not None else json.loads(raw)


def inst_id(symbol: str) -> str:
    if not symbol.endswith("USDT"):
        raise ValueError(f"unsupported symbol {symbol}")
    return f"{symbol[:-4]}-USDT-SWAP"


def symbol_of(instrument_id: str) -> str:
    base, quote, kind = instrument_id.split("-")
    if quote != "USDT" or kind != "SWAP":
        raise ValueError(f"unsupported instrument {instrument_id}")
    return f"{base}{quote}"


def day_of_ms(ts_ms: int) -> int:
    """UTC day index of a millisecond exchange timestamp."""
    return int(ts_ms // 1000 // DAY_S)


def date_of_day(day: int) -> str:
    return datetime.fromtimestamp(day * DAY_S, tz=timezone.utc).strftime("%Y-%m-%d")


# ----------------------------------------------------------------------------- pure reductions


class SecondReducer:
    """Incremental per-second reduction of best bid/ask updates: the last update at or before the
    end of each second with its quantities, the number of updates and the mean queue imbalance
    of the second's updates (the layout of `acquire.convert_bookticker`). Updates must arrive in
    non-decreasing time order; an older one is counted in the current second."""

    def __init__(self) -> None:
        self.ts_s = array("q")
        self.bid = array("d")
        self.ask = array("d")
        self.bid_qty = array("d")
        self.ask_qty = array("d")
        self.updates = array("i")
        self.qi_mean = array("d")
        self._sec = -1
        self._last: tuple[float, float, float, float] | None = None
        self._count = 0
        self._qi_sum = 0.0
        self._qi_cnt = 0

    def push(self, ts_ms: int, bid: float, bid_qty: float, ask: float, ask_qty: float) -> None:
        sec = int(ts_ms // 1000)
        if self._sec >= 0 and sec > self._sec:
            self._flush()
        if self._sec < 0 or sec > self._sec:
            self._sec = sec
        self._last = (bid, ask, bid_qty, ask_qty)
        self._count += 1
        total = bid_qty + ask_qty
        if total > 0:
            self._qi_sum += (bid_qty - ask_qty) / total
            self._qi_cnt += 1

    def _flush(self) -> None:
        if self._last is None:
            return
        bid, ask, bq, aq = self._last
        self.ts_s.append(self._sec)
        self.bid.append(bid)
        self.ask.append(ask)
        self.bid_qty.append(bq)
        self.ask_qty.append(aq)
        self.updates.append(self._count)
        self.qi_mean.append(self._qi_sum / self._qi_cnt if self._qi_cnt else float("nan"))
        self._last, self._count, self._qi_sum, self._qi_cnt = None, 0, 0.0, 0

    def arrays(self, include_open: bool) -> dict[str, np.ndarray]:
        """Flushed seconds, plus the second in progress when `include_open` (checkpoint of a
        running day); the running second stays open for later updates."""
        out = {"ts_s": np.array(self.ts_s, dtype=np.int64), "bid": np.array(self.bid), "ask": np.array(self.ask), "bid_qty": np.array(self.bid_qty), "ask_qty": np.array(self.ask_qty),
               "updates": np.array(self.updates, dtype=np.int32), "qi_mean": np.array(self.qi_mean)}
        if include_open and self._last is not None:
            bid, ask, bq, aq = self._last
            out["ts_s"] = np.append(out["ts_s"], np.int64(self._sec))
            out["bid"], out["ask"] = np.append(out["bid"], bid), np.append(out["ask"], ask)
            out["bid_qty"], out["ask_qty"] = np.append(out["bid_qty"], bq), np.append(out["ask_qty"], aq)
            out["updates"] = np.append(out["updates"], np.int32(self._count))
            out["qi_mean"] = np.append(out["qi_mean"], self._qi_sum / self._qi_cnt if self._qi_cnt else np.nan)
        return out

    def close(self) -> None:
        self._flush()


class TradeBuffer:
    """Prints of one symbol-day; duplicates (same OKX trade id) are dropped at `arrays()`."""

    def __init__(self) -> None:
        self.ts_us = array("q")
        self.price = array("d")
        self.size = array("d")
        self.taker_buy = array("b")
        self.trade_id = array("q")

    def push(self, ts_ms: int, price: float, size: float, taker_buy: bool, trade_id: int) -> None:
        self.ts_us.append(int(ts_ms) * 1000)
        self.price.append(price)
        self.size.append(size)
        self.taker_buy.append(1 if taker_buy else 0)
        self.trade_id.append(trade_id)

    def __len__(self) -> int:
        return len(self.ts_us)

    def arrays(self) -> dict[str, np.ndarray]:
        ts_us = np.array(self.ts_us, dtype=np.int64)
        ids = np.array(self.trade_id, dtype=np.int64)
        order = np.lexsort((ids, ts_us))
        ts_us, ids = ts_us[order], ids[order]
        keep = np.ones(ids.size, dtype=bool)
        if ids.size > 1:
            seen_order = np.argsort(ids, kind="stable")
            sorted_ids = ids[seen_order]
            dup = np.zeros(ids.size, dtype=bool)
            dup[seen_order[1:]] = sorted_ids[1:] == sorted_ids[:-1]
            keep = ~dup
        return {"ts_us": ts_us[keep], "price": np.array(self.price)[order][keep], "size": np.array(self.size)[order][keep],
                "taker_buy": np.array(self.taker_buy, dtype=np.int8)[order][keep], "agg_trade_id": ids[keep]}


def depth_bands(bids: np.ndarray, asks: np.ndarray, levels: tuple[int, ...] = LEVELS) -> dict:
    """Cumulative notional (price × size, sizes in base units) within `b` % of the mid on each
    side, for `b` in `levels`; `bids`/`asks` are (n, 2) arrays of price and size, best first.
    `coverage_*_pct` is how far from the mid the deepest returned level lies (the book may be
    truncated by the endpoint, which understates the wider bands)."""
    bids = np.asarray(bids, dtype=np.float64).reshape(-1, 2)
    asks = np.asarray(asks, dtype=np.float64).reshape(-1, 2)
    if bids.shape[0] == 0 or asks.shape[0] == 0:
        raise ValueError("empty book side")
    best_bid, best_ask = bids[:, 0].max(), asks[:, 0].min()
    mid = (best_bid + best_ask) / 2.0
    out_bid, out_ask = [], []
    bid_dist = (mid - bids[:, 0]) / mid * 100.0
    ask_dist = (asks[:, 0] - mid) / mid * 100.0
    bid_notional = bids[:, 0] * bids[:, 1]
    ask_notional = asks[:, 0] * asks[:, 1]
    for b in levels:
        out_bid.append(float(bid_notional[bid_dist <= b].sum()))
        out_ask.append(float(ask_notional[ask_dist <= b].sum()))
    return {"mid": float(mid), "bid": out_bid, "ask": out_ask, "coverage_bid_pct": float(bid_dist.max()), "coverage_ask_pct": float(ask_dist.max()),
            "levels_bid": int(bids.shape[0]), "levels_ask": int(asks.shape[0])}


class DepthBuffer:
    def __init__(self) -> None:
        self.ts_s = array("q")
        self.bid = array("d")
        self.ask = array("d")
        self.coverage_bid = array("d")
        self.coverage_ask = array("d")

    def push(self, ts_s: int, bands: dict) -> None:
        self.ts_s.append(int(ts_s))
        self.bid.extend(bands["bid"])
        self.ask.extend(bands["ask"])
        self.coverage_bid.append(bands["coverage_bid_pct"])
        self.coverage_ask.append(bands["coverage_ask_pct"])

    def __len__(self) -> int:
        return len(self.ts_s)

    def arrays(self) -> dict[str, np.ndarray]:
        n = len(self.ts_s)
        return {"ts_s": np.array(self.ts_s, dtype=np.int64), "bid": np.array(self.bid).reshape(n, len(LEVELS)), "ask": np.array(self.ask).reshape(n, len(LEVELS)),
                "coverage_bid_pct": np.array(self.coverage_bid), "coverage_ask_pct": np.array(self.coverage_ask)}


# ----------------------------------------------------------------------------- instruments


def instrument_record(raw: dict, mid: float) -> dict:
    """One entry of the instrument-assumptions file (the r8 layout) from an OKX `instruments`
    row: sizes are converted to base units, so `contract_size` is 1; `min_notional` is the
    minimum order at `mid`."""
    ct_val, lot, min_sz, tick = float(raw["ctVal"]), float(raw["lotSz"]), float(raw["minSz"]), float(raw["tickSz"])
    return {
        "symbol": symbol_of(raw["instId"]), "okx_inst_id": raw["instId"], "contract_size": 1.0, "okx_ct_val": ct_val, "tick_size": tick,
        "min_quantity": min_sz * ct_val, "quantity_step": lot * ct_val, "min_notional": round(min_sz * ct_val * mid, 4), "max_quantity": float(raw.get("maxMktSz", 0) or 0) * ct_val or None,
        "leverage_cap": 2.0, "liquidation_fee_rate": 0.005, "mmr_proxy_rate": 0.01, "okx_max_lmt_sz": raw.get("maxLmtSz"), "okx_state": raw.get("state"),
    }


# ----------------------------------------------------------------------------- store


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


class SymbolDay:
    def __init__(self) -> None:
        self.trades = TradeBuffer()
        self.book = SecondReducer()
        self.depth = DepthBuffer()


class TapeStore:
    """Per (symbol, day) buffers and their npz files under `out_dir`."""

    def __init__(self, out_dir: Path, symbols: tuple[str, ...]) -> None:
        self.out_dir = out_dir
        self.symbols = symbols
        self.days: dict[tuple[str, int], SymbolDay] = {}
        self.manifest_path = out_dir / "manifest-forward.json"
        self.manifest = self._load_manifest()

    def _load_manifest(self) -> dict:
        if self.manifest_path.exists():
            return json.loads(self.manifest_path.read_text())
        return {"kind": "forward-okx", "source": {"ws": WS_PUBLIC, "rest": REST, "channels": ["trades", "bbo-tbt"], "depth": "/api/v5/market/books-full"},
                "symbols": list(self.symbols), "created_at": _now(), "files": {}, "disconnects": [], "rest_failures": [], "sessions": []}

    def save_manifest(self) -> None:
        self.manifest["updated_at"] = _now()
        tmp = self.manifest_path.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(self.manifest, indent=1, sort_keys=True) + "\n")
        tmp.replace(self.manifest_path)

    def bucket(self, symbol: str, ts_ms: int) -> SymbolDay:
        key = (symbol, day_of_ms(ts_ms))
        store = self.days.get(key)
        if store is None:
            store = self.days[key] = SymbolDay()
        return store

    def open_days(self) -> list[int]:
        return sorted({day for _, day in self.days})

    def write(self, symbol: str, day: int, final: bool) -> dict | None:
        store = self.days.get((symbol, day))
        if store is None:
            return None
        date = date_of_day(day)
        entry: dict = {"symbol": symbol, "date": date, "partial": not final, "written_at": _now()}
        if final:
            store.book.close()
        for folder, arrays, first_key in (("npz", store.trades.arrays(), "ts_us"), ("book", store.book.arrays(include_open=not final), "ts_s"), ("depth", store.depth.arrays(), "ts_s")):
            target = self.out_dir / folder / symbol / f"{symbol}-{date}.npz"
            target.parent.mkdir(parents=True, exist_ok=True)
            tmp = target.with_suffix(".npz.tmp")
            with tmp.open("wb") as handle:  # a file object: savez would append ".npz" to a path without that suffix
                np.savez_compressed(handle, **arrays)
            tmp.replace(target)
            rows = int(arrays[first_key].size)
            entry[folder] = {"rows": rows, "first_ts": int(arrays[first_key][0]) if rows else None, "last_ts": int(arrays[first_key][-1]) if rows else None,
                             "npz_sha256": _sha256(target) if final else None, "npz_bytes": target.stat().st_size}
        if final:
            depth = store.depth.arrays()
            entry["depth"]["coverage_bid_pct_min"] = float(depth["coverage_bid_pct"].min()) if depth["ts_s"].size else None
            entry["depth"]["coverage_ask_pct_min"] = float(depth["coverage_ask_pct"].min()) if depth["ts_s"].size else None
            gaps = np.diff(depth["ts_s"]) if depth["ts_s"].size > 1 else np.zeros(0, dtype=np.int64)
            entry["depth"]["gap_s_max"] = int(gaps.max()) if gaps.size else None
        self.manifest["files"][f"{symbol}:{date}"] = entry
        return entry

    def finalize_days_before(self, day: int) -> list[str]:
        """Write and drop every (symbol, day) bucket older than `day`."""
        done = []
        for key in sorted(k for k in self.days if k[1] < day):
            self.write(key[0], key[1], final=True)
            del self.days[key]
            done.append(f"{key[0]}:{date_of_day(key[1])}")
        return done

    def checkpoint(self) -> int:
        count = 0
        for symbol, day in list(self.days):
            self.write(symbol, day, final=False)
            count += 1
        self.save_manifest()
        return count


# ----------------------------------------------------------------------------- REST


def rest_get(path: str, timeout: float = 20.0) -> dict:
    request = urllib.request.Request(REST + path, headers={"User-Agent": "tradingV3-research-forward/1.0"})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        document = _loads(response.read())
    if str(document.get("code")) != "0":
        raise RuntimeError(f"OKX REST {path}: {document.get('code')} {document.get('msg')}")
    return document


def fetch_instruments(symbols: tuple[str, ...]) -> dict[str, dict]:
    rows = rest_get("/api/v5/public/instruments?instType=SWAP")["data"]
    by_id = {row["instId"]: row for row in rows}
    out = {}
    for symbol in symbols:
        row = by_id.get(inst_id(symbol))
        if row is None or row.get("state") != "live":
            raise RuntimeError(f"{symbol}: instrument {inst_id(symbol)} absent or not live on OKX")
        out[symbol] = row
    return out


def fetch_depth(symbol: str, ct_val: float) -> tuple[int, dict]:
    document = rest_get(f"/api/v5/market/books-full?instId={inst_id(symbol)}&sz={DEPTH_BOOK_SIZE}")
    data = document["data"][0]
    bids = np.array([[float(px), float(sz) * ct_val] for px, sz, *_ in data["bids"]], dtype=np.float64)
    asks = np.array([[float(px), float(sz) * ct_val] for px, sz, *_ in data["asks"]], dtype=np.float64)
    return int(data["ts"]), depth_bands(bids, asks)


def fetch_recent_trades(symbol: str) -> list[dict]:
    return rest_get(f"/api/v5/market/trades?instId={inst_id(symbol)}&limit=500")["data"]


# ----------------------------------------------------------------------------- capture


class Capture:
    def __init__(self, out_dir: Path, symbols: tuple[str, ...], depth_every: float, checkpoint_every: float, stop_at: float | None = None) -> None:
        self.out_dir = out_dir
        self.symbols = symbols
        self.depth_every = depth_every
        self.checkpoint_every = checkpoint_every
        self.stop_at = stop_at
        self.store = TapeStore(out_dir, symbols)
        self.instruments: dict[str, dict] = {}
        self.ct_val: dict[str, float] = {}
        self.last_trade_id: dict[str, int] = {}
        self.mid: dict[str, float] = {}
        self.ws_messages = 0
        self.ws_connected = False
        self.stopping = False

    # -- lifecycle

    async def run(self) -> None:
        self.out_dir.mkdir(parents=True, exist_ok=True)
        self.instruments = await asyncio.to_thread(fetch_instruments, self.symbols)
        self.ct_val = {symbol: float(row["ctVal"]) for symbol, row in self.instruments.items()}
        self.store.manifest["sessions"].append({"started_at": _now(), "pid": __import__("os").getpid(), "symbols": list(self.symbols)})
        self.store.save_manifest()
        _log(f"capture: {len(self.symbols)} symbols into {self.out_dir}")
        tasks = [asyncio.create_task(self.ws_loop(), name="ws"), asyncio.create_task(self.depth_loop(), name="depth"), asyncio.create_task(self.housekeeping_loop(), name="housekeeping")]
        try:
            await asyncio.gather(*tasks)
        finally:
            for task in tasks:
                task.cancel()
            self.store.checkpoint()
            _log("capture: stopped, checkpoint written")

    def _should_stop(self) -> bool:
        return self.stopping or (self.stop_at is not None and time.time() >= self.stop_at)

    # -- WebSocket

    async def ws_loop(self) -> None:
        import websockets

        backoff = 1.0
        while not self._should_stop():
            try:
                async with websockets.connect(WS_PUBLIC, ping_interval=None, max_size=1 << 24, open_timeout=20) as ws:
                    args = [{"channel": channel, "instId": inst_id(symbol)} for symbol in self.symbols for channel in ("trades", "bbo-tbt")]
                    await ws.send(json.dumps({"op": "subscribe", "args": args}))
                    self.ws_connected = True
                    backoff = 1.0
                    _log("ws: connected and subscribed")
                    if self.last_trade_id:
                        asyncio.create_task(self.recover_trades())
                    pinger = asyncio.create_task(self._pinger(ws))
                    try:
                        while not self._should_stop():
                            raw = await asyncio.wait_for(ws.recv(), timeout=WS_PING_S * 2)
                            self._on_message(raw)
                    finally:
                        pinger.cancel()
            except asyncio.CancelledError:
                raise
            except Exception as error:  # noqa: BLE001 - recorded, reconnected
                if self.ws_connected:
                    self.store.manifest["disconnects"].append({"at": _now(), "error": str(error)[:200]})
                    _log(f"ws: disconnected ({str(error)[:120]}); reconnecting in {backoff:.0f} s")
                else:
                    _log(f"ws: connection failed ({str(error)[:120]}); retrying in {backoff:.0f} s")
                self.ws_connected = False
                await asyncio.sleep(backoff)
                backoff = min(backoff * 2.0, WS_RECONNECT_MAX_S)

    async def _pinger(self, ws) -> None:
        while True:
            await asyncio.sleep(WS_PING_S)
            await ws.send("ping")

    def _on_message(self, raw) -> None:
        if raw == "pong" or raw == b"pong":
            return
        message = _loads(raw)
        if "event" in message:
            if message["event"] == "error":
                _log(f"ws: error event {json.dumps(message)[:200]}")
            return
        arg = message.get("arg")
        data = message.get("data")
        if not arg or not data:
            return
        self.ws_messages += 1
        symbol = symbol_of(arg["instId"])
        ct_val = self.ct_val[symbol]
        if arg["channel"] == "trades":
            for row in data:
                ts_ms = int(row["ts"])
                trade_id = int(row["tradeId"])
                self.store.bucket(symbol, ts_ms).trades.push(ts_ms, float(row["px"]), float(row["sz"]) * ct_val, row["side"] == "buy", trade_id)
                if trade_id > self.last_trade_id.get(symbol, -1):
                    self.last_trade_id[symbol] = trade_id
        elif arg["channel"] == "bbo-tbt":
            for row in data:
                if not row.get("bids") or not row.get("asks"):
                    continue
                ts_ms = int(row["ts"])
                bid = row["bids"][0]
                ask = row["asks"][0]
                self.store.bucket(symbol, ts_ms).book.push(ts_ms, float(bid[0]), float(bid[1]) * ct_val, float(ask[0]), float(ask[1]) * ct_val)

    async def recover_trades(self) -> None:
        """After a reconnect: the last 500 prints of each symbol over REST, unseen ids merged."""
        recovered = 0
        for symbol in self.symbols:
            try:
                rows = await asyncio.to_thread(fetch_recent_trades, symbol)
            except Exception as error:  # noqa: BLE001
                self.store.manifest["rest_failures"].append({"at": _now(), "what": f"recover {symbol}", "error": str(error)[:200]})
                continue
            last = self.last_trade_id.get(symbol, -1)
            ct_val = self.ct_val[symbol]
            for row in rows:
                trade_id = int(row["tradeId"])
                if trade_id > last:
                    ts_ms = int(row["ts"])
                    self.store.bucket(symbol, ts_ms).trades.push(ts_ms, float(row["px"]), float(row["sz"]) * ct_val, row["side"] == "buy", trade_id)
                    recovered += 1
            await asyncio.sleep(REST_SPACING_S)
        self.store.manifest["disconnects"][-1]["recovered_prints"] = recovered
        _log(f"ws: recovery merged {recovered} prints")

    # -- depth

    async def depth_loop(self) -> None:
        instruments_written = False
        while not self._should_stop():
            cycle_started = time.time()
            for symbol in self.symbols:
                if self._should_stop():
                    return
                try:
                    ts_ms, bands = await asyncio.to_thread(fetch_depth, symbol, self.ct_val[symbol])
                    self.store.bucket(symbol, ts_ms).depth.push(ts_ms // 1000, bands)
                    self.mid[symbol] = bands["mid"]
                except Exception as error:  # noqa: BLE001 - recorded, the cycle continues
                    self.store.manifest["rest_failures"].append({"at": _now(), "what": f"depth {symbol}", "error": str(error)[:200]})
                    _log(f"depth: {symbol} failed ({str(error)[:120]})")
                await asyncio.sleep(REST_SPACING_S)
            if not instruments_written and len(self.mid) == len(self.symbols):
                self.write_instruments()
                instruments_written = True
            await asyncio.sleep(max(0.0, self.depth_every - (time.time() - cycle_started)))

    def write_instruments(self) -> None:
        document = {"source": f"OKX /api/v5/public/instruments?instType=SWAP read at {_now()}; sizes in base units (contracts × ctVal); min_notional at the mid of the first depth cycle",
                    "symbols": [instrument_record(self.instruments[symbol], self.mid[symbol]) for symbol in self.symbols]}
        path = self.out_dir / "instrument-assumptions-okx.json"
        if not path.exists():
            path.write_text(json.dumps(document, indent=1) + "\n")
            _log(f"instruments: written {path}")

    # -- housekeeping

    async def housekeeping_loop(self) -> None:
        last_checkpoint = time.time()
        while not self._should_stop():
            await asyncio.sleep(5.0)
            now = time.time()
            today = int(now // DAY_S)
            if now - today * DAY_S >= FINALIZE_GRACE_S:
                done = self.store.finalize_days_before(today)
                if done:
                    self.store.save_manifest()
                    _log(f"finalized {len(done)} symbol-days ({done[0]} …)")
            if now - last_checkpoint >= self.checkpoint_every:
                count = self.store.checkpoint()
                last_checkpoint = now
                _log(f"checkpoint: {count} symbol-days, {self.ws_messages} ws messages so far, connected={self.ws_connected}")
        self.stopping = True


# ----------------------------------------------------------------------------- CLI


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_exec.forward_okx")
    sub = parser.add_subparsers(dest="command", required=True)
    cap = sub.add_parser("capture")
    cap.add_argument("--out", required=True)
    cap.add_argument("--symbols", default=",".join(DEFAULT_SYMBOLS))
    cap.add_argument("--depth-every", type=float, default=30.0)
    cap.add_argument("--checkpoint-every", type=float, default=600.0)
    cap.add_argument("--duration-s", type=float, default=0.0, help="stop after this many seconds (0 = run until killed)")
    args = parser.parse_args(argv)
    symbols = tuple(s for s in args.symbols.split(",") if s)
    stop_at = time.time() + args.duration_s if args.duration_s > 0 else None
    capture = Capture(Path(args.out), symbols, args.depth_every, args.checkpoint_every, stop_at)
    try:
        asyncio.run(capture.run())
    except KeyboardInterrupt:
        _log("capture: interrupted")
    return 0


if __name__ == "__main__":
    sys.exit(main())
