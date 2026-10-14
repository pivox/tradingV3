"""H1 S_B (Binance USD-M) — decisive Gate 1 under PREREGISTRATION-H1 v1 + A1 + A2 (owner's explicit go, 2026-10-15).

Binance is a **statistical data source only** (Binance Vision dataset terms, CC BY-NC-SA 4.0, research use; never an
execution venue). Steps, each written before the next:

1. ``plan``: the S_B events (frozen audit eligibility, venue Binance) and the 1-minute months they need. Metadata only.
2. ``acquire``: 1-minute klines of each event's window (monthly, plus daily files for October 2026) and the daily
   klines of every USDT perpetual for the volume ranking (``scripts.research_exec.acquire``; sha256-checked; zips
   deleted after conversion).
3. ``volumes``: daily quote volume per symbol (from the daily klines).
4. ``hourly``: the hourly klines of the established candidates (top 50 of the previous month at each event) and the
   index members.
5. ``prepare`` (prices up to T only) and ``outcomes`` (one read; refused if the output exists): ``study.py``.
6. ``verdict``: the decisive criteria of v1 §9.2 and A2 §3.2.
"""

from __future__ import annotations

import argparse
import json
import math
import statistics
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import audit as au
from . import study as st

DAY_S = 86_400
LAST_MONTHLY = "2026-09"  # monthly archives exist up to September 2026; October 2026 comes from daily files


def month_str(t: int) -> str:
    return datetime.fromtimestamp(t, tz=timezone.utc).strftime("%Y-%m")


def day_str(t: int) -> str:
    return datetime.fromtimestamp(t, tz=timezone.utc).strftime("%Y-%m-%d")


def months_between(a: int, b: int) -> list[str]:
    out, t = [], a
    while month_str(t) <= month_str(b):
        out.append(month_str(t))
        d = datetime.fromtimestamp(t, tz=timezone.utc)
        t = int(datetime(d.year + (d.month == 12), d.month % 12 + 1, 1, tzinfo=timezone.utc).timestamp())
    return out


