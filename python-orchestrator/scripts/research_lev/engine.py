"""Decision-time inputs and the E1 replay of P2-B4 with an ex ante band admission filter
(PREREGISTRATION-leverage-gt2.md §4, §6).

`decision_inputs` reads only the bar and feature values of the decision second (order price,
tick, ATR5, spread proxy, quote age, `stale_900`): it is shared by the E0a census and the E1
admission filter, so both classify a signal identically.

`replay_streams` is the order loop of `research_exec.scalp_engine.run_policy` (entry E1),
extended with (a) several streams of one pair merged under one position per pair (a pair is busy
while either side has an order or a position; two sides admitted in the same second are a
`conflict`: no order, nothing blocked), and (b) an admission callback placed after the order
price and before the order is sent: a rejected signal does not touch `busy_until`. With one
stream, no admission and the frozen `_exit`, it reproduces `run_policy` byte for byte (tested);
`scalp_engine` itself is not modified.

The B4 exit keeps the frozen trigger detection (a print at or beyond the stop, stop before the
2 h / 23:59 UTC deadline) and replaces the execution price of the stop by the logic of
`q3r2_engine.stop_exit`: market `latency` after the trigger print at the worst of the trigger
print, the prints within the latency and the live touch (mirrored for shorts).
"""

from __future__ import annotations

from typing import Callable

import numpy as np

from ..research_exec.scalp_engine import (
    EXIT_REASONS,
    SETUP_DAY_CLOSE_S,
    SETUP_EXITS,
    SETUP_MAX_HOLD_S,
    TRADE_DTYPE,
    Counters,
    Policy,
    SeriesData,
    _exit,
    _touch,
)
from ..research_exec.tick import SECOND_US
from .leverage import (
    BANDS,
    INDETERMINATE_REASONS,
    MAKER_ENTRY_BP,
    TAKER_STOP_BP,
    band_of,
    c1_distance_bp,
    cost_bp,
    distance_bp,
    floor_binding,
    l_eng,
    l_stop,
    stop_distance,
    stop_trigger,
)

OK = 0
REASON_CODE = {reason: k + 1 for k, reason in enumerate(INDETERMINATE_REASONS)}
STATUS = {"crossed": 1, "unfilled": 2, "filled": 3, "no_exit": 4}
SETTLEMENT_US = 8 * 3600 * SECOND_US  # Binance USD-M settlements at 00, 08, 16 UTC
COUNTER_KEYS = ("signals", "busy", "atr_missing", "quote_stale", "no_quote", "spread_missing", "nonpositive_distance",
                "excluded_band", "conflict", "decisions", "orders", "crossed", "unfilled", "filled", "no_exit")

EXPORT_DTYPE = np.dtype([
    ("config", "<i2"), ("arm", "<i1"), ("series", "<i2"), ("side", "<i1"), ("status", "<i1"),
    ("decision_s", "<i8"), ("fill_ts", "<i8"), ("entry", "<f8"), ("exit_ts", "<i8"), ("exit", "<f8"), ("frozen_exit", "<f8"), ("exit_reason", "<i1"),
    ("gross_bp", "<f8"), ("fees_bp", "<f8"), ("net_bp", "<f8"), ("hold_s", "<f8"),
    ("d_bp", "<f8"), ("c_bp", "<f8"), ("spread_bp", "<f8"), ("tick_bp", "<f8"), ("l_eng", "<f8"), ("l_stop", "<f8"), ("floor", "<i1"),
    ("atr5_bp", "<f8"), ("stale_900", "<f8"), ("trigger", "<f8"), ("stop_slip_bp", "<f8"), ("same_second_stop", "<i1"), ("worst", "<f8"), ("settlements", "<i1"),
    ("c1_d_bp", "<f8"), ("c1_exit", "<f8"), ("c1_reason", "<i1"), ("c1_net_bp", "<f8"), ("c1_hold_s", "<f8"),
])


# ----------------------------------------------------------------------------- decision-time inputs


