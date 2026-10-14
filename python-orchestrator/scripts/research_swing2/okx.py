"""OKX public history for the swing v2 OKX campaign: universe rule, acquisition, data readiness.

Public endpoints only, no credential, no order:

- ``/api/v5/public/instruments?instType=SWAP``: contract metadata (``ctVal``, ``tickSz``, ``lotSz``, ``minSz``,
  ``listTime``, ``instCategory``).
- ``/api/v5/market/history-candles`` (bar ``1Dutc``): daily quote volume, used only to rank the liquidity of the
  candidates (the ``volCcyQuote`` column; prices are not kept).
- ``/api/v5/public/market-data-history``: links to the official historical files (module 2 = 1-minute
  candlesticks, module 3 = funding rates), monthly or daily; each file is downloaded, its sha256 recorded (OKX
  publishes none), converted and deleted.

Layouts follow ``scripts.research_exec.acquire`` so ``data.build_root`` reads them unchanged:
``klines/<instId>/<instId>-1m-<period>.npz`` (``ts_s``, ``open``, ``high``, ``low``, ``close``, ``volume`` = quote
volume) and ``funding/<instId>/<instId>-fundingRate-<period>.npz`` (``ts_ms``, ``rate``). Funding is booked at the
recorded settlement instants (OKX settles every 1, 2, 4 or 8 h depending on the contract and the date).
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import statistics
import sys
import time
import urllib.request
import zipfile
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

API = "https://www.okx.com"
USED = frozenset(("BTC", "ETH", "BNB", "XRP", "ADA", "DOGE", "SOL", "LTC", "LINK", "AVAX",
                  "ARB", "OP", "APT", "SUI", "NEAR", "TIA", "SEI", "INJ", "ATOM", "FIL"))
STABLE = frozenset(("USDC", "USDT", "DAI", "FDUSD", "TUSD", "USDE", "PYUSD", "USD1", "RLUSD", "USDD", "BUSD", "EURT", "XAUT", "PAXG"))
LISTED_BY_MS = 1_759_276_800_000  # 2025-10-01T00:00Z: listed at least a year before the end of the window
VOLUME_FROM_MS = 1_759_276_800_000  # liquidity ranking window 2025-10-01 .. 2026-10-01 (daily quote volume)
VOLUME_TO_MS = 1_790_812_800_000
UNIVERSE_SIZE = 30
REQUEST_GAP_S = 0.15  # under the public limits (20 requests / 2 s)
DAY_MS = 86_400_000


def _log(message: str) -> None:
    print(f"{datetime.now(timezone.utc):%Y-%m-%dT%H:%M:%SZ} {message}", file=sys.stderr, flush=True)


def _get(path: str, retries: int = 5) -> dict:
    for attempt in range(retries):
        try:
            time.sleep(REQUEST_GAP_S)
            with urllib.request.urlopen(urllib.request.Request(API + path, headers={"User-Agent": "research"}), timeout=60) as response:
                document = json.loads(response.read())
            if document.get("code") == "0":
                return document
            raise RuntimeError(f"{path}: code {document.get('code')} {document.get('msg')}")
        except Exception as error:  # noqa: BLE001 - retried, then raised
            if attempt == retries - 1:
                raise
            _log(f"retry {attempt + 1} {path}: {error}")
            time.sleep(2 * (attempt + 1))
    raise AssertionError("unreachable")


# --------------------------------------------------------------------------- universe
def candidates(instruments: list[dict]) -> list[dict]:
    """The pre-declared eligibility rule (metadata only)."""
    out = []
    for item in instruments:
        base = item.get("ctValCcy") or item["instFamily"].split("-")[0]
        if (item["instType"] == "SWAP" and item["settleCcy"] == "USDT" and item["ctType"] == "linear" and item["state"] == "live"
                and item.get("instCategory") == "1" and base not in USED and base not in STABLE
                and int(item["listTime"]) <= LISTED_BY_MS):
            out.append(item)
    return sorted(out, key=lambda i: i["instId"])


def daily_quote_volume(inst_id: str) -> list[tuple[int, float]]:
    """(day open ms, quote volume) over the ranking window, from 1Dutc candles (prices discarded)."""
    rows: dict[int, float] = {}
    cursor = VOLUME_TO_MS
    while cursor > VOLUME_FROM_MS:
        data = _get(f"/api/v5/market/history-candles?instId={inst_id}&bar=1Dutc&after={cursor}&limit=100")["data"]
        if not data:
            break
        for row in data:
            ts = int(row[0])
            if VOLUME_FROM_MS <= ts < VOLUME_TO_MS:
                rows[ts] = float(row[7])  # volCcyQuote
        cursor = min(int(row[0]) for row in data)
    return sorted(rows.items())


def select_universe(out: Path) -> dict:
    instruments = _get("/api/v5/public/instruments?instType=SWAP")["data"]
    eligible = candidates(instruments)
    _log(f"{len(eligible)} eligible instruments; ranking by median daily quote volume")
    ranking = []
    for item in eligible:
        volumes = [v for _, v in daily_quote_volume(item["instId"])]
        ranking.append({"instId": item["instId"], "median_daily_quote_volume": statistics.median(volumes) if volumes else 0.0,
                        "days": len(volumes)})
    ranking.sort(key=lambda r: (-r["median_daily_quote_volume"], r["instId"]))
    chosen = [r["instId"] for r in ranking[:UNIVERSE_SIZE]]
    by_id = {i["instId"]: i for i in instruments}
    document = {
        "retrieved_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "rule": {"instType": "SWAP", "settleCcy": "USDT", "ctType": "linear", "state": "live", "instCategory": "1 (crypto)",
                 "excluded_bases": sorted(USED | STABLE), "listed_by": "2025-10-01T00:00Z",
                 "ranking": "median daily quote volume (1Dutc volCcyQuote) 2025-10-01..2026-09-30", "size": UNIVERSE_SIZE},
        "eligible": len(eligible), "ranking": ranking, "universe": chosen,
        "instruments": [by_id[i] for i in chosen],
    }
    out.write_text(json.dumps(document, indent=1) + "\n")
    return document


def instrument_assumptions(universe: dict) -> dict:
    """``data.build_root`` instruments: quantities in contracts, contract size = ctVal; OKX has no minimum notional."""
    symbols = [{"symbol": i["instId"], "tick_size": float(i["tickSz"]), "quantity_step": float(i["lotSz"]),
                "min_quantity": float(i["minSz"]), "min_notional": 0.0, "contract_size": float(i["ctVal"]),
                "list_time_ms": int(i["listTime"])} for i in universe["instruments"]]
    return {"schema_version": "okx-instruments.v1", "assumption_status": "current_metadata_not_historical",
            "source_url": API + "/api/v5/public/instruments?instType=SWAP", "retrieved_at": universe["retrieved_at"], "symbols": symbols}


# --------------------------------------------------------------------------- acquisition
LINK_SPAN_MS = {"monthly": 270 * DAY_MS, "daily": 9 * DAY_MS}  # the endpoint refuses more than 10 months / 10 days


def file_links(module: int, inst_id: str, aggregation: str, begin_ms: int, end_ms: int) -> list[dict]:
    family = inst_id.removesuffix("-SWAP")
    links: dict[str, dict] = {}
    start = begin_ms
    while start <= end_ms:
        stop = min(start + LINK_SPAN_MS[aggregation], end_ms)
        document = _get(f"/api/v5/public/market-data-history?module={module}&instType=SWAP&instFamilyList={family}"
                        f"&dateAggrType={aggregation}&begin={start}&end={stop}")
        for g in (g for x in document["data"] for d in x["details"] for g in d["groupDetails"]):
            links[g["filename"]] = g
        start = stop + 1
    return list(links.values())


CONVERT_DIAG: dict = {}


def _number(text: str) -> float | None:
    try:
        value = float(text)
    except ValueError:
        return None
    return value if np.isfinite(value) else None


def convert_candles(raw: bytes) -> dict[str, np.ndarray]:
    lines = raw.decode().splitlines()
    header = lines[0].split(",")
    index = {name: header.index(name) for name in ("open", "high", "low", "close", "vol", "vol_ccy", "vol_quote", "open_time", "confirm")}
    rows = [line.split(",") for line in lines[1:] if line]
    # Archived history is final: some monthly files (2023-09..11, 2024-02..07) flag every row confirm=0; they are kept
    # and counted.
    unconfirmed = sum(1 for r in rows if r[index["confirm"]] != "1")
    # Some older files carry 'None' fields: a row without a full OHLC is dropped (and counted); a missing
    # quote volume (used for nothing in the screen) becomes NaN.
    priced = [r for r in rows if all(_number(r[index[c]]) is not None for c in ("open", "high", "low", "close"))]
    CONVERT_DIAG["rows_without_ohlc"] = len(rows) - len(priced)
    CONVERT_DIAG["unconfirmed"] = unconfirmed
    rows = priced
    ts = np.array([int(r[index["open_time"]]) // 1000 for r in rows], dtype=np.int64)
    order = np.argsort(ts, kind="stable")
    arrays = {"ts_s": ts[order]}
    for name, column in (("open", "open"), ("high", "high"), ("low", "low"), ("close", "close"), ("volume", "vol_quote"),
                         ("base_volume", "vol_ccy"), ("contracts", "vol")):
        arrays[name] = np.array([_number(r[index[column]]) if _number(r[index[column]]) is not None else np.nan for r in rows], dtype=np.float64)[order]
    keep = np.ones(arrays["ts_s"].size, dtype=bool)
    keep[:-1] = arrays["ts_s"][1:] != arrays["ts_s"][:-1]
    return {k: v[keep] for k, v in arrays.items()}


def convert_funding(raw: bytes) -> dict[str, np.ndarray]:
    lines = raw.decode().splitlines()
    header = lines[0].split(",")
    rate_i, time_i = header.index("funding_rate"), header.index("funding_time")
    parsed = [line.split(",") for line in lines[1:] if line]
    CONVERT_DIAG["funding_rows_without_rate"] = sum(1 for r in parsed if _number(r[rate_i]) is None)
    rows = sorted({int(r[time_i]): _number(r[rate_i]) for r in parsed if _number(r[rate_i]) is not None}.items())
    return {"ts_ms": np.array([t for t, _ in rows], dtype=np.int64), "rate": np.array([r for _, r in rows], dtype=np.float64)}


def acquire(inst_id: str, out: Path, begin_ms: int, end_ms: int, daily_from_ms: int, manifest: dict) -> None:
    """Monthly files up to ``daily_from_ms``, daily files after it, for candles (module 2) and funding (module 3)."""
    done = {item["filename"] for item in manifest["files"]}
    for module, kind, folder, converter, prefix in ((2, "candles", "klines", convert_candles, "1m"), (3, "funding", "funding", convert_funding, "fundingRate")):
        links = file_links(module, inst_id, "monthly", begin_ms, daily_from_ms - 1)
        links += file_links(module, inst_id, "daily", daily_from_ms, end_ms)
        for link in sorted(links, key=lambda g: g["filename"]):
            name = link["filename"]
            if name in done:
                continue
            period = name.removesuffix(".zip").rsplit(f"{'candlesticks' if module == 2 else 'fundingrates'}-", 1)[1]
            time.sleep(REQUEST_GAP_S)
            with urllib.request.urlopen(urllib.request.Request(link["url"], headers={"User-Agent": "research"}), timeout=120) as response:
                blob = response.read()
            digest = hashlib.sha256(blob).hexdigest()
            CONVERT_DIAG.clear()
            with zipfile.ZipFile(io.BytesIO(blob)) as archive:
                inner = archive.namelist()[0]
                arrays = converter(archive.read(inner))
            diag = dict(CONVERT_DIAG)
            target = out / folder / inst_id / f"{inst_id}-{prefix}-{period}.npz"
            target.parent.mkdir(parents=True, exist_ok=True)
            np.savez_compressed(target, **arrays)
            first = arrays["ts_s" if module == 2 else "ts_ms"]
            manifest["files"].append({"instId": inst_id, "kind": kind, "filename": name, "url": link["url"].split("?")[0], "zip_bytes": len(blob),
                                      "zip_sha256": digest, "rows": int(first.size), "npz": str(target.relative_to(out)), "diag": diag})


def funding_rest(inst_id: str, out: Path, begin_ms: int, end_ms: int, manifest: dict) -> None:
    """Funding events after the last archived one (the archive has no daily funding files), from the public
    funding-rate-history endpoint (about three months deep), stored as ``…-fundingRate-rest.npz``."""
    events: dict[int, float] = {}
    cursor = end_ms
    while cursor > begin_ms:
        data = _get(f"/api/v5/public/funding-rate-history?instId={inst_id}&after={cursor}&limit=100")["data"]
        if not data:
            break
        for row in data:
            ts = int(row["fundingTime"])
            if begin_ms <= ts < end_ms:
                events[ts] = float(row["realizedRate"] or row["fundingRate"])
        cursor = min(int(row["fundingTime"]) for row in data)
    target = out / "funding" / inst_id / f"{inst_id}-fundingRate-rest.npz"
    target.parent.mkdir(parents=True, exist_ok=True)
    rows = sorted(events.items())
    np.savez_compressed(target, ts_ms=np.array([t for t, _ in rows], dtype=np.int64), rate=np.array([r for _, r in rows], dtype=np.float64))
    manifest["files"] = [f for f in manifest["files"] if not (f["instId"] == inst_id and f["kind"] == "funding_rest")]
    manifest["files"].append({"instId": inst_id, "kind": "funding_rest", "filename": target.name, "rows": len(rows),
                              "range": [begin_ms, end_ms], "npz": str(target.relative_to(out))})


def acquire_all(universe_path: Path, out: Path, begin_ms: int, end_ms: int, daily_from_ms: int) -> dict:
    universe = json.loads(universe_path.read_text())
    out.mkdir(parents=True, exist_ok=True)
    manifest_path = out / "manifest-okx.json"
    manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {"source": API + "/api/v5/public/market-data-history", "files": [], "failures": []}
    manifest["failures"] = []
    for inst_id in universe["universe"]:
        try:
            acquire(inst_id, out, begin_ms, end_ms, daily_from_ms, manifest)
            archived = [np.load(p)["ts_ms"] for p in (out / "funding" / inst_id).glob("*-fundingRate-2*.npz")]
            last = max((int(a.max()) for a in archived if a.size), default=begin_ms)
            funding_rest(inst_id, out, last + 1, end_ms, manifest)
            _log(f"ok {inst_id} ({len(manifest['files'])} files)")
        except Exception as error:  # noqa: BLE001 - recorded
            manifest["failures"].append({"instId": inst_id, "error": str(error)})
            _log(f"FAIL {inst_id}: {error}")
        manifest_path.write_text(json.dumps(manifest, indent=1) + "\n")
    manifest["status"] = "complete" if not manifest["failures"] else "incomplete"
    manifest_path.write_text(json.dumps(manifest, indent=1) + "\n")
    return manifest


def missing_spans(tape: Path, inst_id: str, begin_ms: int, end_ms: int, threshold_min: int = 60) -> list[tuple[int, int]]:
    """Spans of more than ``threshold_min`` missing minutes in [begin, end) of the archived candles (head included)."""
    parts = [np.load(p)["ts_s"] for p in sorted((tape / "klines" / inst_id).glob("*.npz"))]
    ts = np.unique(np.concatenate(parts)) * 1000 if parts else np.zeros(0, dtype=np.int64)
    ts = ts[(ts >= begin_ms) & (ts < end_ms)]
    edges = np.concatenate(([begin_ms - 60_000], ts, [end_ms]))
    spans = []
    for a, b in zip(edges[:-1], edges[1:]):
        if (b - a) // 60_000 - 1 > threshold_min:
            spans.append((int(a) + 60_000, int(b)))
    return spans


def fill_gaps(universe_path: Path, out: Path, begin_ms: int, end_ms: int) -> dict:
    """1-minute candles of the archive's missing spans from the public history-candles endpoint (same OKX last-price
    candles), stored as ``<instId>-1m-rest-<span start>.npz``; the spans and their coverage go to manifest-rest.json."""
    universe = json.loads(universe_path.read_text())
    report = {"spans": []}
    for item in universe["instruments"]:
        inst_id = item["instId"]
        start = max(begin_ms, int(item["listTime"]) // 60_000 * 60_000)
        for a, b in missing_spans(out, inst_id, start, end_ms):
            rows: dict[int, list] = {}
            cursor = b
            while cursor > a:
                data = _get(f"/api/v5/market/history-candles?instId={inst_id}&bar=1m&after={cursor}&limit=100")["data"]
                if not data:
                    break
                for row in data:
                    ts = int(row[0])
                    if a <= ts < b and row[8] == "1":
                        rows[ts] = row
                cursor = min(int(row[0]) for row in data)
            ordered = [rows[t] for t in sorted(rows)]
            target = out / "klines" / inst_id / f"{inst_id}-1m-rest-{a}.npz"
            np.savez_compressed(target, ts_s=np.array([int(r[0]) // 1000 for r in ordered], dtype=np.int64),
                                **{name: np.array([float(r[k]) for r in ordered], dtype=np.float64)
                                   for name, k in (("open", 1), ("high", 2), ("low", 3), ("close", 4), ("volume", 7))})
            report["spans"].append({"instId": inst_id, "start_ms": a, "end_ms": b, "minutes": (b - a) // 60_000, "filled": len(ordered),
                                    "npz": str(target.relative_to(out))})
            _log(f"{inst_id} span {a}..{b}: {len(ordered)} of {(b - a) // 60_000} minutes")
            (out / "manifest-rest.json").write_text(json.dumps(report, indent=1) + "\n")
    return report


# --------------------------------------------------------------------------- readiness
def readiness(universe_path: Path, tape: Path, begin_ms: int, end_ms: int) -> dict:
    """Per instrument: listing, candle coverage and gaps, funding coverage and settlement intervals, contract metadata."""
    universe = json.loads(universe_path.read_text())
    rows = []
    for item in universe["instruments"]:
        inst_id = item["instId"]
        ts_parts = [np.load(p)["ts_s"] for p in sorted((tape / "klines" / inst_id).glob("*.npz"))]
        ts = np.unique(np.concatenate(ts_parts)) * 1000 if ts_parts else np.zeros(0, dtype=np.int64)
        ts = ts[(ts >= begin_ms) & (ts < end_ms)]
        row = {"instId": inst_id, "listTime": int(item["listTime"]), "ctVal": item["ctVal"], "tickSz": item["tickSz"], "lotSz": item["lotSz"],
               "minSz": item["minSz"], "candles": int(ts.size)}
        if ts.size:
            first, last = int(ts[0]), int(ts[-1])
            expected = (last - first) // 60_000 + 1
            gaps = np.diff(ts) // 60_000 - 1
            big = np.flatnonzero(gaps > 0)
            row.update(first_candle_ms=first, last_candle_ms=last, missing_minutes=int(expected - ts.size),
                       gaps=int(big.size), longest_gap_minutes=int(gaps.max()) if gaps.size else 0,
                       gaps_over_60_min=[[int(ts[i]), int(gaps[i])] for i in big if gaps[i] > 60][:20],
                       first_decision_ms=first + 250 * 4 * 3_600_000)
        f_parts = [np.load(p) for p in sorted((tape / "funding" / inst_id).glob("*.npz"))]
        if f_parts:
            f_ts = np.unique(np.concatenate([p["ts_ms"] for p in f_parts]))
            f_ts = f_ts[(f_ts >= begin_ms) & (f_ts < end_ms)]
            steps = np.diff(f_ts) // 3_600_000
            changes = []
            for i in range(1, steps.size):
                if steps[i] != steps[i - 1]:
                    changes.append([int(f_ts[i]), int(steps[i - 1]), int(steps[i])])
            expected_from = max(int(ts[0]) if ts.size else begin_ms, begin_ms)
            row.update(funding_events=int(f_ts.size), funding_first_ms=int(f_ts[0]) if f_ts.size else None,
                       funding_last_ms=int(f_ts[-1]) if f_ts.size else None,
                       funding_intervals_hours=sorted({int(s) for s in steps}) if steps.size else [],
                       funding_interval_changes=changes[:30],
                       funding_gaps_over_8h=[[int(f_ts[i]), int(steps[i])] for i in range(steps.size) if steps[i] > 8][:20],
                       funding_starts_late_hours=(int(f_ts[0]) - expected_from) // 3_600_000 if f_ts.size else None)
        else:
            row.update(funding_events=0)
        rows.append(row)
    return {"window": [begin_ms, end_ms], "rows": rows}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_swing2.okx")
    sub = parser.add_subparsers(dest="command", required=True)
    u = sub.add_parser("universe")
    u.add_argument("--out", required=True)
    a = sub.add_parser("acquire")
    a.add_argument("--universe", required=True)
    a.add_argument("--out", required=True)
    a.add_argument("--begin", type=int, default=1_667_260_800_000)  # 2022-11-01
    a.add_argument("--end", type=int, default=1_791_936_000_000)  # 2026-10-14
    a.add_argument("--daily-from", type=int, default=1_790_812_800_000)  # 2026-10-01
    g = sub.add_parser("fill-gaps")
    g.add_argument("--universe", required=True)
    g.add_argument("--out", required=True)
    g.add_argument("--begin", type=int, default=1_667_260_800_000)
    g.add_argument("--end", type=int, default=1_791_820_800_000)  # 2026-10-12T16:00Z, the last archived minute + 1
    i = sub.add_parser("instruments")
    i.add_argument("--universe", required=True)
    i.add_argument("--out", required=True)
    r = sub.add_parser("readiness")
    r.add_argument("--universe", required=True)
    r.add_argument("--tape", required=True)
    r.add_argument("--begin", type=int, default=1_667_260_800_000)
    r.add_argument("--end", type=int, default=1_791_936_000_000)
    r.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    if args.command == "universe":
        document = select_universe(Path(args.out))
        print(json.dumps({"eligible": document["eligible"], "universe": document["universe"]}))
    elif args.command == "acquire":
        manifest = acquire_all(Path(args.universe), Path(args.out), args.begin, args.end, args.daily_from)
        print(json.dumps({"files": len(manifest["files"]), "failures": manifest["failures"], "status": manifest["status"]}))
        return 0 if manifest["status"] == "complete" else 1
    elif args.command == "fill-gaps":
        report = fill_gaps(Path(args.universe), Path(args.out), args.begin, args.end)
        print(json.dumps({"spans": len(report["spans"]), "minutes": sum(s["minutes"] for s in report["spans"]),
                          "filled": sum(s["filled"] for s in report["spans"])}))
    elif args.command == "instruments":
        Path(args.out).write_text(json.dumps(instrument_assumptions(json.loads(Path(args.universe).read_text())), indent=1) + "\n")
    elif args.command == "readiness":
        Path(args.out).write_text(json.dumps(readiness(Path(args.universe), Path(args.tape), args.begin, args.end), indent=1) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
