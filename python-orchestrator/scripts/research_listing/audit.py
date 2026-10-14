"""H1 listing audit — metadata only (no candle values, no returns, no prices).

Inputs (snapshots kept with their sha256 in the campaign directory ``metadata/``):

- OKX ``/api/v5/public/instruments?instType=SWAP`` (live contracts: ``listTime``, ``ruleType``, ``preMktSwTime``,
  ``ctVal``, ``lotSz``, ``minSz``, ``instCategory``);
- Binance ``/fapi/v1/exchangeInfo`` (``onboardDate``, ``deliveryDate``, ``status`` — TRADING / SETTLING —, filters);
- the S3 listing of ``data.binance.vision`` USD-M monthly klines (every USD-M symbol ever archived, delisted included)
  and, for archived symbols absent from exchangeInfo, the first daily kline file name (first archived day);
- the OKX historical-file index (``/api/v5/public/market-data-history``: file names only) and the swing v2 OKX audit
  (segments computed from candle timestamps only).

Outputs: ``okx-events.json``, ``binance-events.json``, ``audit-summary.json``. Every count in
``docs/handbook/research/H1-LISTING-AUDIT.md`` is reproduced by ``python -m scripts.research_listing.audit summarize``.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import time
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

DAY_MS = 86_400_000
WINDOW = ("2023-09-01", "2026-09-01")  # listings of S_A / S_B: 2023-09-01 <= t0 < 2026-09-01
PROGRAMME = frozenset(("BTC", "ETH", "BNB", "XRP", "ADA", "DOGE", "SOL", "LTC", "LINK", "AVAX",
                       "ARB", "OP", "APT", "SUI", "NEAR", "TIA", "SEI", "INJ", "ATOM", "FIL"))
STABLE = frozenset(("USDC", "USDT", "DAI", "FDUSD", "TUSD", "USDE", "PYUSD", "USD1", "RLUSD", "USDD", "BUSD", "EURT",
                    "XAUT", "PAXG", "XAU", "XAG", "USDG", "EUR", "GBP", "BFUSD", "USDP"))
SAME_TIME_MS = DAY_MS  # listings within 24 h of each other on the two venues are co-listings
S3 = "https://s3-ap-northeast-1.amazonaws.com/data.binance.vision"
OKX = "https://www.okx.com"


def ms(day: str) -> int:
    return int(datetime.fromisoformat(day).replace(tzinfo=timezone.utc).timestamp() * 1000)


def iso(value: int | None) -> str | None:
    return None if value is None else datetime.fromtimestamp(value / 1000, tz=timezone.utc).strftime("%Y-%m-%dT%H:%MZ")


def normalise(base: str) -> str:
    return re.sub(r"^(1000000|1000|1M)(?=[A-Z])", "", base.upper())


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _get(url: str, retries: int = 6) -> bytes:
    for attempt in range(retries):
        try:
            time.sleep(0.2)
            with urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "research"}), timeout=60) as response:
                return response.read()
        except Exception:  # noqa: BLE001 - retried
            if attempt == retries - 1:
                raise
            time.sleep(2 * (attempt + 1))
    raise AssertionError


def s3_keys(prefix: str, delimiter: str | None = None) -> list[str]:
    keys, marker = [], ""
    while True:
        url = f"{S3}?prefix={prefix}" + (f"&delimiter={delimiter}" if delimiter else "") + (f"&marker={marker}" if marker else "")
        text = _get(url).decode()
        found = re.findall(r"<Key>([^<]+)</Key>", text) + re.findall(r"<Prefix>([^<]+)</Prefix>", text)[1:]
        keys += found
        if "<IsTruncated>true</IsTruncated>" in text and found:
            marker = found[-1]
        else:
            return keys


# --------------------------------------------------------------------------- OKX
def okx_file_months(family: str, module: int, begin: str, end: str) -> list[str]:
    """Months (YYYY-MM) or days (YYYY-MM-DD) of OKX historical files of a family (names only)."""
    out, start = set(), ms(begin)
    stop = ms(end)
    while start < stop:
        upper = min(start + 270 * DAY_MS, stop)
        doc = json.loads(_get(f"{OKX}/api/v5/public/market-data-history?module={module}&instType=SWAP&instFamilyList={family}"
                              f"&dateAggrType=monthly&begin={start}&end={upper}"))
        if doc.get("code") != "0":
            raise RuntimeError(f"{family}: {doc.get('msg')}")
        for x in doc["data"]:
            for d in x["details"]:
                for g in d["groupDetails"]:
                    m = re.search(r"(\d{4}-\d{2})\.zip$", g["filename"])
                    if m:
                        out.add(m.group(1))
        start = upper + 1
    return sorted(out)


def okx_first_day(family: str, month: str) -> str | None:
    begin = ms(month + "-01") - DAY_MS
    days = set()
    for k in range(4):
        doc = json.loads(_get(f"{OKX}/api/v5/public/market-data-history?module=2&instType=SWAP&instFamilyList={family}"
                              f"&dateAggrType=daily&begin={begin + k * 9 * DAY_MS}&end={begin + (k + 1) * 9 * DAY_MS - 1}"))
        for x in doc.get("data", []):
            for d in x["details"]:
                for g in d["groupDetails"]:
                    m = re.search(r"(\d{4}-\d{2}-\d{2})\.zip$", g["filename"])
                    if m and m.group(1).startswith(month):
                        days.add(m.group(1))
    return min(days) if days else None


# --------------------------------------------------------------------------- build
def build(meta: Path, swing_okx: Path, out: Path) -> dict:
    okx_live = {i["instId"]: i for i in json.loads((meta / "okx-swap.json").read_text())["data"]}
    binance = {s["symbol"]: s for s in json.loads((meta / "binance-fapi.json").read_text())["symbols"]}
    archive = json.loads((meta / "binance-um-archive-symbols.json").read_text())
    audit = json.loads((swing_okx / "instrument-audit.json").read_text())["instruments"]
    inventory = json.loads((swing_okx / "inventory.json").read_text())["instruments"]

    # --- Binance perpetual listings (first listing of each normalised base, USDT perpetual on coins) ---
    archive_first_path = meta / "binance-archive-first-day.json"
    archive_first = json.loads(archive_first_path.read_text()) if archive_first_path.exists() else {}
    binance_events: dict[str, dict] = {}
    for symbol in sorted(set(archive) | set(binance)):
        if not symbol.endswith("USDT") or not re.fullmatch(r"[A-Z0-9]+", symbol):
            continue  # non-ASCII symbols (a few meme contracts) are listed in the audit as not probed
        info = binance.get(symbol)
        if info is not None and (info["contractType"] != "PERPETUAL" or info.get("underlyingType") != "COIN"):
            continue
        base = normalise(symbol[:-4])
        if info is not None:
            t0, source = int(info["onboardDate"]), "binance_exchangeinfo_onboardDate"
            delisted = info["status"] != "TRADING"
            delist_ms = int(info["deliveryDate"]) if delisted else None
        else:
            if symbol not in archive_first:
                keys = s3_keys(f"data/futures/um/daily/klines/{symbol}/1m/")
                days = sorted(re.findall(r"-(\d{4}-\d{2}-\d{2})\.zip$", " ".join(k for k in keys if k.endswith(".zip"))))
                archive_first[symbol] = days[0] if days else None
                archive_first_path.write_text(json.dumps(archive_first, indent=1, sort_keys=True))
            day = archive_first[symbol]
            if day is None:
                continue
            t0, source, delisted, delist_ms = ms(day), "binance_archive_first_daily_file", True, None
        current = binance_events.get(base)
        if current is None or t0 < current["t0_ms"]:
            binance_events[base] = {"base": base, "symbol": symbol, "t0_ms": t0, "t0": iso(t0), "date_source": source,
                                    "delisted": delisted, "delist_ms": delist_ms, "status": (info or {}).get("status", "ARCHIVE_ONLY")}

    # --- OKX perpetual listings: live contracts + archive families (the swing v2 OKX inventory + Binance bases) ---
    extra_path = meta / "okx-extra-families.json"
    extra = json.loads(extra_path.read_text()) if extra_path.exists() else {}
    known = {k.split("-")[0] for k in audit} | {b for b, v in inventory.items() if v.get("files")}
    probe = sorted({e["base"] for e in binance_events.values()} - known - {k.split("-")[0] for k in okx_live})
    for base in probe:
        if base in extra:
            continue
        months = okx_file_months(f"{base}-USDT", 2, "2022-09-01", "2026-10-01")
        extra[base] = {"months": months, "first_day": okx_first_day(f"{base}-USDT", months[0]) if months else None}
        extra_path.write_text(json.dumps(extra, indent=1, sort_keys=True))

    okx_events: dict[str, dict] = {}
    bases = {k.split("-")[0] for k, v in okx_live.items() if v["settleCcy"] == "USDT"} | known | {b for b, v in extra.items() if v["months"]}
    for base in sorted(bases):
        inst = f"{base}-USDT-SWAP"
        live = okx_live.get(inst)
        segments = audit.get(inst, {}).get("segments", [])
        first_candle_ms = segments[0]["start_s"] * 1000 if segments else (ms(extra[base]["first_day"]) if extra.get(base, {}).get("first_day") else None)
        if live is not None and live.get("instCategory") != "1":
            continue
        if live is not None:
            t0 = int(live["listTime"])
            source = "okx_instruments_listTime"
            premarket = live.get("ruleType") == "pre_market" or bool(live.get("preMktSwTime"))
            normal_start = int(live["preMktSwTime"]) if live.get("preMktSwTime") else (None if live.get("ruleType") == "pre_market" else t0)
            verified = True
        else:
            t0, source, premarket, normal_start, verified = first_candle_ms, "okx_archive_first_candle", None, first_candle_ms, False
        if t0 is None:
            continue
        # A live contract whose listTime is much later than the archived history is a re-listing (new contract):
        relisted = bool(live is not None and first_candle_ms is not None and t0 - first_candle_ms > 7 * DAY_MS)
        okx_events[base] = {
            "base": base, "instId": inst, "t0_ms": t0, "t0": iso(t0), "date_source": source, "date_verified": verified,
            "first_archived_candle": iso(first_candle_ms), "first_candle_minus_t0_h": None if first_candle_ms is None else round((first_candle_ms - t0) / 3.6e6, 1),
            "live": live is not None, "delisted": live is None, "premarket": premarket, "normal_trading_start": iso(normal_start),
            "relisting_of_earlier_contract": relisted, "segments": len(segments),
            "ct_val": float(live["ctVal"]) if live else (segments[0]["ct_val_observed"]["median"] if segments else None),
            "ct_val_ccy": live.get("ctValCcy") if live else None, "lot_sz": float(live["lotSz"]) if live else (segments[0]["lot_inferred"] if segments else None),
            "min_sz": float(live["minSz"]) if live else None, "tick_sz": float(live["tickSz"]) if live else (segments[0]["tick_inferred"] if segments else None),
            "excluded_base": base in PROGRAMME or base in STABLE,
        }
    out.mkdir(parents=True, exist_ok=True)
    (out / "okx-events.json").write_text(json.dumps(okx_events, indent=1, sort_keys=True))
    (out / "binance-events.json").write_text(json.dumps(binance_events, indent=1, sort_keys=True))
    return {"okx": len(okx_events), "binance": len(binance_events)}


# --------------------------------------------------------------------------- classify
def venue_order(okx: dict | None, binance: dict | None) -> str:
    """Order of the first perpetual listings of a base across OKX and Binance (other venues are not observed)."""
    if okx is not None and okx.get("premarket"):
        return "PREMARKET"
    if okx is None or binance is None:
        return "FIRST_OBSERVED_PERP_LISTING"
    a, b = okx["t0_ms"], binance["t0_ms"]
    uncertain = not okx.get("date_verified", True) or binance.get("date_source") != "binance_exchangeinfo_onboardDate"
    if uncertain and abs(a - b) <= 31 * DAY_MS:
        return "UNKNOWN_ORDER"
    if a <= b + SAME_TIME_MS:
        return "FIRST_OBSERVED_PERP_LISTING"
    return "FOLLOW_ON_VENUE_LISTING"


def classify(out: Path, funding: dict | None = None) -> dict:
    okx = json.loads((out / "okx-events.json").read_text())
    binance = json.loads((out / "binance-events.json").read_text())
    lo, hi = ms(WINDOW[0]), ms(WINDOW[1])
    rows = []
    for venue, events, other in (("okx", okx, binance), ("binance", binance, okx)):
        for base, event in events.items():
            if not lo <= event["t0_ms"] < hi:
                continue
            order = venue_order(event if venue == "okx" else other.get(base), other.get(base) if venue == "okx" else event)
            if venue == "binance" and order == "FIRST_OBSERVED_PERP_LISTING" and base in okx and okx[base]["t0_ms"] <= event["t0_ms"] + SAME_TIME_MS:
                order = "FOLLOW_ON_VENUE_LISTING" if okx[base]["t0_ms"] < event["t0_ms"] - SAME_TIME_MS else "CO_LISTED_ASSIGNED_TO_OKX"
            if venue == "binance" and order == "PREMARKET":  # the OKX pre-market flag concerns the OKX event only
                order = "FIRST_OBSERVED_PERP_LISTING" if base not in okx or okx[base]["t0_ms"] > event["t0_ms"] + SAME_TIME_MS else "FOLLOW_ON_VENUE_LISTING"
            verified = event.get("date_verified", event.get("date_source") == "binance_exchangeinfo_onboardDate")
            rows.append({"venue": venue, "base": base, "t0": event["t0"], "order": order, "date_verified": verified,
                         "delisted": event["delisted"], "excluded_base": base in PROGRAMME or base in STABLE,
                         "relisting": event.get("relisting_of_earlier_contract", False),
                         "coverage_ok": venue == "binance" or (event.get("first_candle_minus_t0_h") is not None and abs(event["first_candle_minus_t0_h"]) <= 24),
                         "funding": (funding or {}).get(f"{venue}:{base}")})
    (out / "events-classified.json").write_text(json.dumps(rows, indent=1, sort_keys=True))
    return {"rows": len(rows)}


def eligible(row: dict) -> bool:
    """Primary-analysis eligibility (fixed in the protocol, §2)."""
    return (not row["excluded_base"] and row["order"] == "FIRST_OBSERVED_PERP_LISTING" and row["date_verified"]
            and not row["relisting"] and row["coverage_ok"] and row.get("funding") is not False)


def summarize(out: Path) -> dict:
    rows = json.loads((out / "events-classified.json").read_text())
    summary = {}
    for venue in ("okx", "binance"):
        r = [x for x in rows if x["venue"] == venue and not x["excluded_base"]]
        summary[venue] = {
            "listings_in_window": len(r),
            "by_order": dict(Counter(x["order"] for x in r)),
            "date_verified": sum(x["date_verified"] for x in r),
            "date_unverified": sum(not x["date_verified"] for x in r),
            "delisted": sum(x["delisted"] for x in r),
            "relisting": sum(x["relisting"] for x in r),
            "coverage_not_ok": sum(not x["coverage_ok"] for x in r),
            "funding_available": sum(x["funding"] is True for x in r),
            "funding_missing": sum(x["funding"] is False for x in r),
            "funding_unchecked": sum(x["funding"] is None for x in r),
            "eligible_primary": sum(eligible(x) for x in r),
            "by_year": dict(Counter(x["t0"][:4] for x in r)),
            "eligible_by_year": dict(Counter(x["t0"][:4] for x in r if eligible(x))),
        }
    summary["excluded_programme_or_stable"] = sum(x["excluded_base"] for x in rows)
    (out / "audit-summary.json").write_text(json.dumps(summary, indent=1, sort_keys=True))
    return summary


def check_funding(out: Path) -> dict:
    """Presence of funding files around each in-window listing (file names only): OKX monthly index, Binance S3 listing."""
    rows = json.loads((out / "events-classified.json").read_text())
    path = out / "funding-presence.json"
    found = json.loads(path.read_text()) if path.exists() else {}
    for row in rows:
        key = f"{row['venue']}:{row['base']}"
        if key in found or row["excluded_base"]:
            continue
        month = row["t0"][:7]
        if row["venue"] == "okx":
            months = okx_file_months(f"{row['base']}-USDT", 3, month + "-01", (datetime.fromisoformat(month + "-01") .replace(day=28)).strftime("%Y-%m-%d"))
            found[key] = bool(months)
        else:
            sym = json.loads((out / "binance-events.json").read_text())[row["base"]]["symbol"]
            keys = s3_keys(f"data/futures/um/monthly/fundingRate/{sym}/")
            y, m = map(int, month.split("-"))
            following = f"{y + (m == 12):04d}-{m % 12 + 1:02d}"
            found[key] = any(f"-fundingRate-{month}.zip" in k or f"-fundingRate-{following}.zip" in k for k in keys)
        path.write_text(json.dumps(found, indent=1, sort_keys=True))
    return found


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_listing.audit")
    parser.add_argument("command", choices=("build", "funding", "classify", "summarize"))
    parser.add_argument("--meta", required=True)
    parser.add_argument("--swing-okx", default="")
    parser.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    out = Path(args.out)
    if args.command == "build":
        print(json.dumps(build(Path(args.meta), Path(args.swing_okx), out)))
    elif args.command == "funding":
        classify(out)
        print(len(check_funding(out)))
    elif args.command == "classify":
        path = out / "funding-presence.json"
        print(json.dumps(classify(out, json.loads(path.read_text()) if path.exists() else None)))
    else:
        print(json.dumps(summarize(out), indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