def decision_inputs(data: SeriesData, side: int, seconds: np.ndarray, stale_s: float) -> dict[str, np.ndarray]:
    """Per decision second (bar index): status (0 = determinable, else a `REASON_CODE`), E1 order
    price (`run_policy`: the touch, rounded to the grid), tick, stop distance and its effective
    trigger, d, c, L_eng, L_stop, floor flag and the C2 stratum inputs. Only index `s` of the bars
    and features is read."""
    s = np.asarray(seconds, dtype=np.int64)
    bars, features = data.bars, data.features
    m = s.size
    out = {name: np.full(m, np.nan) for name in ("price", "tick", "distance", "trigger", "d_bp", "c_bp", "spread_bp", "tick_bp", "l_eng", "l_stop", "atr5_bp", "stale_900")}
    out["status"] = np.zeros(m, dtype=np.int8)
    out["floor"] = np.zeros(m, dtype=np.int8)
    if m == 0:
        return out
    atr5 = features["atr5"][s] if "atr5" in features else np.full(m, np.nan)
    mid, age = bars["mid"][s], bars["quote_age"][s]
    bid, ask = bars["bid"][s], bars["ask"][s]
    tick = data.ticks[s]
    spread = features["spread_bp"][s]
    status = out["status"]
    status[~np.isfinite(atr5)] = REASON_CODE["atr_missing"]
    with np.errstate(invalid="ignore"):
        stale = ~np.isfinite(mid) | (age > stale_s)
    status[(status == OK) & stale] = REASON_CODE["quote_stale"]
    status[(status == OK) & ~np.isfinite(spread)] = REASON_CODE["spread_missing"]
    ok = status == OK
    raw = np.where(side > 0, bid, ask)
    price = np.where(ok, np.round(np.where(ok, raw, 1.0) / tick) * tick, np.nan)
    distance = np.where(ok, stop_distance(np.where(ok, atr5, 0.0), tick), np.nan)
    trigger = np.where(ok, stop_trigger(np.where(ok, price, 1.0), side, np.where(ok, distance, 0.0), tick), np.nan)
    with np.errstate(invalid="ignore", divide="ignore"):
        d_bp = distance_bp(price, trigger, side)
        tick_bp = tick / price * 1e4
    status[ok & ~(d_bp > 0)] = REASON_CODE["nonpositive_distance"]
    ok = status == OK
    c_bp = cost_bp(spread, tick_bp)
    out.update({
        "price": price, "tick": np.asarray(tick, dtype=np.float64), "distance": distance, "trigger": trigger,
        "d_bp": np.where(ok, d_bp, np.nan), "c_bp": np.where(ok, c_bp, np.nan), "spread_bp": np.where(ok, spread, np.nan), "tick_bp": np.where(ok, tick_bp, np.nan),
        "l_eng": np.where(ok, l_eng(np.where(ok, d_bp, 1.0), np.where(ok, c_bp, 1.0)), np.nan),
        "l_stop": np.where(ok, l_stop(np.where(ok, d_bp, 1.0)), np.nan),
        "floor": np.where(ok, floor_binding(np.where(ok, atr5, 0.0), tick), False).astype(np.int8),
        "atr5_bp": np.where(ok, atr5 / price * 1e4, np.nan),
        "stale_900": np.where(ok, features["stale_900"][s], np.nan) if "stale_900" in features else np.full(m, np.nan),
    })
    return out


# ----------------------------------------------------------------------------- exits


def _frozen_exit(data: SeriesData, policy: Policy, side: int, s: int, entry: float, jf: int, fill_ts: int, tick: float, tol: float) -> dict:
    exit_ts, exit_price, reason, exit_fee = _exit(data, policy, side, s, entry, jf, fill_ts, tick, tol)
    return {"exit_ts": exit_ts, "exit_price": exit_price, "reason": reason, "exit_fee": exit_fee}


