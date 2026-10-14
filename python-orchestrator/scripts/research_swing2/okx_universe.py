"""Monthly OKX universe for swing v2 (owner's rule of 2026-10-14) and the data it needs.

On the 1st of each month M, the universe is the 30 OKX USDT-margined linear crypto swaps with the highest median
daily quote volume over month M-1, among the instruments eligible at that date: 1-minute candles in the official
archive since at least 42 days before the 1st of M (the 250-bar EMA200 4h warm-up), at least 15 days with candles
in M-1, base not among the 20 pairs already used nor a stablecoin or gold token. Only data known before M is used.
Delisted instruments can enter: the archive keeps their files.

There is no endpoint listing past instruments, so the candidate families are enumerated, best effort, from the
union of public lists (OKX live swaps and spot pairs, Binance USD-M symbols including delisted ones, Binance spot
pairs); each ``<BASE>-USDT`` family is probed in the OKX archive. A family absent from every list cannot enter;
the report says so. Steps (each resumable):

    candidates -> inventory -> download (1m candles + daily quote volumes) -> universe -> funding -> readiness
"""

from __future__ import annotations

import argparse
import io
import json
import re
import statistics
import time
import urllib.request
import zipfile
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from .okx import CONVERT_DIAG, DAY_MS, REQUEST_GAP_S, STABLE, USED, _get, _log, convert_candles, convert_funding, file_links

FIRST_MONTH = "2022-09"  # volume of 2022-12 decides January 2023; candles from 2022-09 cover the warm-up
LAST_MONTH = "2026-09"
DAILY_TAIL = ("2026-10-01", "2026-10-12")  # the October 2026 days archived so far (UTC+8 files)
UNIVERSE_FROM = "2023-01"
UNIVERSE_TO = "2026-10"
UNIVERSE_SIZE = 30
WARMUP_DAYS = 42
MIN_DAYS_IN_PREVIOUS_MONTH = 15
EXCLUDED = USED | STABLE | frozenset(("XAU", "XAG", "USDG", "EUR", "GBP"))


def _months(first: str, last: str) -> list[str]:
    out, (y, m) = [], map(int, first.split("-"))
    while f"{y:04d}-{m:02d}" <= last:
        out.append(f"{y:04d}-{m:02d}")
        y, m = (y + 1, 1) if m == 12 else (y, m + 1)
    return out


def _month_start_ms(month: str) -> int:
    y, m = map(int, month.split("-"))
    return int(datetime(y, m, 1, tzinfo=timezone.utc).timestamp() * 1000)


def _fetch_json(url: str) -> dict | list:
    with urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "research"}), timeout=120) as response:
        return json.loads(response.read())


def _normalise(base: str) -> str:
    return re.sub(r"^(1000000|1000|1M)(?=[A-Z])", "", base.upper())


# --------------------------------------------------------------------------- candidates
def enumerate_candidates(out: Path) -> dict:
    sources: dict[str, set[str]] = defaultdict(set)
    for item in _get("/api/v5/public/instruments?instType=SWAP")["data"]:
        if item["settleCcy"] == "USDT" and item.get("instCategory") == "1":
            sources[item["instFamily"].split("-")[0]].add("okx_swap_live")
    for item in _get("/api/v5/public/instruments?instType=SPOT")["data"]:
        if item["quoteCcy"] == "USDT":
            sources[item["baseCcy"]].add("okx_spot_live")
    for item in _fetch_json("https://fapi.binance.com/fapi/v1/exchangeInfo")["symbols"]:
        if item["quoteAsset"] == "USDT" and item.get("contractType") == "PERPETUAL":
            sources[_normalise(item["baseAsset"])].add("binance_usdm_" + item["status"].lower())
    for item in _fetch_json("https://api.binance.com/api/v3/exchangeInfo")["symbols"]:
        if item["quoteAsset"] == "USDT":
            sources[_normalise(item["baseAsset"])].add("binance_spot_" + item["status"].lower())
    families = {base: sorted(tags) for base, tags in sources.items() if base not in EXCLUDED and re.fullmatch(r"[A-Z0-9]+", base)}
    document = {"retrieved_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "excluded": sorted(EXCLUDED),
                "families": dict(sorted(families.items()))}
    out.write_text(json.dumps(document, indent=1) + "\n")
    return document


