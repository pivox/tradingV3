"""H1 Gate 1 event study (protocol `PREREGISTRATION-H1.md`, frozen 2026-10-14T20:12:44Z, sha256 10d4f0c3…).

Two passes, in this order, enforced by the code:

1. ``prepare``:
   - event list (frozen eligibility of ``audit.eligible``, plus the archive-dated delisted first listings for the
     survivorship sensitivity);
   - data-quality exclusions;
   - control matching with prices up to T only;
   - comparability diagnostics.
   It writes ``prepare.json`` and reads no price after any event's T.
2. ``outcomes``: reads ``prepare.json`` and computes the outcomes after T. It is refused when its output exists
   (one read per set).

Venue: OKX (S_A screen). The S_B (Binance) run needs the owner's separate authorisation.
"""

from __future__ import annotations

import argparse
import json
import math
import random
import statistics
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import audit as au
from .conventions import log_return, normalized_return, paired_surplus, select_established_controls

HOUR = 3600
DAY = 86_400
HORIZONS = (1, 3, 7, 14, 30)
PRIMARY = 14
ESTABLISHED_DAYS = 180
INDEX_SIZE = 30
CANDIDATES = 50
TOLERANCE = math.log(3.0)
MAX_CONTROLS = 20
MIN_CONTROLS = 3
MAX_UNCOMPARABLE = 0.30
MAX_MISSING_SECONDS = 6 * HOUR
A2_CONTROLS = 20  # amendment A2: the 20 first established perpetuals of the volume ranking with data at T
SEED = 20261014


# --------------------------------------------------------------------------- series
class Series:
    """1-minute closes of one instrument, indexed by open time (seconds)."""

    def __init__(self, ts: np.ndarray, close: np.ndarray):
        order = np.argsort(ts, kind="stable")
        ts, close = ts[order], close[order]
        keep = np.ones(ts.size, dtype=bool)
        keep[1:] = ts[1:] != ts[:-1]
        self.ts, self.close = ts[keep], close[keep]

    @classmethod
    def load(cls, tape: Path, inst: str, hourly: bool = False) -> "Series | None":
        """``hourly``: keep only the last candle of each hour (enough for prices at hour marks; small in memory)."""
        files = sorted((tape / "klines" / inst).glob("*.npz"))
        if not files:
            return None
        parts = [np.load(p) for p in files]
        series = cls(np.concatenate([p["ts_s"] for p in parts]).astype(np.int64), np.concatenate([p["close"] for p in parts]))
        if hourly:
            bucket = series.ts // HOUR
            last = np.flatnonzero(np.diff(bucket, append=bucket[-1] + 1) != 0)
            series.ts, series.close = series.ts[last], series.close[last]
        return series

    def price_before(self, t: int, max_age: int = HOUR) -> float | None:
        """Close of the last candle whose minute ends at or before ``t`` (open ≤ t − 60), at most ``max_age`` old."""
        i = int(np.searchsorted(self.ts, t - 60, side="right")) - 1
        if i < 0 or t - 60 - self.ts[i] > max_age:
            return None
        return float(self.close[i])

    def missing_seconds(self, a: int, b: int) -> int:
        lo, hi = np.searchsorted(self.ts, a), np.searchsorted(self.ts, b)
        return max(0, (b - a) - 60 * int(hi - lo))

    def window(self, a: int, b: int) -> np.ndarray:
        lo, hi = np.searchsorted(self.ts, a), np.searchsorted(self.ts, b)
        return self.close[lo:hi]

    @property
    def end(self) -> int:
        return int(self.ts[-1]) + 60


def hourly_sigma(series: Series, t: int) -> float | None:
    """σ_h = std of the 23 hourly log returns between the hour marks t − 23 h … t, × √24 (prices up to t only).

    Implementation note (amendment A1): 23 returns instead of 24, because a listing on the hour has no price at
    t − 24 h; the same rule applies to events and controls."""
    prices = [series.price_before(t - 23 * HOUR + k * HOUR) for k in range(24)]
    if any(p is None for p in prices):
        return None
    returns = [math.log(b / a) for a, b in zip(prices, prices[1:])]
    return statistics.stdev(returns) * math.sqrt(24)


