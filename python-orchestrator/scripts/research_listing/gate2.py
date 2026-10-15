"""H1 Gate 2 (historical, NOT executable on X-Perps): the short after listing under v1 §11–§15 + A1 + A2 + A3.

Steps, each written before the next (one read, claim first):

1. ``funding-plan``: the funding archive months each event and C1-A2 control needs, [T − 3 d, T + 15 d]. Metadata only.
2. ``funding-okx``: OKX archive funding months missing from the swing v2 tape (Binance funding goes through
   ``research_exec.acquire fundingrate --pairs-file``). Rates only, no price.
3. ``coverage``: which events and controls have a funding series over their window. Stops the run if any H1 event has
   none (no frozen rule covers a whole missing series).
4. ``run``: the portfolio, the control strategy, the criteria and the A3 diagnostics. Refuses to run twice.

Conventions are in ``conventions.py`` (frozen with v1); execution choices in ``H1-GATE2-IMPLEMENTATION-NOTE.md``.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import math
import random
import statistics
import time
import urllib.request
import zipfile
from collections import Counter, defaultdict
from dataclasses import dataclass, replace
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import conventions as cv

HOUR, DAY = 3600, 86_400
HORIZON = 14 * DAY
EQUITY, RISK, SLOTS = 500.0, 0.005, 2
R_UNIT = EQUITY * RISK  # 2.50 USDT
MAX_DD = 0.15 * EQUITY
DRAWS = 1000
SEED = 20261015
HALF_YEAR_MIN_TRADES = 15  # the Gate 1 convention (v1 §9.2.3)
LABEL = "historical, not executable on X-Perps"

PROFILES = {
    "baseline": cv.BASELINE,
    "pessimistic": cv.PESSIMISTIC,
    "baseline_stress": replace(cv.BASELINE, stop_slippage=2 * cv.BASELINE.stop_slippage),
    "pessimistic_stress": replace(cv.PESSIMISTIC, stop_slippage=2 * cv.PESSIMISTIC.stop_slippage),
}


def month_of(t: int) -> str:
    return datetime.fromtimestamp(t, tz=timezone.utc).strftime("%Y-%m")


def months(a: int, b: int) -> list[str]:
    out, t = [], a - a % DAY
    while t <= b:
        m = month_of(t)
        if m not in out:
            out.append(m)
        t += DAY
    return out


# --------------------------------------------------------------------------- data
@dataclass
class Bars:
    ts: np.ndarray
    open: np.ndarray
    high: np.ndarray
    close: np.ndarray
    step: int  # 60 (1-minute) or 3600 (hourly)

    def price_before(self, t: int) -> float | None:
        """Close of the last bar ending at or before t, at most one hour old."""
        i = int(np.searchsorted(self.ts, t - self.step, side="right")) - 1
        if i < 0 or t - self.step - self.ts[i] > HOUR:
            return None
        return float(self.close[i])

    @property
    def end(self) -> int:
        return int(self.ts[-1]) + self.step if self.ts.size else 0


def load_bars(folder: Path, inst: str, wanted: list[str], step: int) -> Bars | None:
    files = [p for p in sorted((folder / inst).glob("*.npz")) if any(m in p.name for m in wanted)]
    if not files:
        return None
    parts = [np.load(p) for p in files]
    ts = np.concatenate([p["ts_s"] for p in parts]).astype(np.int64)
    order = np.argsort(ts, kind="stable")
    ts = ts[order]
    keep = np.ones(ts.size, dtype=bool)
    keep[:-1] = ts[1:] != ts[:-1]
    cols = {k: np.concatenate([p[k] for p in parts])[order][keep] for k in ("open", "high", "close")}
    return Bars(ts[keep], cols["open"], cols["high"], cols["close"], step)


def load_funding(folders: list[Path], inst: str) -> tuple[np.ndarray, np.ndarray] | None:
    files = [p for f in folders for p in sorted((f / inst).glob("*.npz"))]
    if not files:
        return None
    rows: dict[int, float] = {}
    for p in files:
        with np.load(p) as z:
            for t, r in zip(z["ts_ms"].tolist(), z["rate"].tolist()):
                if math.isfinite(r):
                    rows[int(t) // 1000] = float(r)
    items = sorted(rows.items())
    return np.array([t for t, _ in items], dtype=np.int64), np.array([r for _, r in items], dtype=np.float64)


# --------------------------------------------------------------------------- one trade
def sigma24(bars: Bars, t0: int, T: int) -> float | None:
    """σ24 (v1 §13): 1-minute log returns over [t0, T] × √1440; on hourly bars (S_B controls) × √24."""
    lo, hi = np.searchsorted(bars.ts, t0), np.searchsorted(bars.ts, T - bars.step, side="right")
    closes = bars.close[lo:hi]
    if closes.size < 3 or np.any(closes <= 0):
        return None
    r = np.diff(np.log(closes))
    return float(np.std(r, ddof=1) * math.sqrt(DAY / bars.step))


def missing_settlements(stamps: list[int], exit_ts: int) -> list[tuple[int, int]]:
    """§12: an interval longer than 1.5 × the last known cadence hides settlements at that cadence.

    Returns (time of each missing settlement, start of its gap). The tail after the last settlement up to the exit is
    checked the same way."""
    out = []
    points = list(stamps)
    for k in range(1, len(points) + 1):
        if k < 2:
            continue
        cadence = points[k - 1] - points[k - 2]
        nxt = points[k] if k < len(points) else exit_ts
        start = points[k - 1]
        if cadence <= 0 or nxt - start <= 1.5 * cadence:
            continue
        when = start + cadence
        while when < nxt - 0.5 * cadence or (k == len(points) and when <= exit_ts):
            out.append((when, start))
            when += cadence
    return out


def funding_cash(funding, bars: Bars, T: int, exit_ts: int, entry_ref: float) -> tuple[float, int]:
    """Real settlements in (T, exit] as a fraction of the entry notional (§12), plus the §12 missing-settlement rule
    (booked against the short at the largest |rate| of the 3 days before the gap)."""
    ts, rate = funding
    total = 0.0
    for t, r in zip(ts.tolist(), rate.tolist()):
        if T < t <= exit_ts:
            total += cv.funding_cashflow("short", 1.0, (bars.price_before(t) or entry_ref) / entry_ref, r)
    window = [(t, r) for t, r in zip(ts.tolist(), rate.tolist()) if T - 3 * DAY <= t <= exit_ts]
    missing = [(w, g) for w, g in missing_settlements([t for t, _ in window], exit_ts) if T < w <= exit_ts]
    for when, gap_start in missing:
        worst = max((abs(r) for t, r in window if gap_start - 3 * DAY <= t <= gap_start), default=0.0)
        total -= worst * ((bars.price_before(when) or entry_ref) / entry_ref)
    return total, len(missing)


def xperp_funding(funding, T: int, exit_ts: int) -> float:
    """A3 D2: each recorded rate spread evenly over its own interval, re-settled every 4 h, clipped to ±1 %; after a
    4-h settlement at −1 % (the short pays the cap) the next 12 h settle every 2 h, each floored at −1 %."""
    ts, rate = funding
    seg = []  # (start, end, rate per second)
    prev = None
    for t, r in zip(ts.tolist(), rate.tolist()):
        span = (t - prev) if prev is not None else 8 * HOUR
        seg.append((t - span, t, r / max(span, 1)))
        prev = t
    if not seg:
        return 0.0
    if seg[-1][1] < exit_ts:
        seg.append((seg[-1][1], exit_ts, seg[-1][2]))

    def accrued(a: int, b: int) -> float:
        return sum(d * max(0, min(b, e) - max(a, s)) for s, e, d in seg if e > a and s < b)

    total, t, escalated_until = 0.0, T, -1
    while t < exit_ts:
        step = 2 * HOUR if t < escalated_until else 4 * HOUR
        b = min(t + step, exit_ts)
        r = max(-0.01, min(0.01, accrued(t, b)))
        total += r
        if step == 4 * HOUR and r <= -0.01:
            escalated_until = b + 12 * HOUR
        t = b
    return total


def simulate(bars: Bars, funding, t0: int, T: int, spec: dict, costs: cv.Costs, sens_exit: bool = False) -> dict:
    """One H1 short (v1 §13): taker entry at the open of the first bar after T, stop d = clamp(3σ24, 15 %, 60 %),
    stop-market at the worse of the stop and the next bar's open (and of a gapped open), exit at T + 14 d."""
    out: dict = {"T": T}
    sig = sigma24(bars, t0, T)
    if sig is None:
        return {**out, "skip": "no_sigma24"}
    i0 = int(np.searchsorted(bars.ts, T))
    if i0 >= bars.ts.size or bars.ts[i0] >= T + HOUR:
        return {**out, "skip": "no_entry_price"}
    ref = float(bars.open[i0])
    d = cv.stop_distance(sig)
    plan = cv.size_short(EQUITY, RISK, ref, d, spec["ct_val"], spec["lot"], spec["min_contracts"], costs, spec.get("min_notional", 0.0))
    out.update(sigma24=sig, stop_distance=d, entry_ref=ref)
    if plan.reason:
        return {**out, "skip": plan.reason}
    entry = ref * (1 - costs.entry_slippage)
    level = ref * (1 + d)
    end = T + HORIZON
    i1 = int(np.searchsorted(bars.ts, end))
    hit = np.flatnonzero(bars.high[i0:i1] >= level)
    if hit.size:
        i = i0 + int(hit[0])
        fill = max(level, float(bars.open[i]))
        if bars.step == 60 and i + 1 < bars.ts.size and bars.ts[i + 1] <= bars.ts[i] + 120:
            fill = max(fill, float(bars.open[i + 1]))
        exit_px, exit_ts, fee, how = fill * (1 + costs.stop_slippage), int(bars.ts[i]) + bars.step, costs.stop_fee, "stop"
    else:
        if bars.end < end:  # delisted: last close, or the highest close of the last 24 h (A3 D4)
            if sens_exit:
                lo = np.searchsorted(bars.ts, bars.end - DAY)
                px = float(bars.close[lo:].max())
            else:
                px = float(bars.close[-1])
            exit_ts, how = bars.end, "delisted"
        else:
            px = bars.price_before(end)
            if px is None:
                return {**out, "skip": "no_exit_price"}
            exit_ts, how = end, "time"
        exit_px, fee = px * (1 + costs.exit_slippage), costs.exit_fee
    fund, missing = (0.0, 0) if funding is None else funding_cash(funding, bars, T, exit_ts, ref)
    gross = (entry - exit_px) / ref
    fees = costs.entry_fee * entry / ref + fee * exit_px / ref
    net = gross - fees + fund
    return {**out, "exit": how, "exit_ts": exit_ts, "gross": gross, "fees": fees, "funding": fund, "funding_missing_booked": missing,
            "net": net, "notional": plan.notional, "pnl": plan.notional * net, "R": plan.notional * net / R_UNIT,
            "hours": (exit_ts - T) / HOUR, "xperp_funding": xperp_funding(funding, T, exit_ts) if funding is not None else 0.0}