# --------------------------------------------------------------------------- inventory
def _inventory_one(base: str) -> dict:
    inst = f"{base}-USDT-SWAP"
    monthly = {g["filename"]: g for g in file_links(2, inst, "monthly", _month_start_ms(FIRST_MONTH) - 9 * DAY_MS, _month_start_ms(LAST_MONTH))}
    months = sorted({re.search(r"(\d{4}-\d{2})\.zip$", name).group(1) for name in monthly})
    files = {m: [monthly[n]] for n in monthly for m in [re.search(r"(\d{4}-\d{2})\.zip$", n).group(1)]}
    daily: dict[str, list[dict]] = {}
    if months:
        lo, hi = months[0], months[-1]
        holes = [m for m in _months(lo, max(hi, LAST_MONTH)) if m not in files]
        for month in holes:
            begin = _month_start_ms(month) - DAY_MS
            nxt = _months(month, "2099-12")[1]
            links = file_links(2, inst, "daily", begin, _month_start_ms(nxt) - 1)
            days = [g for g in links if re.search(r"(\d{4}-\d{2})-\d{2}\.zip$", g["filename"]).group(1) == month]
            if days:
                daily[month] = days
            elif month > hi:
                break  # past the last monthly file: no daily files either, the instrument is gone
        tail = [g for g in file_links(2, inst, "daily", _month_start_ms("2026-10") - DAY_MS, _month_start_ms("2026-10") + 12 * DAY_MS)
                if DAILY_TAIL[0] <= re.search(r"(\d{4}-\d{2}-\d{2})\.zip$", g["filename"]).group(1) <= DAILY_TAIL[1]]
        if tail:
            daily["2026-10"] = tail
    return {"instId": inst, "monthly_months": months, "daily_months": sorted(daily),
            "files": sorted([g for v in files.values() for g in v] + [g for v in daily.values() for g in v], key=lambda g: g["filename"])}


def inventory(candidates_path: Path, out: Path) -> dict:
    families = json.loads(candidates_path.read_text())["families"]
    document = json.loads(out.read_text()) if out.exists() else {"instruments": {}}
    todo = [b for b in families if b not in document["instruments"]]
    _log(f"inventory: {len(todo)} families to probe")
    def probe(base):
        try:
            return base, _inventory_one(base)
        except Exception as error:  # noqa: BLE001 - recorded
            return base, {"error": str(error)}

    with ThreadPoolExecutor(max_workers=3) as pool:  # ~7 requests/s, under the 10/s public limit
        for count, (base, result) in enumerate(pool.map(probe, todo), 1):
            document["instruments"][base] = result
            if count % 25 == 0 or count == len(todo):
                out.write_text(json.dumps(document) + "\n")
                _log(f"inventory {count}/{len(todo)}")
    return document