def ceil_hour(t: int) -> int:
    return -(-t // HOUR) * HOUR


def month_of(t: int) -> str:
    return datetime.fromtimestamp(t, tz=timezone.utc).strftime("%Y-%m")


def prev_month_bounds(t: int) -> tuple[int, int]:
    d = datetime.fromtimestamp(t, tz=timezone.utc)
    start = datetime(d.year, d.month, 1, tzinfo=timezone.utc)
    prev = datetime(d.year - (d.month == 1), (d.month - 2) % 12 + 1, 1, tzinfo=timezone.utc)
    return int(prev.timestamp()), int(start.timestamp())


# --------------------------------------------------------------------------- prepare
def established_ranking(volumes: dict, listing: dict, t: int) -> list[str]:
    """Instruments listed ≥ 180 days before t, ranked by median reliable daily quote volume of the previous month."""
    lo, hi = prev_month_bounds(t)
    rows = []
    for inst, days in volumes.items():
        base = inst.split("-")[0]
        if base in au.STABLE or base not in listing or listing[base] > t - ESTABLISHED_DAYS * DAY:
            continue
        values = [v[0] for d, v in days.items() if lo // DAY <= int(d) < hi // DAY and v[2] >= 720]
        if len(values) >= 15:
            rows.append((statistics.median(values), inst))
    rows.sort(key=lambda r: (-r[0], r[1]))
    return [inst for _, inst in rows]


def prepare(campaign: Path, tape: Path, volumes_path: Path, out: Path) -> dict:
    rows = json.loads((campaign / "audit" / "events-classified.json").read_text())
    okx_events = json.loads((campaign / "audit" / "okx-events.json").read_text())
    volumes = json.loads(volumes_path.read_text())
    listing = {b: e["t0_ms"] // 1000 for b, e in okx_events.items()}
    primary = [r for r in rows if r["venue"] == "okx" and au.eligible(r)]
    sensitivity = [r for r in rows if r["venue"] == "okx" and not r["excluded_base"] and r["order"] == "FIRST_OBSERVED_PERP_LISTING"
                   and not r["date_verified"] and not r["relisting"]]
    cache: dict[str, Series | None] = {}

    def series(inst: str) -> Series | None:  # hourly resolution is enough for prices at hour marks up to T
        if inst not in cache:
            cache[inst] = Series.load(tape, inst, hourly=True)
        return cache[inst]

    events = []
    for group, chosen in (("primary", primary), ("survivorship_sensitivity", sensitivity)):
        for r in chosen:
            ev = okx_events[r["base"]]
            inst = ev["instId"]
            t0 = ev["t0_ms"] // 1000
            T = ceil_hour(t0 + DAY)
            record = {"group": group, "base": r["base"], "instId": inst, "t0": t0, "T": T, "month": month_of(T), "exclusions": []}
            s = series(inst)
            if s is None:
                record["exclusions"].append("no_tape_data")
                events.append(record)
                continue
            if s.price_before(T) is None:
                record["exclusions"].append("no_price_at_T")
            sigma = hourly_sigma(s, T)
            record["sigma_h"] = sigma
            if sigma is None or sigma <= 0:
                record["exclusions"].append("no_sigma_at_T")
            # Controls: prices up to T only.
            ranking = [i for i in established_ranking(volumes, listing, T) if i != inst][:CANDIDATES]
            admissible = []
            for c in ranking:
                cs = series(c)
                if cs is None or sigma is None or sigma <= 0:
                    continue
                cs_sigma = hourly_sigma(cs, T)
                if cs_sigma is None or cs_sigma <= 0 or cs.price_before(T) is None:
                    continue
                gap = abs(math.log(cs_sigma / sigma))
                if gap <= TOLERANCE:
                    admissible.append((gap, c, cs_sigma, ranking.index(c) + 1))
            admissible.sort()
            # Amendment A2 primary controls: established perpetuals at T, by previous-month volume, no volatility filter.
            usable = {}
            for c in ranking:
                cs = series(c)
                if cs is None or cs.price_before(T) is None:
                    continue
                cs_sigma = hourly_sigma(cs, T)
                if cs_sigma is not None and cs_sigma > 0:
                    usable[c] = cs_sigma
            chosen = select_established_controls(ranking, set(usable), A2_CONTROLS)
            record["controls_a2"] = [{"instId": c, "sigma_h": usable[c], "volume_rank": ranking.index(c) + 1,
                                      "log_vol_ratio": math.log(usable[c] / sigma) if sigma else None} for c in chosen]
            record["a2_usable"] = len(chosen) >= MIN_CONTROLS
            record["controls"] = [{"instId": c, "sigma_h": cs, "log_ratio": g, "volume_rank": k} for g, c, cs, k in admissible[:MAX_CONTROLS]]
            record["admissible_controls"] = len(admissible)
            record["comparable"] = len(admissible) >= MIN_CONTROLS
            record["index"] = established_ranking(volumes, listing, T)[:INDEX_SIZE]
            events.append(record)
    diagnostics = {}
    for group in ("primary", "survivorship_sensitivity"):
        g = [e for e in events if e["group"] == group]
        usable = [e for e in g if not e["exclusions"]]
        ratios = [abs(c["log_ratio"]) for e in usable for c in e.get("controls", [])]
        diagnostics[group] = {
            "events": len(g), "excluded_data_quality": {k: sum(k in e["exclusions"] for e in g) for k in ("no_tape_data", "no_price_at_T", "no_sigma_at_T")},
            "usable": len(usable), "without_comparable_controls": sum(not e["comparable"] for e in usable),
            "share_without_controls": (sum(not e["comparable"] for e in usable) / len(usable)) if usable else None,
            "admissible_controls_median": statistics.median([e["admissible_controls"] for e in usable]) if usable else None,
            "abs_log_vol_ratio_median": statistics.median(ratios) if ratios else None,
            "event_sigma_median": statistics.median([e["sigma_h"] for e in usable]) if usable else None,
            "control_volume_rank_median": statistics.median([c["volume_rank"] for e in usable for c in e.get("controls", [])]) if ratios else None,
            "a2_events_with_3_controls": sum(e.get("a2_usable", False) for e in usable),
            "a2_controls_median": statistics.median([len(e.get("controls_a2", [])) for e in usable]) if usable else None,
            "a2_abs_log_vol_ratio_median": statistics.median([abs(c["log_vol_ratio"]) for e in usable for c in e.get("controls_a2", []) if c["log_vol_ratio"] is not None]) if usable else None,
            "a2_event_over_control_vol_median": statistics.median([math.exp(-c["log_vol_ratio"]) for e in usable for c in e.get("controls_a2", []) if c["log_vol_ratio"] is not None]) if usable else None,
        }
    document = {"stage": "prepare", "written_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "events": events, "diagnostics": diagnostics}
    out.write_text(json.dumps(document, indent=1))
    return diagnostics


# --------------------------------------------------------------------------- outcomes
def index_return(series_of, members: list[str], a: int, b: int) -> float | None:
    values = []
    for m in members:
        s = series_of(m)
        if s is None:
            continue
        p0, p1 = s.price_before(a), s.price_before(b)
        if p0 and p1:
            values.append(log_return(p0, p1))
    return sum(values) / len(values) if len(values) >= 10 else None


def outcome_price(s: Series, T: int, h: int, sensitivity: bool) -> tuple[float | None, str]:
    """Price at T + h; a series ending earlier (delisting) gives its last close (main) or the highest close of its
    last 24 h (sensitivity unfavourable to H1)."""
    target = T + h * DAY
    if s.end < target:
        if sensitivity:
            tail = s.window(s.end - DAY, s.end)
            return (float(tail.max()) if tail.size else None), "delisted_high"
        return float(s.close[-1]), "delisted_last"
    return s.price_before(target), "normal"


def block_ci(values_by_month: dict[str, list[float]], months_per_block: int, draws: int = 10_000, seed: int = SEED) -> list[float] | None:
    months = sorted(values_by_month)
    if len(months) < 2:
        return None
    blocks = [sum((values_by_month[m] for m in months[i:i + months_per_block]), []) for i in range(0, len(months), months_per_block)]
    blocks = [b for b in blocks if b]
    rng = random.Random(seed + months_per_block)
    means = []
    for _ in range(draws):
        sample = [x for _ in blocks for x in blocks[rng.randrange(len(blocks))]]
        means.append(sum(sample) / len(sample))
    means.sort()
    return [means[int(0.05 * draws)], means[int(0.95 * draws) - 1]]


def summarize_values(values: list[float]) -> dict:
    if not values:
        return {"n": 0}
    q = np.quantile(values, [0.01, 0.05, 0.25, 0.5, 0.75, 0.95, 0.99]).tolist()
    mean = sum(values) / len(values)
    sd = statistics.stdev(values) if len(values) > 1 else 0.0
    skew = (sum((x - mean) ** 3 for x in values) / len(values)) / sd ** 3 if sd > 0 else None
    return {"n": len(values), "mean": mean, "median": q[3], "quantiles_1_5_25_50_75_95_99": q, "sd": sd, "skew": skew,
            "p_negative": sum(x < 0 for x in values) / len(values), "p_below_minus_10pct": sum(x < -0.10 for x in values) / len(values)}


def outcomes(prepared: Path, tape: Path, out: Path) -> dict:
    if out.exists():
        raise SystemExit("outcomes refused: the output exists (one read per set)")
    doc = json.loads(prepared.read_text())
    cache: dict[str, Series | None] = {}

    def series_of(inst: str) -> Series | None:  # index and controls: hourly resolution
        if inst not in cache:
            cache[inst] = Series.load(tape, inst, hourly=True)
        return cache[inst]

    results = []
    for e in doc["events"]:
        if e["exclusions"]:
            results.append({**{k: e[k] for k in ("group", "base", "month")}, "excluded": e["exclusions"]})
            continue
        s = Series.load(tape, e["instId"])  # the event: full 1-minute resolution (gaps, path)
        T = e["T"]
        row = {k: e[k] for k in ("group", "base", "month", "T")}
        row["missing_seconds_T_T14"] = s.missing_seconds(T, T + PRIMARY * DAY)
        row["quality_excluded"] = row["missing_seconds_T_T14"] > MAX_MISSING_SECONDS
        p0 = s.price_before(T)
        for sens in (False, True):
            tag = "sens" if sens else "main"
            for h in HORIZONS:
                p1, how = outcome_price(s, T, h, sens)
                rm = index_return(series_of, e["index"], T, T + h * DAY)
                if p1 is None or rm is None:
                    continue
                r = log_return(p0, p1)
                ar = r - rm
                def control_ars(controls):
                    out = []
                    for c in controls:
                        cs = series_of(c["instId"])
                        c0 = cs.price_before(T)
                        c1 = cs.price_before(T + h * DAY) if cs.end >= T + h * DAY else float(cs.close[-1])
                        if c0 and c1:
                            out.append((log_return(c0, c1) - rm, c["sigma_h"]))
                    return out
                row[f"{tag}_r{h}"] = r
                row[f"{tag}_ar{h}"] = ar
                row[f"{tag}_how{h}"] = how
                a2 = control_ars(e["controls_a2"])
                if len(a2) >= MIN_CONTROLS:  # amendment A2 primary: raw and risk-normalised paired surplus
                    row[f"{tag}_s{h}"] = paired_surplus(ar, [x for x, _ in a2])
                    row[f"{tag}_z{h}"] = normalized_return(ar, e["sigma_h"], h) - sum(normalized_return(x, sc, h) for x, sc in a2) / len(a2)
                    row[f"{tag}_control_ar{h}_median"] = statistics.median([x for x, _ in a2])
                v1 = control_ars(e["controls"])
                if e["comparable"] and len(v1) >= MIN_CONTROLS:  # v1 volatility-matched control: secondary diagnostic
                    row[f"{tag}_s_v1{h}"] = paired_surplus(ar, [x for x, _ in v1])
        window = s.window(T, T + PRIMARY * DAY)
        if window.size:
            row["mae14_short"] = math.log(float(window.max()) / p0)
            row["mfe14_short"] = -math.log(float(window.min()) / p0)
        results.append(row)
    report = {}
    for group in ("primary", "with_survivorship_sensitivity"):
        g = [x for x in results if "excluded" not in x and not x.get("quality_excluded")
             and (x["group"] == "primary" or group == "with_survivorship_sensitivity")]
        block = {}
        for tag in ("main", "sens"):
            s14 = [x[f"{tag}_s{PRIMARY}"] for x in g if f"{tag}_s{PRIMARY}" in x]
            by_month: dict[str, list] = defaultdict(list)
            for x in g:
                if f"{tag}_s{PRIMARY}" in x:
                    by_month[x["month"]].append(x[f"{tag}_s{PRIMARY}"])
            z_by_month: dict[str, list] = defaultdict(list)
            for x in g:
                if f"{tag}_z{PRIMARY}" in x:
                    z_by_month[x["month"]].append(x[f"{tag}_z{PRIMARY}"])
            block[tag] = {
                "z14": summarize_values([x[f"{tag}_z{PRIMARY}"] for x in g if f"{tag}_z{PRIMARY}" in x]),
                "z14_ci90_1m": block_ci(z_by_month, 1), "z14_ci90_2m": block_ci(z_by_month, 2), "z14_ci90_3m": block_ci(z_by_month, 3),
                "s14_v1_volatility_matched_NOT_GENERALIZABLE": summarize_values([x[f"{tag}_s_v1{PRIMARY}"] for x in g if f"{tag}_s_v1{PRIMARY}" in x]),
                "s14": summarize_values(s14),
                "s14_ci90_1m": block_ci(by_month, 1), "s14_ci90_2m": block_ci(by_month, 2), "s14_ci90_3m": block_ci(by_month, 3),
                "months": len(by_month),
                "ar14_index": summarize_values([x[f"{tag}_ar{PRIMARY}"] for x in g if f"{tag}_ar{PRIMARY}" in x]),
                "r14_absolute": summarize_values([x[f"{tag}_r{PRIMARY}"] for x in g if f"{tag}_r{PRIMARY}" in x]),
                "secondary_horizons": {h: {"s": summarize_values([x[f"{tag}_s{h}"] for x in g if f"{tag}_s{h}" in x]),
                                            "ar": summarize_values([x[f"{tag}_ar{h}"] for x in g if f"{tag}_ar{h}" in x])} for h in HORIZONS},
                "delisted_within_14d": sum(1 for x in g if x.get(f"{tag}_how{PRIMARY}", "normal") != "normal"),
            }
        block["mae14_short"] = summarize_values([x["mae14_short"] for x in g if "mae14_short" in x])
        block["p_mae_over_25pct"] = sum(1 for x in g if x.get("mae14_short", 0) > math.log(1.25)) / len(g) if g else None
        block["p_mae_over_50pct"] = sum(1 for x in g if x.get("mae14_short", 0) > math.log(1.5)) / len(g) if g else None
        report[group] = block
    screen = {}
    for group, label in (("primary", "primary"), ("with_survivorship_sensitivity", "with_delisted")):
        for stat in ("s14", "z14"):  # amendment A2: raw AND risk-normalised paired surplus
            v = report[group]["main"][stat]
            screen[f"{label}_{stat}_mean_negative"] = v.get("n", 0) > 0 and v["mean"] < 0
            screen[f"{label}_{stat}_median_negative"] = v.get("n", 0) > 0 and v["median"] < 0
    verdict = "S_A_SCREEN_PASS" if all(screen.values()) else "S_A_SCREEN_FAIL"
    document = {"stage": "outcomes", "set": "S_A (OKX)", "written_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                "screen_checks": screen, "verdict": verdict, "report": report, "events": results}
    out.write_text(json.dumps(document, indent=1, default=lambda v: None))
    return {"verdict": verdict, "checks": screen}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_listing.study")
    parser.add_argument("command", choices=("prepare", "outcomes"))
    parser.add_argument("--campaign", required=True)
    parser.add_argument("--tape", required=True)
    parser.add_argument("--volumes", default="")
    parser.add_argument("--out", required=True)
    parser.add_argument("--prepared", default="")
    args = parser.parse_args(argv)
    if args.command == "prepare":
        print(json.dumps(prepare(Path(args.campaign), Path(args.tape), Path(args.volumes), Path(args.out)), indent=1))
    else:
        print(json.dumps(outcomes(Path(args.prepared), Path(args.tape), Path(args.out)), indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