# --------------------------------------------------------------------------- portfolio and statistics
def portfolio(trades: list[dict]) -> tuple[list[dict], Counter]:
    """Chronological by T; at most SLOTS positions open; a plan below the minimum or arriving on a full budget is skipped."""
    done, skips, open_until = [], Counter(), []
    for tr in sorted(trades, key=lambda x: (x["T"], x.get("key", ""))):
        if "skip" in tr:
            skips[tr["skip"]] += 1
            continue
        open_until = [u for u in open_until if u > tr["T"]]
        if len(open_until) >= SLOTS:
            skips["risk_budget_full"] += 1
            continue
        open_until.append(tr["exit_ts"])
        done.append(tr)
    return done, skips


def stats(done: list[dict], field: str = "R") -> dict:
    if not done:
        return {"n": 0}
    rs = [t[field] if field == "R" else t[field] for t in done]
    pnl = [t["pnl"] for t in done]
    win, loss = sum(p for p in pnl if p > 0), -sum(p for p in pnl if p < 0)
    cum, peak, dd = 0.0, 0.0, 0.0
    for t in sorted(done, key=lambda x: x["exit_ts"]):
        cum += t["pnl"]
        peak = max(peak, cum)
        dd = max(dd, peak - cum)
    halves = defaultdict(list)
    for t in done:
        m = month_of(t["T"])
        halves[m[:4] + ("H1" if int(m[5:]) <= 6 else "H2")].append(t["pnl"])
    srt = sorted(t["R"] for t in done)
    k = max(1, int(0.05 * len(srt)))
    return {"n": len(done), "mean_R": statistics.fmean(t["R"] for t in done), "median_R": statistics.median(t["R"] for t in done),
            "mean_net_return": statistics.fmean(t["net"] for t in done), "mean_gross_return": statistics.fmean(t["gross"] for t in done),
            "mean_funding": statistics.fmean(t["funding"] for t in done), "funding_missing_booked": sum(t["funding_missing_booked"] for t in done),
            "profit_factor": win / loss if loss > 0 else (math.inf if win > 0 else None), "pnl": sum(pnl), "max_drawdown": dd, "worst_R": srt[0], "best_R": srt[-1],
            "half_years": {h: [len(v), sum(v)] for h, v in sorted(halves.items())},
            "mean_R_without_top_5pct": statistics.fmean(srt[:-k]) if len(srt) > k else None,
            "stop_rate": sum(t["exit"] == "stop" for t in done) / len(done), "delisted_exits": sum(t["exit"] == "delisted" for t in done),
            "mean_notional": statistics.fmean(t["notional"] for t in done)}