# --------------------------------------------------------------------------- download
def _download_one(tape: Path, inst: str, link: dict) -> dict:
    name = link["filename"]
    period = re.search(r"candlesticks-(\d{4}-\d{2}(?:-\d{2})?)\.zip$", name).group(1)
    target = tape / "klines" / inst / f"{inst}-1m-{period}.npz"
    if target.exists():
        with np.load(target) as data:
            arrays = {k: data[k] for k in ("ts_s", "volume")}
        return {"filename": name, "cached": True, **_daily_volume(arrays)}
    for attempt in range(5):
        try:
            time.sleep(REQUEST_GAP_S)
            with urllib.request.urlopen(urllib.request.Request(link["url"], headers={"User-Agent": "research"}), timeout=120) as response:
                blob = response.read()
            break
        except Exception:  # noqa: BLE001 - retried
            if attempt == 4:
                raise
            time.sleep(3 * (attempt + 1))
    import hashlib

    CONVERT_DIAG.clear()
    with zipfile.ZipFile(io.BytesIO(blob)) as archive:
        arrays = convert_candles(archive.read(archive.namelist()[0]))
    diag = dict(CONVERT_DIAG)
    target.parent.mkdir(parents=True, exist_ok=True)
    np.savez_compressed(target, **arrays)
    return {"filename": name, "zip_sha256": hashlib.sha256(blob).hexdigest(), "zip_bytes": len(blob), "rows": int(arrays["ts_s"].size),
            "diag": diag, **_daily_volume(arrays)}