def b4_exit(data: SeriesData, policy: Policy, side: int, entry: float, jf: int, fill_ts: int, tick: float, tol: float, distance: float) -> dict:
    """B4 with the frozen trigger detection and the realistic stop execution. Also returns the
    frozen execution price (the touch), the worst price from the fill print to the exit moment,
    and whether the stop print falls in the fill's second."""
    tts, tpx = data.tts, data.tpx
    stop = entry - side * distance
    day_close = (fill_ts // (86400 * SECOND_US)) * 86400 * SECOND_US + SETUP_DAY_CLOSE_S * SECOND_US
    deadline = min(fill_ts + SETUP_MAX_HOLD_S * SECOND_US, max(day_close, fill_ts))
    k0 = jf + 1
    k1 = int(np.searchsorted(tts, deadline, side="right"))
    window = tpx[k0:k1]
    stop_hit = (window <= stop + tol) if side > 0 else (window >= stop - tol)
    same_second = 0
    if stop_hit.any():
        k = k0 + int(np.argmax(stop_hit))
        moment = int(tts[k]) + policy.latency_us
        touch = _touch(data, moment, want_bid=side > 0)
        k_end = int(np.searchsorted(tts, moment, side="right"))
        reason = EXIT_REASONS["stop"]
        same_second = int(tts[k] // SECOND_US == fill_ts // SECOND_US)
        if touch is None:
            return {"exit_ts": moment, "exit_price": None, "reason": reason, "exit_fee": policy.taker_bp}
        prints = tpx[k:k_end]
        price = min(float(prints.min()), touch) if side > 0 else max(float(prints.max()), touch)
    else:
        moment = deadline + policy.latency_us
        touch = _touch(data, moment, want_bid=side > 0)
        k_end = int(np.searchsorted(tts, moment, side="right"))
        reason = EXIT_REASONS["time"]
        if touch is None:
            return {"exit_ts": moment, "exit_price": None, "reason": reason, "exit_fee": policy.taker_bp}
        price = touch
    path = tpx[jf:k_end]
    worst = (min(float(path.min()), price) if side > 0 else max(float(path.max()), price)) if path.size else price
    return {"exit_ts": moment, "exit_price": price, "reason": reason, "exit_fee": policy.taker_bp, "frozen_exit": touch, "worst": worst, "same_second": same_second}


def settlements_crossed(fill_ts: int, exit_ts: int) -> int:
    """Funding settlements T (00, 08, 16 UTC) with fill < T ≤ exit."""
    return int(exit_ts // SETTLEMENT_US - fill_ts // SETTLEMENT_US)


# ----------------------------------------------------------------------------- order loop


def replay_streams(data: SeriesData, streams: dict[int, np.ndarray], policy: Policy, admit: Callable[[int, int, float, float], str | None] | None, exit_fn: Callable[..., dict]) -> tuple[list[tuple], dict[str, int]]:
    """Orders of one pair: `streams` maps a side (+1 / −1) to its sorted decision seconds.
    Returns one record per order sent (status, side, s, price, tick, tol, jf, fill_ts, exit dict)
    and the counters."""
    if policy.entry != "E1":
        raise ValueError("the leverage study replays entry E1 only")
    counters = dict.fromkeys(COUNTER_KEYS, 0)
    sides = [side for side in (1, -1) if side in streams]
    arrays = {side: np.asarray(streams[side], dtype=np.int64) for side in sides}
    for side in sides:
        counters["signals"] += int(arrays[side].size)
    union = np.unique(np.concatenate([arrays[side] for side in sides])) if sides else np.zeros(0, dtype=np.int64)
    member = {side: np.isin(union, arrays[side]) for side in sides}
    bars = data.bars
    mids, ages, bids, asks, ticks = bars["mid"], bars["quote_age"], bars["bid"], bars["ask"], data.ticks
    atr5 = data.features.get("atr5") if policy.exit in SETUP_EXITS else None
    quote_at_activation, first_after_activation, bound_at_ttl = data.indices(policy.latency_us, policy.ttl_us)
    ttl_s = policy.ttl_us // SECOND_US
    busy_until = -1
    records: list[tuple] = []
    for position, s in enumerate(union.tolist()):
        here = [side for side in sides if member[side][position]]
        if s < busy_until or s >= data.n:
            counters["busy"] += len(here)
            continue
        candidates = []
        for side in here:
            if atr5 is not None and not atr5[s] == atr5[s]:
                counters["atr_missing"] += 1
                continue
            counters["decisions"] += 1
            mid = mids[s]
            if not mid == mid or ages[s] > policy.stale_s:
                counters["quote_stale"] += 1
                continue
            b, a = float(bids[s]), float(asks[s])
            tick = float(ticks[s])
            tol = tick * 1e-6
            price = b if side > 0 else a
            price = round(price / tick) * tick
            iq = int(quote_at_activation[s])
            if iq < 0:
                counters["no_quote"] += 1
                continue
            if admit is not None:
                verdict = admit(side, s, price, tick)
                if verdict is not None:
                    counters[verdict] += 1
                    continue
            candidates.append((side, price, tick, tol, iq))
        if not candidates:
            continue
        if len(candidates) > 1:
            counters["conflict"] += 1
            continue
        side, price, tick, tol, iq = candidates[0]
        counters["orders"] += 1
        if (side > 0 and price >= data.ask[iq] - tol) or (side < 0 and price <= data.bid[iq] + tol):
            counters["crossed"] += 1
            busy_until = s + 1 + policy.cancel_block_s
            records.append((STATUS["crossed"], side, s, price, tick, tol, -1, -1, None))
            continue
        j0 = int(first_after_activation[s])
        j1 = int(bound_at_ttl[s])
        window = data.tpx[j0:j1]
        hit = (window < price - tol) if side > 0 else (window > price + tol)
        if window.size == 0 or not hit.any():
            counters["unfilled"] += 1
            busy_until = s + 1 + ttl_s
            records.append((STATUS["unfilled"], side, s, price, tick, tol, -1, -1, None))
            continue
        jf = j0 + int(np.argmax(hit))
        fill_ts = int(data.tts[jf])
        counters["filled"] += 1
        result = exit_fn(data, policy, side, s, price, jf, fill_ts, tick, tol)
        if result["exit_price"] is None:
            counters["no_exit"] += 1
            busy_until = data.n
            records.append((STATUS["no_exit"], side, s, price, tick, tol, jf, fill_ts, result))
            continue
        records.append((STATUS["filled"], side, s, price, tick, tol, jf, fill_ts, result))
        busy_until = int((result["exit_ts"] - data.start_s * SECOND_US) // SECOND_US) + 1
    return records, counters


def replay_frozen(data: SeriesData, side: int, decisions: np.ndarray, policy: Policy) -> tuple[np.ndarray, Counters]:
    """The loop above with one stream, no admission and the frozen exit, in `run_policy`'s output
    format: the hook-off identity test compares the two byte for byte."""
    records, counts = replay_streams(data, {side: decisions}, policy, None, _frozen_exit)
    rows = []
    for status, sd, s, price, _tick, _tol, _jf, fill_ts, result in records:
        if status != STATUS["filled"]:
            continue
        exit_ts, exit_price, reason, exit_fee = result["exit_ts"], result["exit_price"], result["reason"], result["exit_fee"]
        gross = sd * (exit_price - price) / price * 1e4
        fees = policy.maker_bp + exit_fee
        rows.append((data.series_id, sd, data.start_s + s, fill_ts, price, exit_ts, exit_price, reason, gross, fees, gross - fees, (exit_ts - fill_ts) / 1e6))
    trades = np.array(rows, dtype=TRADE_DTYPE) if rows else np.zeros(0, dtype=TRADE_DTYPE)
    counters = Counters(decisions=counts["decisions"], orders=counts["orders"], crossed=counts["crossed"], unfilled=counts["unfilled"], filled=counts["filled"])
    return trades, counters


# ----------------------------------------------------------------------------- one arm


def band_admission(inputs: dict[int, dict[str, np.ndarray]], positions: dict[int, dict[int, int]], definition: str, band: str) -> Callable[[int, int, float, float], str | None]:
    """Admission callback of one arm: the signal's leverage under `definition`, from its
    decision-time inputs, must fall in `band`; indeterminable signals are refused with their
    reason. The order price of the loop must equal the census price (checked)."""
    key = "l_eng" if definition == "eng" else "l_stop"
    if band not in {name for name, _, _ in BANDS}:
        raise ValueError(f"unknown band {band}")

    def admit(side: int, s: int, price: float, tick: float) -> str | None:
        table = inputs[side]
        i = positions[side][s]
        status = int(table["status"][i])
        if status != OK:
            return INDETERMINATE_REASONS[status - 1]
        if abs(table["price"][i] - price) > tick * 1e-6:
            raise AssertionError(f"admission price {price} differs from the decision-time price {table['price'][i]}")
        return None if band_of(float(table[key][i])) == band else "excluded_band"

    return admit


def replay_arm(data: SeriesData, streams: dict[int, np.ndarray], policy: Policy, definition: str, band: str, config: int = 0, arm: int = 0) -> tuple[np.ndarray, dict[str, int]]:
    """E1 replay of one configuration on one pair for one arm (definition, band): admitted orders
    with their decision-time d, c, L and strata, the realistic B4 exit, the frozen-touch exit for
    the diagnostics, the worst price, settlements crossed and the C1 counterfactual (stop widened
    to L_eng = 1.5, evaluated on the same fill, independent of any pair blocking)."""
    if policy.exit != "B4":
        raise ValueError("P2-B4 only")
    inputs = {side: decision_inputs(data, side, seconds, policy.stale_s) for side, seconds in streams.items()}
    positions = {side: {int(s): i for i, s in enumerate(np.asarray(seconds).tolist())} for side, seconds in streams.items()}

    def exit_fn(d: SeriesData, p: Policy, side: int, s: int, entry: float, jf: int, fill_ts: int, tick: float, tol: float) -> dict:
        i = positions[side][s]
        return b4_exit(d, p, side, entry, jf, fill_ts, tick, tol, float(inputs[side]["distance"][i]))

    records, counters = replay_streams(data, streams, policy, band_admission(inputs, positions, definition, band), exit_fn)
    rows = []
    nan = float("nan")
    for status, side, s, price, tick, tol, jf, fill_ts, result in records:
        i = positions[side][s]
        x = inputs[side]
        base = dict(config=config, arm=arm, series=data.series_id, side=side, status=status, decision_s=data.start_s + s, entry=price,
                    d_bp=x["d_bp"][i], c_bp=x["c_bp"][i], spread_bp=x["spread_bp"][i], tick_bp=x["tick_bp"][i], l_eng=x["l_eng"][i], l_stop=x["l_stop"][i],
                    floor=x["floor"][i], atr5_bp=x["atr5_bp"][i], stale_900=x["stale_900"][i], trigger=x["trigger"][i])
        row = dict.fromkeys(EXPORT_DTYPE.names, nan)
        row.update({"fill_ts": -1, "exit_ts": -1, "exit_reason": -1, "same_second_stop": 0, "settlements": 0, "c1_reason": -1})
        row.update(base)
        if status == STATUS["filled"]:
            exit_price = result["exit_price"]
            gross = side * (exit_price - price) / price * 1e4
            fees = MAKER_ENTRY_BP + result["exit_fee"]
            row.update({"fill_ts": fill_ts, "exit_ts": result["exit_ts"], "exit": exit_price, "frozen_exit": result["frozen_exit"], "exit_reason": result["reason"],
                        "gross_bp": gross, "fees_bp": fees, "net_bp": gross - fees, "hold_s": (result["exit_ts"] - fill_ts) / 1e6,
                        "worst": result["worst"], "same_second_stop": result["same_second"], "settlements": settlements_crossed(fill_ts, result["exit_ts"])})
            if result["reason"] == EXIT_REASONS["stop"]:
                row["stop_slip_bp"] = side * (x["trigger"][i] - exit_price) / price * 1e4
            d1 = c1_distance_bp(x["c_bp"][i])
            if np.isfinite(d1):
                distance1 = d1 / 1e4 * price
                trigger1 = stop_trigger(price, side, distance1, tick)
                c1 = b4_exit(data, policy, side, price, jf, fill_ts, tick, tol, distance1)
                row["c1_d_bp"] = float(distance_bp(price, trigger1, side))
                if c1["exit_price"] is not None:
                    gross1 = side * (c1["exit_price"] - price) / price * 1e4
                    row.update({"c1_exit": c1["exit_price"], "c1_reason": c1["reason"], "c1_net_bp": gross1 - (MAKER_ENTRY_BP + c1["exit_fee"]), "c1_hold_s": (c1["exit_ts"] - fill_ts) / 1e6})
        elif status == STATUS["no_exit"]:
            row.update({"fill_ts": fill_ts})
        rows.append(tuple(row[name] for name in EXPORT_DTYPE.names))
    export = np.array(rows, dtype=EXPORT_DTYPE) if rows else np.zeros(0, dtype=EXPORT_DTYPE)
    return export, counters


__all__ = ["COUNTER_KEYS", "EXPORT_DTYPE", "OK", "REASON_CODE", "STATUS", "TAKER_STOP_BP", "b4_exit", "band_admission", "decision_inputs", "replay_arm", "replay_frozen", "replay_streams", "settlements_crossed"]