def criteria(s: dict, s_stress: dict, ctrl: dict, profile: str) -> dict:
    pf_min = 1.2 if profile == "baseline" else 1.0
    big = {h: v for h, v in s.get("half_years", {}).items() if v[0] >= HALF_YEAR_MIN_TRADES}
    pos = sum(1 for v in big.values() if v[1] > 0)
    return {
        "1_at_least_60_trades": s["n"] >= 60,
        "2_mean_net_R_gt_0": s["n"] > 0 and s["mean_R"] > 0,
        "2_mean_net_R_gt_0_under_stress": s_stress.get("n", 0) > 0 and s_stress["mean_R"] > 0,
        "3_mean_absolute_net_short_return_gt_0": s["n"] > 0 and s["mean_net_return"] > 0,
        "4_surplus_over_control_gt_0_p_le_0.10": ctrl["surplus_R"] is not None and ctrl["surplus_R"] > 0 and ctrl["p"] <= 0.10,
        f"5_profit_factor_ge_{pf_min}": s.get("profit_factor") is not None and s["profit_factor"] >= pf_min,
        "6_portfolio_pnl_gt_0": s["n"] > 0 and s["pnl"] > 0,
        "7_max_drawdown_le_15pct": s["n"] > 0 and s["max_drawdown"] <= MAX_DD,
        "8_worst_trade_ge_minus_3R": s["n"] > 0 and s["worst_R"] >= -3,
        "9_positive_in_two_thirds_of_half_years": bool(big) and pos >= math.ceil(2 * len(big) / 3),
        "10_mean_R_gt_0_without_top_5pct": s.get("mean_R_without_top_5pct") is not None and s["mean_R_without_top_5pct"] > 0,
    }