def _daily_volume(arrays: dict) -> dict:
    days = (arrays["ts_s"] // 86_400).astype(np.int64)
    volume = np.nan_to_num(arrays["volume"], nan=0.0)
    out: dict[str, list] = {}
    for day in np.unique(days):
        mask = days == day
        out[str(int(day))] = [float(volume[mask].sum()), int(mask.sum())]
    return {"days": out}


def download(inventory_path: Path, tape: Path, workers: int = 3) -> dict:
    """All candle files of every family found in the archive; daily quote volume (UTC days) per family."""
    inv = json.loads(inventory_path.read_text())["instruments"]
    volumes_path = tape / "daily-volume.json"
    manifest_path = tape / "manifest-candles.json"
    volumes = json.loads(volumes_path.read_text()) if volumes_path.exists() else {}
    manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {"files": [], "failures": []}
    done = {f["filename"] for f in manifest["files"]}
    jobs = [(item["instId"], g) for item in inv.values() if "files" in item for g in item["files"] if g["filename"] not in done]
    _log(f"download: {len(jobs)} files")

    def work(job):
        inst, link = job
        try:
            return inst, _download_one(tape, inst, link)
        except Exception as error:  # noqa: BLE001 - recorded
            return inst, {"filename": link["filename"], "error": str(error)}

    with ThreadPoolExecutor(max_workers=workers) as pool:
        for count, (inst, result) in enumerate(pool.map(work, jobs), 1):
            if "error" in result:
                manifest["failures"].append({"instId": inst, **result})
            else:
                days = result.pop("days")
                bucket = volumes.setdefault(inst, {})
                for day, (vol, minutes) in days.items():  # a UTC day spans two UTC+8 files: add them up
                    previous = bucket.get(day, [0.0, 0])
                    bucket[day] = [previous[0] + vol, previous[1] + minutes]
                manifest["files"].append({"instId": inst, **result})
            if count % 200 == 0 or count == len(jobs):
                volumes_path.write_text(json.dumps(volumes) + "\n")
                manifest_path.write_text(json.dumps(manifest) + "\n")
                _log(f"download {count}/{len(jobs)} ({len(manifest['failures'])} failures)")
    manifest["status"] = "complete" if not manifest["failures"] else "incomplete"
    volumes_path.write_text(json.dumps(volumes) + "\n")
    manifest_path.write_text(json.dumps(manifest) + "\n")
    return manifest


def redo_bad(tape: Path) -> int:
    """Remove the converted files of the first conversion that dropped confirm=0 rows or lacked the contract volume,
    so that ``download`` fetches and converts them again."""
    manifest_path = tape / "manifest-candles.json"
    manifest = json.loads(manifest_path.read_text())
    removed = set()
    for path in (tape / "klines").glob("*/*.npz"):
        with np.load(path) as data:
            expected = 1440 if re.search(r"-\d{4}-\d{2}-\d{2}[.]npz$", path.name) else 40_000
            rows = data["ts_s"].size
            stale = "contracts" not in data.files and (rows < 0.9 * expected or (rows and bool(np.isnan(data["volume"]).any())))
        if stale:
            removed.add(path.name)
            path.unlink()
    manifest["files"] = [f for f in manifest["files"]
                         if f"{f['instId']}-1m-{re.search(r'candlesticks-(.+)[.]zip$', f['filename']).group(1)}.npz" not in removed]
    manifest_path.write_text(json.dumps(manifest) + "\n")
    return len(removed)


def volumes(tape: Path, out: Path) -> dict:
    """Daily quote volume (UTC days) per instrument. Per minute: the archived quote volume; else base volume x close;
    else contracts x contract value x close, the contract value taken from current OKX metadata, or inferred as the
    median of quote volume / (contracts x close) over the instrument's minutes carrying both."""
    live = {i["instId"]: float(i["ctVal"]) for i in _get("/api/v5/public/instruments?instType=SWAP")["data"]}
    document = {"instruments": {}, "days": {}}
    for directory in sorted((tape / "klines").iterdir()):
        inst = directory.name
        parts = [dict(np.load(p)) for p in sorted(directory.glob("*.npz"))]
        for part in parts:  # files converted before the contract volume was kept: their quote volume is complete
            for key in ("base_volume", "contracts"):
                part.setdefault(key, np.full(part["ts_s"].size, np.nan))
        if not parts:
            continue
        data = {k: np.concatenate([p[k] for p in parts]) for k in ("ts_s", "close", "volume", "base_volume", "contracts")}
        order = np.argsort(data["ts_s"], kind="stable")
        data = {k: v[order] for k, v in data.items()}
        keep = np.ones(data["ts_s"].size, dtype=bool)
        keep[1:] = data["ts_s"][1:] != data["ts_s"][:-1]
        data = {k: v[keep] for k, v in data.items()}
        both = np.isfinite(data["volume"]) & np.isfinite(data["contracts"]) & (data["contracts"] > 0)
        inferred = float(np.median(data["volume"][both] / (data["contracts"][both] * data["close"][both]))) if both.any() else None
        ct_val = live.get(inst, inferred)
        quote = np.where(np.isfinite(data["volume"]), data["volume"],
                         np.where(np.isfinite(data["base_volume"]), data["base_volume"] * data["close"],
                                  data["contracts"] * (ct_val if ct_val is not None else np.nan) * data["close"]))
        source = {"quote": int(np.isfinite(data["volume"]).sum()),
                  "base_x_close": int((~np.isfinite(data["volume"]) & np.isfinite(data["base_volume"])).sum()),
                  "contracts_x_ctval_x_close": int((~np.isfinite(data["volume"]) & ~np.isfinite(data["base_volume"])).sum())}
        days = (data["ts_s"] // 86_400).astype(np.int64)
        bucket = {}
        for day in np.unique(days):
            mask = days == day
            values = quote[mask]
            bucket[str(int(day))] = [float(np.nansum(values)), int(mask.sum()), int(np.isnan(values).sum())]
        document["days"][inst] = bucket
        document["instruments"][inst] = {"minutes": int(data["ts_s"].size), "ct_val_live": live.get(inst), "ct_val_inferred": inferred,
                                         "volume_source_minutes": source}
    out.write_text(json.dumps(document) + "\n")
    return document


# --------------------------------------------------------------------------- universe
def monthly_universe(tape: Path, out: Path) -> dict:
    volumes = json.loads((tape / "daily-quote-volume.json").read_text())["days"]
    first_day: dict[str, int] = {}
    for inst in volumes:
        parts = [np.load(p)["ts_s"][:1] for p in sorted((tape / "klines" / inst).glob("*.npz"))]
        stamps = [int(p[0]) for p in parts if p.size]
        if stamps:
            first_day[inst] = min(stamps) // 86_400
    months = {}
    for month in _months(UNIVERSE_FROM, UNIVERSE_TO):
        start_day = _month_start_ms(month) // DAY_MS
        prev_start = _month_start_ms(_months("2000-01", month)[-2]) // DAY_MS
        ranking = []
        for inst, days in volumes.items():
            if inst not in first_day or first_day[inst] > start_day - WARMUP_DAYS:
                continue
            values = [v for d, (v, minutes, missing) in days.items() if prev_start <= int(d) < start_day and minutes >= 720 and missing == 0]
            if len(values) < MIN_DAYS_IN_PREVIOUS_MONTH:
                continue
            ranking.append((statistics.median(values), inst, len(values)))
        ranking.sort(key=lambda r: (-r[0], r[1]))
        months[month] = {"universe": [inst for _, inst, _ in ranking[:UNIVERSE_SIZE]], "eligible": len(ranking),
                         "ranking_top40": [[inst, round(v), n] for v, inst, n in ranking[:40]]}
    members = sorted({inst for m in months.values() for inst in m["universe"]})
    document = {"rule": {"size": UNIVERSE_SIZE, "rank": "median daily quote volume of month M-1 (UTC days with >= 720 minutes)",
                         "eligible": f"candles since >= {WARMUP_DAYS} days before the 1st of M, >= {MIN_DAYS_IN_PREVIOUS_MONTH} days in M-1",
                         "excluded_bases": sorted(EXCLUDED), "first_month": UNIVERSE_FROM, "last_month": UNIVERSE_TO},
                "months": months, "members": members}
    out.write_text(json.dumps(document, indent=1) + "\n")
    return document


# --------------------------------------------------------------------------- funding
def funding(universe_path: Path, tape: Path) -> dict:
    """Funding of every member: archive monthly (and daily) files, then the REST history for the last days."""
    from .okx import funding_rest

    members = json.loads(universe_path.read_text())["members"]
    manifest_path = tape / "manifest-funding.json"
    manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {"files": [], "failures": []}
    done = {f["filename"] for f in manifest["files"]}
    for inst in members:
        try:
            links = file_links(3, inst, "monthly", _month_start_ms(FIRST_MONTH) - 9 * DAY_MS, _month_start_ms(LAST_MONTH))
            for link in sorted(links, key=lambda g: g["filename"]):
                if link["filename"] in done:
                    continue
                time.sleep(REQUEST_GAP_S)
                with urllib.request.urlopen(urllib.request.Request(link["url"], headers={"User-Agent": "research"}), timeout=120) as response:
                    blob = response.read()
                CONVERT_DIAG.clear()
                with zipfile.ZipFile(io.BytesIO(blob)) as archive:
                    arrays = convert_funding(archive.read(archive.namelist()[0]))
                period = re.search(r"fundingrates-(\d{4}-\d{2})\.zip$", link["filename"]).group(1)
                target = tape / "funding" / inst / f"{inst}-fundingRate-{period}.npz"
                target.parent.mkdir(parents=True, exist_ok=True)
                np.savez_compressed(target, **arrays)
                manifest["files"].append({"instId": inst, "filename": link["filename"], "rows": int(arrays["ts_ms"].size), "diag": dict(CONVERT_DIAG)})
            archived = [np.load(p)["ts_ms"] for p in (tape / "funding" / inst).glob("*-fundingRate-2*.npz")]
            last = max((int(a.max()) for a in archived if a.size), default=_month_start_ms(FIRST_MONTH))
            try:
                funding_rest(inst, tape, last + 1, _month_start_ms("2026-10") + 14 * DAY_MS, manifest)
            except Exception as error:  # noqa: BLE001 - a delisted instrument has no REST history
                manifest["files"].append({"instId": inst, "kind": "funding_rest", "skipped": str(error)[:120]})
            _log(f"funding ok {inst}")
        except Exception as error:  # noqa: BLE001 - recorded
            manifest["failures"].append({"instId": inst, "error": str(error)})
            _log(f"funding FAIL {inst}: {error}")
        manifest_path.write_text(json.dumps(manifest, indent=1) + "\n")
    return manifest



# --------------------------------------------------------------------------- instruments and readiness
def _tick_from_prices(tape: Path, inst: str) -> float:
    """Price increment of a delisted instrument, inferred from the first archived month (the smallest positive
    difference between distinct OHLC levels, as a power of ten no larger than it)."""
    files = sorted((tape / "klines" / inst).glob("*.npz"))
    with np.load(files[0]) as data:
        levels = np.unique(np.concatenate([data[k] for k in ("open", "high", "low", "close")]))
    steps = np.diff(levels)
    step = float(steps[steps > 0].min()) if steps.size and (steps > 0).any() else float(levels.min()) * 1e-4
    return float(10 ** np.floor(np.log10(step) + 1e-9))


def instruments(universe_path: Path, tape: Path, out: Path) -> dict:
    """Engine instruments for every member: current OKX metadata when still listed, otherwise an inferred tick,
    one coin per contract, no lot or minimum beyond the 5-USDT notional floor (declared assumption)."""
    members = json.loads(universe_path.read_text())["members"]
    live = {i["instId"]: i for i in _get("/api/v5/public/instruments?instType=SWAP")["data"]}
    symbols = []
    for inst in members:
        item = live.get(inst)
        if item is not None:
            symbols.append({"symbol": inst, "source": "okx_live_metadata", "tick_size": float(item["tickSz"]), "quantity_step": float(item["lotSz"]),
                            "min_quantity": float(item["minSz"]), "min_notional": 0.0, "contract_size": float(item["ctVal"]),
                            "list_time_ms": int(item["listTime"])})
        else:
            symbols.append({"symbol": inst, "source": "delisted_inferred", "tick_size": _tick_from_prices(tape, inst), "quantity_step": 1e-8,
                            "min_quantity": 0.0, "min_notional": 0.0, "contract_size": 1.0, "list_time_ms": None})
    document = {"schema_version": "okx-instruments.v1", "assumption_status": "current_metadata_or_inferred_not_historical",
                "retrieved_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "symbols": symbols}
    out.write_text(json.dumps(document, indent=1) + "\n")
    return document


def readiness(universe_path: Path, instruments_path: Path, tape: Path, begin_ms: int, end_ms: int, out: Path) -> dict:
    universe = json.loads(universe_path.read_text())
    meta = {s["symbol"]: s for s in json.loads(instruments_path.read_text())["symbols"]}
    months_in: dict[str, list[str]] = defaultdict(list)
    for month, entry in universe["months"].items():
        for inst in entry["universe"]:
            months_in[inst].append(month)
    rows = []
    for inst in universe["members"]:
        ts = np.unique(np.concatenate([np.load(p)["ts_s"] for p in sorted((tape / "klines" / inst).glob("*.npz"))])).astype(np.int64) * 1000
        ts = ts[(ts >= begin_ms) & (ts < end_ms)]
        gaps = np.diff(ts) // 60_000 - 1
        big = np.flatnonzero(gaps > 60)
        row = {"instId": inst, "months_in_universe": len(months_in[inst]), "first_month": min(months_in[inst]), "last_month": max(months_in[inst]),
               "first_candle_ms": int(ts[0]), "last_candle_ms": int(ts[-1]), "missing_minutes": int((ts[-1] - ts[0]) // 60_000 + 1 - ts.size),
               "gaps_over_60_min": [[int(ts[i]), int(gaps[i])] for i in big], "metadata": meta[inst]}
        f_files = sorted((tape / "funding" / inst).glob("*.npz"))
        if f_files:
            f_ts = np.unique(np.concatenate([np.load(p)["ts_ms"] for p in f_files]))
            f_ts = f_ts[(f_ts >= begin_ms) & (f_ts < end_ms)]
            hours = np.round(np.diff(f_ts) / 3_600_000).astype(int)
            regimes = []
            for i, h in enumerate(hours):
                if not regimes or regimes[-1][1] != int(h):
                    regimes.append([int(f_ts[i]), int(h)])
            row.update(funding_events=int(f_ts.size), funding_first_ms=int(f_ts[0]) if f_ts.size else None,
                       funding_last_ms=int(f_ts[-1]) if f_ts.size else None,
                       funding_interval_regimes=regimes[:40], funding_gaps=[[int(f_ts[i]), int(h)] for i, h in enumerate(hours) if h > 8],
                       funding_missing_before_first_hours=(int(f_ts[0]) - int(ts[0])) // 3_600_000 if f_ts.size else None,
                       funding_missing_after_last_hours=(int(ts[-1]) - int(f_ts[-1])) // 3_600_000 if f_ts.size else None)
        else:
            row.update(funding_events=0)
        rows.append(row)
    document = {"window": [begin_ms, end_ms], "rows": rows}
    out.write_text(json.dumps(document, indent=1) + "\n")
    return document


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_swing2.okx_universe")
    sub = parser.add_subparsers(dest="command", required=True)
    c = sub.add_parser("candidates")
    c.add_argument("--out", required=True)
    i = sub.add_parser("inventory")
    i.add_argument("--candidates", required=True)
    i.add_argument("--out", required=True)
    d = sub.add_parser("download")
    d.add_argument("--inventory", required=True)
    d.add_argument("--tape", required=True)
    d.add_argument("--workers", type=int, default=3)
    u = sub.add_parser("universe")
    u.add_argument("--tape", required=True)
    u.add_argument("--out", required=True)
    f = sub.add_parser("funding")
    f.add_argument("--universe", required=True)
    f.add_argument("--tape", required=True)
    n = sub.add_parser("instruments")
    n.add_argument("--universe", required=True)
    n.add_argument("--tape", required=True)
    n.add_argument("--out", required=True)
    r = sub.add_parser("readiness")
    r.add_argument("--universe", required=True)
    r.add_argument("--instruments", required=True)
    r.add_argument("--tape", required=True)
    r.add_argument("--begin", type=int, default=1_667_260_800_000)
    r.add_argument("--end", type=int, default=1_791_820_800_000)
    r.add_argument("--out", required=True)
    rb = sub.add_parser("redo-bad")
    rb.add_argument("--tape", required=True)
    vo = sub.add_parser("volumes")
    vo.add_argument("--tape", required=True)
    args = parser.parse_args(argv)
    if args.command == "redo-bad":
        print(redo_bad(Path(args.tape)))
    elif args.command == "volumes":
        print(len(volumes(Path(args.tape), Path(args.tape) / "daily-quote-volume.json")["days"]))
    elif args.command == "instruments":
        print(len(instruments(Path(args.universe), Path(args.tape), Path(args.out))["symbols"]))
    elif args.command == "readiness":
        print(len(readiness(Path(args.universe), Path(args.instruments), Path(args.tape), args.begin, args.end, Path(args.out))["rows"]))
    elif args.command == "candidates":
        print(len(enumerate_candidates(Path(args.out))["families"]))
    elif args.command == "inventory":
        document = inventory(Path(args.candidates), Path(args.out))
        print(sum(1 for v in document["instruments"].values() if v.get("files")))
    elif args.command == "download":
        manifest = download(Path(args.inventory), Path(args.tape), args.workers)
        print(json.dumps({"files": len(manifest["files"]), "failures": len(manifest["failures"])}))
    elif args.command == "universe":
        document = monthly_universe(Path(args.tape), Path(args.out))
        print(json.dumps({"members": len(document["members"])}))
    elif args.command == "funding":
        manifest = funding(Path(args.universe), Path(args.tape))
        print(json.dumps({"files": len(manifest["files"]), "failures": manifest["failures"]}))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