def events(campaign: Path) -> list[dict]:
    rows = json.loads((campaign / "audit" / "events-classified.json").read_text())
    binance = json.loads((campaign / "audit" / "binance-events.json").read_text())
    out = []
    for r in rows:
        if r["venue"] == "binance" and au.eligible(r):
            e = binance[r["base"]]
            out.append({"base": r["base"], "symbol": e["symbol"], "t0": e["t0_ms"] // 1000})
    return sorted(out, key=lambda e: e["t0"])


def plan(campaign: Path, out: Path) -> dict:
    evs = events(campaign)
    minute_monthly, minute_daily = defaultdict(set), defaultdict(set)
    for e in evs:
        a, b = e["t0"] - DAY_S, e["t0"] + 33 * DAY_S
        for m in months_between(a, b):
            if m <= LAST_MONTHLY:
                minute_monthly[e["symbol"]].add(m)
        t = max(a, int(datetime(2026, 10, 1, tzinfo=timezone.utc).timestamp()))
        while t <= b and day_str(t) <= "2026-10-14":
            minute_daily[e["symbol"]].add(day_str(t))
            t += DAY_S
    meta = campaign / "metadata"
    archive = json.loads((meta / "binance-um-archive-symbols.json").read_text())
    fapi = {s["symbol"]: s for s in json.loads((meta / "binance-fapi.json").read_text())["symbols"]}
    perps = sorted(s for s in archive if s.endswith("USDT") and s.isascii() and s.isalnum()
                   and (s not in fapi or (fapi[s]["contractType"] == "PERPETUAL" and fapi[s].get("underlyingType") == "COIN"))
                   and au.normalise(s[:-4]) not in au.STABLE)
    first_day = json.loads((meta / "binance-archive-first-day.json").read_text())
    # The ranking at T reads only the month before T, and only instruments listed >= 180 days before T: a (symbol,
    # month) pair is needed iff some event has that previous month and the symbol was established at its T.
    volume_months = sorted({month_str(st.prev_month_bounds(st.ceil_hour(e["t0"] + DAY_S))[0]) for e in evs})
    volume_pairs = defaultdict(set)
    for s in perps:  # listing = exchangeInfo onboardDate, else the archive's first day
        listed = int(fapi[s]["onboardDate"]) // 1000 if s in fapi else (
            int(datetime.strptime(first_day[s], "%Y-%m-%d").replace(tzinfo=timezone.utc).timestamp()) if s in first_day else None)
        if listed is None:
            continue
        for e in evs:
            T = st.ceil_hour(e["t0"] + DAY_S)
            lo, hi = st.prev_month_bounds(T)
            gone = int(fapi[s]["deliveryDate"]) // 1000 if s in fapi else None  # delisted before that month: no archive
            if listed <= T - st.ESTABLISHED_DAYS * DAY_S and (gone is None or gone > lo):
                volume_pairs[s].add(month_str(st.prev_month_bounds(T)[0]))
    volume_pairs = {k: sorted(v) for k, v in sorted(volume_pairs.items())}
    document = {"events": evs, "minute_monthly": {k: sorted(v) for k, v in minute_monthly.items()},
                "minute_daily": {k: sorted(v) for k, v in minute_daily.items()}, "volume_pairs": volume_pairs, "volume_months": volume_months,
                "counts": {"events": len(evs), "minute_monthly_files": sum(len(v) for v in minute_monthly.values()),
                           "minute_daily_files": sum(len(v) for v in minute_daily.values()),
                           "volume_symbols": len(volume_pairs), "daily_kline_files": sum(len(v) for v in volume_pairs.values())}}
    out.write_text(json.dumps(document, indent=1))
    for key in ("minute_monthly", "minute_daily", "volume_pairs"):  # pairs files for ``acquire --pairs-file``
        out.with_name(f"pairs-{key}.json").write_text(json.dumps(document[key]))
    return document["counts"]


def volumes(tape: Path, fapi_path: Path, archive_first: Path, out: Path) -> dict:
    """{symbol: {day: [quote volume, source, 1440]}} from the daily klines; listing time per symbol."""
    fapi = {s["symbol"]: s for s in json.loads(fapi_path.read_text())["symbols"]}
    vol, listing = {}, {}
    for d in sorted((tape / "klines1d").iterdir()):
        days = {}
        first = None
        for p in sorted(d.glob("*.npz")):
            with np.load(p) as z:
                for ts, q in zip(z["ts_s"].tolist(), z["quote_volume"].tolist()):
                    days[str(ts // DAY_S)] = [q, "binance_1d_quote", 1440]
                    first = ts if first is None else min(first, ts)
        vol[d.name] = days
        info = fapi.get(d.name)
        listing[d.name] = int(info["onboardDate"]) // 1000 if info else first
    out.write_text(json.dumps({"volumes": vol, "listing": listing}))
    return {"symbols": len(vol)}


def candidates(plan_doc: dict, vol_doc: dict) -> dict:
    """Symbol-months of hourly klines needed: the top-50 established candidates (index = top 30) at each event's T."""
    need = defaultdict(set)
    volumes_, listing = vol_doc["volumes"], vol_doc["listing"]
    for e in plan_doc["events"]:
        T = st.ceil_hour(e["t0"] + DAY_S)
        ranking = [s for s in st.established_ranking(volumes_, listing, T) if s != e["symbol"]][:st.CANDIDATES]
        for s in ranking:
            for m in months_between(T - 2 * DAY_S, T + 31 * DAY_S):
                if m <= LAST_MONTHLY:
                    need[s].add(m)
    return {k: sorted(v) for k, v in need.items()}


def prepare(campaign: Path, tape: Path, vol_path: Path, out: Path) -> dict:
    """``study.prepare`` logic for Binance: events from the S_B list, 1-minute event series, hourly control series."""
    plan_doc = json.loads((campaign / "gate1-sb" / "plan.json").read_text())
    vol_doc = json.loads(vol_path.read_text())
    volumes_, listing = vol_doc["volumes"], vol_doc["listing"]
    cache: dict = {}

    def hourly(sym: str):
        if sym not in cache:
            cache[sym] = st.Series.load(tape, sym, hourly=True, subdir="klines1h")
        return cache[sym]

    records = []
    for e in plan_doc["events"]:
        T = st.ceil_hour(e["t0"] + DAY_S)
        rec = {"group": "primary", "base": e["base"], "instId": e["symbol"], "t0": e["t0"], "T": T, "month": st.month_of(T), "exclusions": []}
        ev = st.Series.load(tape, e["symbol"], hourly=True, subdir="klines")
        if ev is None:
            rec["exclusions"].append("no_tape_data")
            records.append(rec)
            continue
        if ev.price_before(T) is None:
            rec["exclusions"].append("no_price_at_T")
        sigma = st.hourly_sigma(ev, T)
        rec["sigma_h"] = sigma
        if sigma is None or sigma <= 0:
            rec["exclusions"].append("no_sigma_at_T")
        ranking = [s for s in st.established_ranking(volumes_, listing, T) if s != e["symbol"]][:st.CANDIDATES]
        usable, admissible = {}, []
        for c in ranking:
            cs = hourly(c)
            if cs is None or cs.price_before(T) is None:
                continue
            cs_sigma = st.hourly_sigma(cs, T)
            if cs_sigma is None or cs_sigma <= 0:
                continue
            usable[c] = cs_sigma
            if sigma and sigma > 0 and abs(math.log(cs_sigma / sigma)) <= st.TOLERANCE:
                admissible.append((abs(math.log(cs_sigma / sigma)), c, cs_sigma, ranking.index(c) + 1))
        admissible.sort()
        rec["controls"] = [{"instId": c, "sigma_h": cs, "log_ratio": g, "volume_rank": k} for g, c, cs, k in admissible[:st.MAX_CONTROLS]]
        rec["admissible_controls"] = len(admissible)
        rec["comparable"] = len(admissible) >= st.MIN_CONTROLS
        chosen = [s for s in ranking if s in usable][:st.A2_CONTROLS]
        rec["controls_a2"] = [{"instId": c, "sigma_h": usable[c], "volume_rank": ranking.index(c) + 1,
                               "log_vol_ratio": math.log(usable[c] / sigma) if sigma else None} for c in chosen]
        rec["a2_usable"] = len(chosen) >= st.MIN_CONTROLS
        rec["index"] = st.established_ranking(volumes_, listing, T)[:st.INDEX_SIZE]
        records.append(rec)
    usable_events = [r for r in records if not r["exclusions"]]
    diagnostics = {"primary": {
        "events": len(records),
        "excluded_data_quality": dict(Counter(x for r in records for x in r["exclusions"])),
        "usable": len(usable_events),
        "a2_events_with_3_controls": sum(r["a2_usable"] for r in usable_events),
        "a2_coverage": sum(r["a2_usable"] for r in usable_events) / len(usable_events) if usable_events else None,
        "v1_share_without_comparable_controls": sum(not r["comparable"] for r in usable_events) / len(usable_events) if usable_events else None,
        "event_sigma_median": statistics.median([r["sigma_h"] for r in usable_events]) if usable_events else None,
        "a2_event_over_control_vol_median": statistics.median([math.exp(-c["log_vol_ratio"]) for r in usable_events for c in r["controls_a2"]
                                                               if c["log_vol_ratio"] is not None]) if usable_events else None,
    }}
    out.write_text(json.dumps({"stage": "prepare", "venue": "binance", "written_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                               "events": records, "diagnostics": diagnostics}, indent=1))
    return diagnostics


# --------------------------------------------------------------------------- decisive verdict
def verdict(outcomes_path: Path, out: Path) -> dict:
    doc = json.loads(outcomes_path.read_text())
    ev = [e for e in doc["events"] if "excluded" not in e and not e.get("quality_excluded")]
    report = doc["report"]["primary"]
    checks = {}
    details = {}
    for tag in ("main", "sens"):
        s = [e[f"{tag}_s14"] for e in ev if f"{tag}_s14" in e]
        block = report[tag]
        mean = block["s14"]["mean"] if s else None
        checks[f"{tag}_1_mean_le_minus_5pct"] = mean is not None and mean <= -0.05
        checks[f"{tag}_1_ci_upper_lt_0_1m"] = bool(block["s14_ci90_1m"]) and block["s14_ci90_1m"][1] < 0
        checks[f"{tag}_1_ci_upper_lt_0_2m"] = bool(block["s14_ci90_2m"]) and block["s14_ci90_2m"][1] < 0
        ar_event = [e[f"{tag}_ar14"] for e in ev if f"{tag}_ar14" in e and f"{tag}_control_ar14_median" in e]
        ar_ctrl = [e[f"{tag}_control_ar14_median"] for e in ev if f"{tag}_ar14" in e and f"{tag}_control_ar14_median" in e]
        checks[f"{tag}_2_median_event_ar_lt_median_control_ar"] = bool(ar_event) and statistics.median(ar_event) < statistics.median(ar_ctrl)
        checks[f"{tag}_2_p_negative_ge_55pct"] = bool(s) and sum(x < 0 for x in s) / len(s) >= 0.55
        halves = defaultdict(list)
        for e in ev:
            if f"{tag}_s14" in e:
                halves[e["month"][:4] + ("H1" if int(e["month"][5:]) <= 6 else "H2")].append(e[f"{tag}_s14"])
        big = {k: v for k, v in halves.items() if len(v) >= 15}
        neg = sum(1 for v in big.values() if sum(v) / len(v) < 0)
        checks[f"{tag}_3_negative_in_two_thirds_of_half_years"] = bool(big) and neg >= math.ceil(2 * len(big) / 3)
        total = sum(s) if s else 0.0
        srt = sorted(s)
        k = max(1, int(0.05 * len(srt)))
        by_month = defaultdict(float)
        for e in ev:
            if f"{tag}_s14" in e:
                by_month[e["month"]] += e[f"{tag}_s14"]
        checks[f"{tag}_4_mean_without_5pct_most_negative_lt_0"] = bool(srt) and sum(srt[k:]) / len(srt[k:]) < 0
        checks[f"{tag}_4_no_month_over_25pct"] = total < 0 and max(v / total for v in by_month.values()) <= 0.25
        checks[f"{tag}_4_no_event_over_5pct"] = total < 0 and max(x / total for x in s) <= 0.05
        details[tag] = {"half_years": {h: (len(v), sum(v) / len(v)) for h, v in sorted(halves.items())}, "max_month_share": max(v / total for v in by_month.values()) if total else None,
                        "max_event_share": max(x / total for x in s) if total else None, "mean_without_5pct": sum(srt[k:]) / len(srt[k:]) if srt else None}
        z = block["z14"]
        checks[f"{tag}_A2_z_mean_lt_0"] = z.get("n", 0) > 0 and z["mean"] < 0
        checks[f"{tag}_A2_z_ci_upper_lt_0_1m"] = bool(block["z14_ci90_1m"]) and block["z14_ci90_1m"][1] < 0
        checks[f"{tag}_A2_z_ci_upper_lt_0_2m"] = bool(block["z14_ci90_2m"]) and block["z14_ci90_2m"][1] < 0
    prepared = json.loads(Path(doc.get("prepared_path", outcomes_path.with_name("prepare.json"))).read_text())
    coverage = prepared["diagnostics"]["primary"]["a2_coverage"]
    checks["5_a2_coverage_ge_70pct"] = coverage is not None and coverage >= 0.70
    passed = all(checks.values())
    document = {"set": "S_B (Binance USD-M, statistics only)", "verdict": "GATE1_PASS" if passed else "GATE1_FAIL",
                "checks": checks, "details": details, "failed": [k for k, v in checks.items() if not v]}
    out.write_text(json.dumps(document, indent=1, default=lambda v: None))
    return document


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_listing.sb")
    parser.add_argument("command", choices=("plan", "volumes", "candidates", "prepare", "outcomes", "verdict"))
    parser.add_argument("--campaign", required=True)
    parser.add_argument("--tape", default="")
    parser.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    campaign = Path(args.campaign)
    if args.command == "plan":
        print(json.dumps(plan(campaign, Path(args.out))))
    elif args.command == "volumes":
        print(json.dumps(volumes(Path(args.tape), campaign / "metadata" / "binance-fapi.json", campaign / "metadata" / "binance-archive-first-day.json", Path(args.out))))
    elif args.command == "candidates":
        need = candidates(json.loads((campaign / "gate1-sb" / "plan.json").read_text()), json.loads((campaign / "gate1-sb" / "volumes.json").read_text()))
        Path(args.out).write_text(json.dumps(need, indent=1))
        print(json.dumps({"symbols": len(need), "files": sum(len(v) for v in need.values())}))
    elif args.command == "prepare":
        print(json.dumps(prepare(campaign, Path(args.tape), campaign / "gate1-sb" / "volumes.json", Path(args.out)), indent=1))
    elif args.command == "outcomes":  # one read: study.outcomes refuses an existing output
        g = campaign / "gate1-sb"
        print(json.dumps(st.outcomes(g / "prepare.json", Path(args.tape), g / "outcomes.json", control_subdir="klines1h",
                                     set_label="S_B (Binance USD-M, statistics only)"), indent=1))
    else:
        print(json.dumps(verdict(campaign / "gate1-sb" / "outcomes.json", Path(args.out)), indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