def control_strategy(event_keys: list[str], ctrl_trades: dict[str, list[dict]], h1_mean_R: float, rng: random.Random) -> dict:
    """§15.4: the same rule on one C1-A2 control per event, 1,000 draws, the same portfolio."""
    means = []
    for _ in range(DRAWS):
        pick = []
        for k in event_keys:
            options = ctrl_trades.get(k) or []
            if options:
                pick.append(dict(rng.choice(options), key=k))
        done, _ = portfolio(pick)
        if done:
            means.append(statistics.fmean(t["R"] for t in done))
    if not means:
        return {"draws": 0, "surplus_R": None, "p": 1.0}
    return {"draws": len(means), "control_mean_R": statistics.fmean(means), "control_mean_R_p5_p95": [float(np.percentile(means, 5)), float(np.percentile(means, 95))],
            "surplus_R": h1_mean_R - statistics.fmean(means), "p": sum(m >= h1_mean_R for m in means) / len(means)}


# --------------------------------------------------------------------------- inputs
def load_inputs(campaign: Path, okx_tape: Path, gate2: Path) -> list[dict]:
    meta = campaign / "metadata"
    okx_events = json.loads((campaign / "audit" / "okx-events.json").read_text())
    okx_live = {i["instId"]: i for i in json.loads((meta / "okx-swap.json").read_text())["data"]}
    fapi = {s["symbol"]: s for s in json.loads((meta / "binance-fapi.json").read_text())["symbols"]}

    def okx_spec(inst: str, base: str | None = None) -> dict | None:
        if base and base in okx_events and okx_events[base].get("ct_val"):
            e = okx_events[base]
            return {"ct_val": float(e["ct_val"]), "lot": float(e["lot_sz"] or 1), "min_contracts": float(e["min_sz"] or e["lot_sz"] or 1), "min_notional": 0.0}
        i = okx_live.get(inst)
        if i is not None:
            return {"ct_val": float(i["ctVal"]), "lot": float(i["lotSz"]), "min_contracts": float(i["minSz"]), "min_notional": 0.0}
        e = okx_events.get(inst.split("-")[0])  # delisted OKX contract: archive-observed specification (§14)
        if e and e.get("ct_val"):
            return {"ct_val": float(e["ct_val"]), "lot": float(e["lot_sz"] or 1), "min_contracts": float(e["min_sz"] or e["lot_sz"] or 1), "min_notional": 0.0}
        return None

    def binance_spec(sym: str) -> dict | None:
        s = fapi.get(sym)
        if s is None:
            return None
        f = {x["filterType"]: x for x in s["filters"]}
        lot = f.get("MARKET_LOT_SIZE") or f["LOT_SIZE"]
        return {"ct_val": 1.0, "lot": float(lot["stepSize"]), "min_contracts": float(lot["minQty"]),
                "min_notional": float(f.get("MIN_NOTIONAL", {}).get("notional", 5))}

    rows = []
    for set_name, sub in (("S_A", "gate1-sa-a2"), ("S_B", "gate1-sb")):
        for e in json.loads((campaign / sub / "prepare.json").read_text())["events"]:
            if e["exclusions"] or e["group"] != "primary":
                continue
            okx = set_name == "S_A"
            rows.append({
                "key": f"{set_name}:{e['base']}", "set": set_name, "base": e["base"], "inst": e["instId"], "t0": int(e["t0"]), "T": int(e["T"]),
                "bars": (okx_tape / "klines") if okx else (campaign / "gate1-sb" / "tape" / "klines"), "step": 60,
                "funding": [okx_tape / "funding", gate2 / "funding-okx" / "funding"] if okx else [gate2 / "funding-binance" / "funding"],
                "spec": okx_spec(e["instId"], e["base"]) if okx else binance_spec(e["instId"]),
                "controls": [{"inst": c["instId"], "spec": okx_spec(c["instId"]) if okx else binance_spec(c["instId"]),
                              "bars": (okx_tape / "klines") if okx else (campaign / "gate1-sb" / "tape" / "klines1h"), "step": 60 if okx else 3600}
                             for c in e["controls_a2"]],
            })
    return rows


