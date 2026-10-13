"""Acquisition of Binance USD-M daily archives (data.binance.vision) into the research layout.

    python -m scripts.research_exec.acquire aggtrades --out DIR --symbols BTCUSDT,ETHUSDT --dates 2025-01-13..2025-01-17
    python -m scripts.research_exec.acquire bookdepth --out DIR --symbols BTCUSDT --dates 2025-02-10..2025-02-14,2025-03-10..2025-03-14

Every zip is downloaded to a staging file, its sha256 checked against the published `.CHECKSUM`
(fail-closed), converted and deleted; `manifest-<kind>.json` records each file (hashes, rows,
first and last timestamps, diagnostics). Files already in the manifest are skipped.

Layouts (one npz per symbol-day, `numpy.savez_compressed`):

- aggTrades → `npz/<SYMBOL>/<SYMBOL>-<date>.npz` with `ts_us`, `price`, `size`, `taker_buy`,
  `agg_trade_id`, sorted by (ts_us, agg_trade_id): the layout of the 2026-10-12 tape.
- bookDepth → `depth/<SYMBOL>/<SYMBOL>-<date>.npz` with `ts_s` (snapshot time, seconds UTC),
  `bid` and `ask` (n × 5, cumulative notional within 1 … 5 % of the mid on that side); snapshots
  missing one of the ten levels are dropped and counted.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import sys
import time
import urllib.request
import zipfile
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from pathlib import Path

import numpy as np
import pandas as pd

BASE = "https://data.binance.vision/data/futures/um/daily"
KINDS = {"aggtrades": ("aggTrades", "npz"), "bookdepth": ("bookDepth", "depth"), "bookticker": ("bookTicker", "book")}
LEVELS = (1, 2, 3, 4, 5)
AGG_COLUMNS = ("agg_trade_id", "price", "quantity", "first_trade_id", "last_trade_id", "transact_time", "is_buyer_maker")
BOOK_COLUMNS = ("update_id", "best_bid_price", "best_bid_qty", "best_ask_price", "best_ask_qty", "transaction_time", "event_time")


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _log(message: str) -> None:
    print(f"{_now()} {message}", file=sys.stderr, flush=True)


def parse_dates(spec: str) -> list[str]:
    """`2025-01-13..2025-01-17,2025-03-10` → every calendar day of each range, in order."""
    out: list[str] = []
    for item in spec.split(","):
        item = item.strip()
        if not item:
            continue
        if ".." in item:
            first, last = item.split("..")
            day = datetime.strptime(first, "%Y-%m-%d")
            end = datetime.strptime(last, "%Y-%m-%d")
            while day <= end:
                out.append(day.strftime("%Y-%m-%d"))
                day += timedelta(days=1)
        else:
            out.append(datetime.strptime(item, "%Y-%m-%d").strftime("%Y-%m-%d"))
    return out


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _fetch(url: str, dest: Path, attempts: int = 4) -> None:
    last: Exception | None = None
    for attempt in range(attempts):
        try:
            with urllib.request.urlopen(url, timeout=120) as response, dest.open("wb") as handle:
                while True:
                    chunk = response.read(1 << 20)
                    if not chunk:
                        break
                    handle.write(chunk)
            return
        except Exception as error:  # noqa: BLE001 - retried, then raised
            last = error
            time.sleep(2.0 * (attempt + 1))
    raise RuntimeError(f"download failed after {attempts} attempts: {url}: {last}")


def _expected_sha256(url: str) -> str:
    with urllib.request.urlopen(url + ".CHECKSUM", timeout=60) as response:
        text = response.read().decode("ascii", errors="replace")
    return text.split()[0].strip().lower()


def convert_aggtrades(raw: bytes) -> tuple[dict[str, np.ndarray], dict]:
    frame = _read_csv(raw, AGG_COLUMNS, {"agg_trade_id": "int64", "price": "float64", "quantity": "float64", "transact_time": "int64"})
    order = np.lexsort((frame["agg_trade_id"].to_numpy(), frame["transact_time"].to_numpy()))
    ts_us = frame["transact_time"].to_numpy()[order] * 1000
    maker = frame["is_buyer_maker"].to_numpy()[order]
    taker_buy = np.where(_falsy(maker), 1, 0).astype(np.int8)
    arrays = {"ts_us": ts_us.astype(np.int64), "price": frame["price"].to_numpy()[order], "size": frame["quantity"].to_numpy()[order], "taker_buy": taker_buy, "agg_trade_id": frame["agg_trade_id"].to_numpy()[order].astype(np.int64)}
    diag = {"input_already_sorted": bool(np.all(order == np.arange(order.size))), "taker_buy_share": float(taker_buy.mean()) if taker_buy.size else None, "price_min": float(arrays["price"].min()) if ts_us.size else None, "price_max": float(arrays["price"].max()) if ts_us.size else None, "agg_id_unique": bool(np.unique(arrays["agg_trade_id"]).size == ts_us.size)}
    return arrays, diag


def _falsy(values: np.ndarray) -> np.ndarray:
    if values.dtype.kind == "b":
        return ~values
    text = np.char.lower(values.astype(str))
    return (text == "false") | (text == "0")


def _read_csv(source, columns: tuple[str, ...], dtypes: dict, usecols: tuple[str, ...] | None = None) -> pd.DataFrame:
    """`source` is the CSV as bytes or a binary stream (a zip member read lazily: the bookTicker
    day of a major is over a gigabyte of text, never held whole)."""
    stream = io.BytesIO(source) if isinstance(source, (bytes, bytearray)) else source
    head = stream.peek(200)[:200].split(b"\n", 1)[0].decode("ascii", errors="replace") if hasattr(stream, "peek") else stream.getvalue()[:200].split(b"\n", 1)[0].decode("ascii", errors="replace")
    has_header = head.split(",")[0].strip() == columns[0]
    return pd.read_csv(stream, header=0 if has_header else None, names=None if has_header else list(columns), dtype=dtypes, usecols=list(usecols) if usecols else None, engine="c")


def convert_bookdepth(raw: bytes) -> tuple[dict[str, np.ndarray], dict]:
    frame = _read_csv(raw, ("timestamp", "percentage", "depth", "notional"), {"percentage": "int64", "depth": "float64", "notional": "float64"})
    ts = pd.to_datetime(frame["timestamp"], format="%Y-%m-%d %H:%M:%S", utc=True).astype("int64").to_numpy() // 10**9
    frame = frame.assign(ts_s=ts)
    table = frame.pivot_table(index="ts_s", columns="percentage", values="notional", aggfunc="last")
    wanted = [-level for level in LEVELS] + list(LEVELS)
    for column in wanted:
        if column not in table.columns:
            table[column] = np.nan
    table = table[wanted]
    complete = table.notna().all(axis=1).to_numpy()
    kept = table[complete]
    ts_s = kept.index.to_numpy().astype(np.int64)
    bid = kept[[-level for level in LEVELS]].to_numpy(dtype=np.float64)
    ask = kept[list(LEVELS)].to_numpy(dtype=np.float64)
    gaps = np.diff(ts_s) if ts_s.size > 1 else np.zeros(0, dtype=np.int64)
    diag = {"snapshots_total": int(table.shape[0]), "snapshots_incomplete_dropped": int((~complete).sum()), "gap_s_p50": float(np.median(gaps)) if gaps.size else None, "gap_s_max": int(gaps.max()) if gaps.size else None, "bid1_median_usdt": float(np.median(bid[:, 0])) if ts_s.size else None, "ask1_median_usdt": float(np.median(ask[:, 0])) if ts_s.size else None}
    return {"ts_s": ts_s, "bid": bid, "ask": ask}, diag


def convert_bookticker(raw: bytes) -> tuple[dict[str, np.ndarray], dict]:
    """bookTicker (every best bid/ask update with quantities) → one record per second with at
    least one update: the last update at or before the end of the second (`bid`, `ask`,
    `bid_qty`, `ask_qty`), the number of updates and the mean queue imbalance of the second's
    updates (r9 §1). Rows are ordered by transaction time (file order for ties)."""
    used = ("best_bid_price", "best_bid_qty", "best_ask_price", "best_ask_qty", "transaction_time")
    frame = _read_csv(raw, BOOK_COLUMNS, {name: "float64" if name != "transaction_time" else "int64" for name in used}, usecols=used)
    t_ms = frame["transaction_time"].to_numpy()
    order = np.argsort(t_ms, kind="stable")
    sec = (t_ms[order] // 1000).astype(np.int64)
    bid, bq, ask, aq = (frame[column].to_numpy()[order] for column in ("best_bid_price", "best_bid_qty", "best_ask_price", "best_ask_qty"))
    del frame
    if sec.size == 0:
        empty = {"ts_s": np.zeros(0, dtype=np.int64), "bid": np.zeros(0), "ask": np.zeros(0), "bid_qty": np.zeros(0), "ask_qty": np.zeros(0), "updates": np.zeros(0, dtype=np.int32), "qi_mean": np.zeros(0)}
        return empty, {"updates_total": 0, "seconds_with_updates": 0}
    last = np.flatnonzero(np.diff(sec, append=sec[-1] + 1))  # index of the last update of each second
    counts = np.diff(np.concatenate(([0], last + 1)))
    with np.errstate(invalid="ignore", divide="ignore"):
        total = bq + aq
        qi = np.where(total > 0, (bq - aq) / total, np.nan)
        ok = np.isfinite(qi)
        qi_sum = np.cumsum(np.where(ok, qi, 0.0))[last]
        qi_cnt = np.cumsum(ok.astype(np.int64))[last]
        qi_sum[1:] -= qi_sum[:-1].copy()
        qi_cnt[1:] -= qi_cnt[:-1].copy()
        qi_mean = np.where(qi_cnt > 0, qi_sum / np.maximum(qi_cnt, 1), np.nan)
        spread_bp = (ask[last] - bid[last]) / ((ask[last] + bid[last]) / 2.0) * 1e4
    arrays = {"ts_s": sec[last], "bid": bid[last], "ask": ask[last], "bid_qty": bq[last], "ask_qty": aq[last], "updates": counts.astype(np.int32), "qi_mean": qi_mean}
    diag = {"updates_total": int(sec.size), "seconds_with_updates": int(last.size), "input_already_sorted": bool(np.all(order == np.arange(order.size))), "spread_bp_median": float(np.median(spread_bp)), "locked_or_crossed_seconds": int(np.sum(ask[last] <= bid[last])), "bid_qty_median": float(np.median(bq[last])), "ask_qty_median": float(np.median(aq[last]))}
    return arrays, diag


CONVERTERS = {"aggtrades": convert_aggtrades, "bookdepth": convert_bookdepth, "bookticker": convert_bookticker}


def acquire_one(kind: str, out_dir: Path, symbol: str, date: str) -> dict:
    name, folder = KINDS[kind]
    url = f"{BASE}/{name}/{symbol}/{symbol}-{name}-{date}.zip"
    target = out_dir / folder / symbol / f"{symbol}-{date}.npz"
    target.parent.mkdir(parents=True, exist_ok=True)
    staging = out_dir / "staging" / f"{symbol}-{name}-{date}.zip"
    staging.parent.mkdir(parents=True, exist_ok=True)
    started = time.time()
    expected = _expected_sha256(url)
    _fetch(url, staging)
    actual = _sha256(staging)
    if actual != expected:
        staging.unlink(missing_ok=True)
        raise RuntimeError(f"checksum mismatch for {url}: expected {expected}, got {actual}")
    downloaded = time.time()
    zip_bytes = staging.stat().st_size
    with zipfile.ZipFile(staging) as archive:
        inner = archive.namelist()[0]
        if kind == "bookticker":
            with archive.open(inner) as stream:  # streamed: the CSV of a major's day exceeds a gigabyte
                arrays, diag = CONVERTERS[kind](io.BufferedReader(stream, buffer_size=1 << 20))
        else:
            raw = archive.read(inner)
            arrays, diag = CONVERTERS[kind](raw)
            del raw
    staging.unlink()
    np.savez_compressed(target, **arrays)
    first_key = "ts_us" if kind == "aggtrades" else "ts_s"
    rows = int(arrays[first_key].size)
    return {
        "symbol": symbol, "date": date, "url": url, "zip_sha256_expected": expected, "zip_sha256_actual": actual, "zip_bytes": zip_bytes, "rows": rows,
        "first_ts": int(arrays[first_key][0]) if rows else None, "last_ts": int(arrays[first_key][-1]) if rows else None,
        "npz_path": str(target.relative_to(out_dir)), "npz_sha256": _sha256(target), "npz_bytes": target.stat().st_size,
        "download_seconds": round(downloaded - started, 1), "convert_seconds": round(time.time() - downloaded, 1), "diag": diag,
    }


def run(kind: str, out_dir: Path, symbols: list[str], dates: list[str], workers: int) -> dict:
    manifest_path = out_dir / f"manifest-{kind}.json"
    manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {"kind": kind, "source": f"{BASE}/{KINDS[kind][0]}", "created_at": _now(), "files": [], "failures": []}
    done = {(item["symbol"], item["date"]) for item in manifest["files"]}
    todo = [(symbol, date) for symbol in symbols for date in dates if (symbol, date) not in done]
    _log(f"{kind}: {len(todo)} files to acquire ({len(done)} already in the manifest) into {out_dir}")
    failures = []
    started = time.time()

    def work(item):
        symbol, date = item
        try:
            return acquire_one(kind, out_dir, symbol, date)
        except Exception as error:  # noqa: BLE001 - recorded, the run continues
            return {"symbol": symbol, "date": date, "error": str(error)}

    with ThreadPoolExecutor(max_workers=max(workers, 1)) as pool:
        for count, result in enumerate(pool.map(work, todo), start=1):
            if "error" in result:
                failures.append(result)
                _log(f"FAIL {result['symbol']} {result['date']}: {result['error']}")
            else:
                manifest["files"].append(result)
                _log(f"ok {result['symbol']} {result['date']} rows={result['rows']} zip={result['zip_bytes'] // 1024} KiB ({count}/{len(todo)})")
            if count % 20 == 0 or count == len(todo):
                manifest["failures"] = failures
                manifest["updated_at"] = _now()
                manifest_path.write_text(json.dumps(manifest, indent=1) + "\n")
    manifest["failures"] = failures
    manifest["status"] = "complete" if not failures else "incomplete"
    manifest["elapsed_seconds"] = round(time.time() - started, 1)
    manifest["updated_at"] = _now()
    manifest_path.write_text(json.dumps(manifest, indent=1) + "\n")
    _log(f"{kind}: done, {len(manifest['files'])} files in the manifest, {len(failures)} failures, {manifest['elapsed_seconds']} s")
    return manifest


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_exec.acquire")
    parser.add_argument("kind", choices=tuple(KINDS))
    parser.add_argument("--out", required=True)
    parser.add_argument("--symbols", required=True)
    parser.add_argument("--dates", required=True)
    parser.add_argument("--workers", type=int, default=3)
    args = parser.parse_args(argv)
    manifest = run(args.kind, Path(args.out), [s for s in args.symbols.split(",") if s], parse_dates(args.dates), args.workers)
    print(json.dumps({"kind": args.kind, "files": len(manifest["files"]), "failures": len(manifest["failures"]), "status": manifest["status"]}))
    return 0 if manifest["status"] == "complete" else 1


if __name__ == "__main__":
    sys.exit(main())