# --------------------------------------------------------------------------- funding acquisition (rates only)
def funding_plan(rows: list[dict], okx_tape: Path, out: Path) -> dict:
    okx_need, binance_need = defaultdict(set), defaultdict(set)
    for r in rows:
        wanted = months(r["T"] - 3 * DAY, r["T"] + 15 * DAY)
        for inst in [r["inst"]] + [c["inst"] for c in r["controls"]]:
            for m in wanted:
                if r["set"] == "S_A":
                    if not (okx_tape / "funding" / inst / f"{inst}-fundingRate-{m}.npz").exists():
                        okx_need[inst].add(m)
                else:
                    binance_need[inst].add(m)
    doc = {"okx": {k: sorted(v) for k, v in sorted(okx_need.items())}, "binance": {k: sorted(v) for k, v in sorted(binance_need.items())}}
    out.write_text(json.dumps(doc, indent=1))
    (out.parent / "pairs-funding-binance.json").write_text(json.dumps(doc["binance"]))
    return {"okx_instruments": len(doc["okx"]), "okx_files": sum(map(len, doc["okx"].values())),
            "binance_symbols": len(doc["binance"]), "binance_files": sum(map(len, doc["binance"].values()))}


def funding_okx(plan_path: Path, out: Path) -> dict:
    """OKX archive monthly funding files (module 3), sha256 recorded; the OKX archive publishes no checksum."""
    from scripts.research_swing2 import okx as okxlib

    need = json.loads(plan_path.read_text())["okx"]
    manifest_path = out / "manifest-funding-okx.json"
    manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {"files": [], "failures": [], "absent": []}
    done = {(f["instId"], f["month"]) for f in manifest["files"]} | {(f["instId"], f["month"]) for f in manifest["absent"]}
    for inst, wanted in need.items():
        todo = [m for m in wanted if (inst, m) not in done]
        if not todo:
            continue
        try:
            begin = int(datetime.strptime(todo[0], "%Y-%m").replace(tzinfo=timezone.utc).timestamp() * 1000)
            last = datetime.strptime(todo[-1], "%Y-%m")
            end = int(datetime(last.year + (last.month == 12), last.month % 12 + 1, 1, tzinfo=timezone.utc).timestamp() * 1000) - 1
            links = {l["filename"]: l for l in okxlib.file_links(3, inst, "monthly", begin, end)}
            for m in todo:
                link = next((l for n, l in links.items() if n.endswith(f"fundingrates-{m}.zip")), None)
                if link is None:
                    manifest["absent"].append({"instId": inst, "month": m})
                    continue
                time.sleep(okxlib.REQUEST_GAP_S)
                with urllib.request.urlopen(urllib.request.Request(link["url"], headers={"User-Agent": "research"}), timeout=120) as response:
                    blob = response.read()
                with zipfile.ZipFile(io.BytesIO(blob)) as archive:
                    arrays = okxlib.convert_funding(archive.read(archive.namelist()[0]))
                target = out / "funding" / inst / f"{inst}-fundingRate-{m}.npz"
                target.parent.mkdir(parents=True, exist_ok=True)
                np.savez_compressed(target, **arrays)
                manifest["files"].append({"instId": inst, "month": m, "filename": link["filename"], "zip_bytes": len(blob),
                                          "zip_sha256": hashlib.sha256(blob).hexdigest(), "rows": int(arrays["ts_ms"].size)})
        except Exception as error:  # noqa: BLE001 - recorded, reported in coverage
            manifest["failures"].append({"instId": inst, "error": str(error)[:200]})
        manifest_path.write_text(json.dumps(manifest, indent=1))
    return {"files": len(manifest["files"]), "absent": len(manifest["absent"]), "failures": len(manifest["failures"]),
            "bytes": sum(f["zip_bytes"] for f in manifest["files"])}


def coverage(rows: list[dict]) -> dict:
    """A funding series must cover each H1 event's window [T, T + 14 d] (settlements inside it). Controls are reported."""
    ev_missing, ev_partial, ctrl_missing = [], [], 0
    for r in rows:
        f = load_funding(r["funding"], r["inst"])
        inside = 0 if f is None else int(np.sum((f[0] > r["T"]) & (f[0] <= r["T"] + HORIZON)))
        if f is None or inside == 0:
            ev_missing.append(r["key"])
        elif inside < 14 * 3 * 0.5:  # fewer than half the settlements of an 8-hour cadence: reported, handled by §12
            ev_partial.append([r["key"], inside])
        for c in r["controls"]:
            g = load_funding(r["funding"], c["inst"])
            if g is None or not np.any((g[0] > r["T"]) & (g[0] <= r["T"] + HORIZON)):
                ctrl_missing += 1
    return {"events": len(rows), "events_without_funding_in_window": ev_missing, "events_partial": ev_partial,
            "control_legs": sum(len(r["controls"]) for r in rows), "control_legs_without_funding": ctrl_missing}


# --------------------------------------------------------------------------- the one read
def run(rows: list[dict], out: Path) -> dict:
    if out.exists():
        raise SystemExit("gate2 refused: the output exists (one read)")
    trades = {p: [] for p in PROFILES}
    sens = []
    ctrl = {p: defaultdict(list) for p in ("baseline", "pessimistic")}
    ctrl_skips = Counter()
    for r in rows:
        wanted = months(r["t0"] - DAY, r["T"] + 15 * DAY)
        bars = load_bars(r["bars"], r["inst"], wanted, r["step"])
        fund = load_funding(r["funding"], r["inst"])
        for p, costs in PROFILES.items():
            tr = {"skip": "no_bars"} if bars is None or r["spec"] is None else simulate(bars, fund, r["t0"], r["T"], r["spec"], costs)
            if r["spec"] is None:
                tr = {"skip": "no_specification"}
            trades[p].append({**tr, "T": r["T"], "key": r["key"], "set": r["set"]})
        if bars is not None and r["spec"] is not None:
            sens.append({**simulate(bars, fund, r["t0"], r["T"], r["spec"], cv.BASELINE, sens_exit=True), "key": r["key"], "set": r["set"]})
        for c in r["controls"]:
            cb = load_bars(c["bars"], c["inst"], wanted, c["step"])
            cf = load_funding(r["funding"], c["inst"])
            if cb is None or c["spec"] is None:
                ctrl_skips["no_bars_or_spec"] += 1
                continue
            for p in ("baseline", "pessimistic"):
                tr = simulate(cb, cf, r["t0"], r["T"], c["spec"], PROFILES[p])
                ctrl[p][r["key"]].append({**tr, "T": r["T"], "inst": c["inst"]})
    keys = [r["key"] for r in rows]
    result: dict = {"label": LABEL, "written_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "events": len(rows),
                    "control_legs_skipped": dict(ctrl_skips), "profiles": {}}
    portfolios = {}
    for p in PROFILES:
        done, skips = portfolio(trades[p])
        portfolios[p] = done
        result["profiles"][p] = {"stats": stats(done), "skips": dict(skips)}
    rng = random.Random(SEED)
    verdict_parts = {}
    for p in ("baseline", "pessimistic"):
        s = result["profiles"][p]["stats"]
        c = control_strategy(keys, ctrl[p], s.get("mean_R", 0.0), rng)
        result["profiles"][p]["control_strategy"] = c
        crit = criteria(s, result["profiles"][f"{p}_stress"]["stats"], c, p)
        result["profiles"][p]["criteria"] = crit
        verdict_parts[p] = crit
    base_ok = all(v for k, v in verdict_parts["baseline"].items() if k != "2_mean_net_R_gt_0_under_stress")
    all_ok = all(all(v.values()) for v in verdict_parts.values())
    result["verdict"] = "GATE2_PASS" if all_ok else ("INSUFFICIENT_EXECUTION_DATA" if base_ok else "GATE2_FAIL")
    # ---- A3 diagnostics (never decisive)
    diag: dict = {}
    for name in ("S_A", "S_B"):  # D1
        diag[f"D1_{name}"] = {p: stats(portfolio([t for t in trades[p] if t["set"] == name])[0]) for p in ("baseline", "pessimistic")}
    d2 = {}
    for p in ("baseline", "pessimistic"):  # D2
        swapped = [{**t, "net": t["net"] - t["funding"] + t["xperp_funding"], "funding": t["xperp_funding"]} if "skip" not in t else t for t in portfolios[p]]
        for t in swapped:
            t["pnl"] = t["notional"] * t["net"]
            t["R"] = t["pnl"] / R_UNIT
        d2[p] = {**stats(swapped), "total_funding_xperp_R": sum(t["notional"] * t["funding"] / R_UNIT for t in swapped)}
    diag["D2_xperp_funding"] = d2
    base = portfolios["baseline"]  # D3
    if base:
        def mean_r(c8: float) -> float:
            return statistics.fmean((t["pnl"] - t["notional"] * c8 * t["hours"] / 8) / R_UNIT for t in base)
        lo, hi = 0.0, 0.02
        if mean_r(lo) <= 0:
            diag["D3_break_even_funding_per_8h"] = 0.0
        elif mean_r(hi) > 0:
            diag["D3_break_even_funding_per_8h"] = f"> {hi}"
        else:
            for _ in range(60):
                mid = (lo + hi) / 2
                lo, hi = (mid, hi) if mean_r(mid) > 0 else (lo, mid)
            diag["D3_break_even_funding_per_8h"] = lo
    diag["D4_delisting_unfavourable_exit"] = stats(portfolio(sens)[0])
    diag["D5_execution"] = {
        "stop_distance_quantiles": np.percentile([t["stop_distance"] for t in base], [10, 50, 90]).tolist() if base else None,
        "share_at_60pct_cap": sum(t["stop_distance"] >= cv.STOP_CAP for t in base) / len(base) if base else None,
        "notional_quantiles": np.percentile([t["notional"] for t in base], [10, 50, 90]).tolist() if base else None,
        "skips_baseline": result["profiles"]["baseline"]["skips"],
    }
    result["diagnostics_A3"] = diag
    result["trades_baseline"] = [{k: t.get(k) for k in ("key", "set", "T", "exit", "stop_distance", "notional", "gross", "funding", "net", "R")} for t in base]
    out.write_text(json.dumps(result, indent=1, default=lambda v: None))
    return {"verdict": result["verdict"], "baseline": result["profiles"]["baseline"]["criteria"], "pessimistic": result["profiles"]["pessimistic"]["criteria"]}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_listing.gate2")
    parser.add_argument("command", choices=("funding-plan", "funding-okx", "coverage", "run"))
    parser.add_argument("--campaign", required=True)
    parser.add_argument("--okx-tape", required=True)
    parser.add_argument("--gate2", required=True)
    args = parser.parse_args(argv)
    campaign, okx_tape, gate2 = Path(args.campaign), Path(args.okx_tape), Path(args.gate2)
    rows = load_inputs(campaign, okx_tape, gate2)
    if args.command == "funding-plan":
        print(json.dumps(funding_plan(rows, okx_tape, gate2 / "funding-plan.json")))
    elif args.command == "funding-okx":
        print(json.dumps(funding_okx(gate2 / "funding-plan.json", gate2 / "funding-okx")))
    elif args.command == "coverage":
        doc = coverage(rows)
        (gate2 / "coverage.json").write_text(json.dumps(doc, indent=1))
        print(json.dumps({k: (len(v) if isinstance(v, list) else v) for k, v in doc.items()}))
    else:
        print(json.dumps(run(rows, gate2 / "gate2.json"), indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
