"""Event detectors of the `lp` programme, on the tape layouts (no market data is read here; the
inputs are arrays the caller loaded).

- **Liquidation clusters** (G4): the liquidated notional per aligned 5-minute bin must reach an
  absolute threshold **and** a trailing percentile of the instrument's own bins (the percentile
  alone yields ~3 bins/day/pair without content; `research-scalping-theory-gap-2026-10-20.md`
  §3.3 G4). The percentile is computed on the bins strictly before the candidate (no look-ahead)
  over a bounded look-back, after a minimum history. The event side is the provider's: forced
  sells (long liquidations) dominate → +1 (buy), forced buys → −1.
- **Conditional excursions** (G1): 1-minute closes, EMA over 15 minutes, σ15 = realised 15-minute
  volatility of the 1-minute log returns strictly before the minute judged (lagged one minute, so
  the excursion's own return never inflates its yardstick); an excursion is |ln(p / EMA)| ≥ k·σ15,
  one event per cool-down; the stress indicator is the (lagged) realised 24 h volatility above
  the median of its daily values over the trailing 30 days (−1 = unknown before 30 days of
  history). Side = provide against the excursion (price above the EMA → −1). z is scale-free: on a
  pure Gaussian random walk k = 1.5 still fires on ≈ 0.1 % of minutes (synthetic test) — the null
  rate the control must carry; real returns have fatter tails.
- **Clock windows** (G5): around the funding settlements and the US cash open (13:30 UTC under
  US daylight time, 14:30 UTC otherwise, weekdays), armed at h − 5 (or h − 2), alive until h + 1;
  side 0 (both sides, signs pre-declared elsewhere). Settlement instants come from the tape's
  `funding` rows of the instrument when available (`funding_times_from_tape`: some X-Perps settle
  every 4 h and the interval shortens under stress, `H1-XPERP-MARKET-ACCESS.md` §2.8), else from
  the fixed `funding_hours` (00 / 08 / 16 UTC).

**Timing fields.** `ts_us` is the event instant; `arm_ts_us` is the *earliest instant at which the
event is actionable*: for a detected event (liquidation cluster, excursion) it equals `ts_us` —
the event is known only once it happened, so the simulator decides at the next minute boundary
(`lp_sim.event_intents`, `next_minute`); for a *scheduled* clock window it is `h − arm_min`,
strictly before `ts_us`, and the simulator decides there and rests until `end_ts_us` (never
at h − 1, the toxic minute: theory report fiche G5, architecture §2.3). `end_ts_us` bounds the
window of interest (bin end, cool-down, h + end_min); it is an order lifetime only for scheduled
windows.

**Sums.** The detectors aggregate with numpy (`np.add.at`, `np.cumsum`, `np.percentile`): float64
sequential / pairwise summation, not `math.fsum`. These are thresholds and yardsticks, not a
PnL, so the ~1e-16 relative difference is immaterial; the notional reported in `value` for an
emitted liquidation event is re-summed with `math.fsum` (exact). `lp_sim` keeps `fsum` for PnL.

**Liquidation side.** `side` of a liquidation row is +1 (forced buy), −1 (forced sell) or 0
(unknown); an unknown side counts in the bin's total notional but not in its signed notional,
so it never orients the event.

Every detector returns rows of `EVENT_DTYPE` plus a diagnostics dict; nothing is fitted.
"""

from __future__ import annotations

import math
from datetime import date as _date
from datetime import datetime, timedelta, timezone

import numpy as np

from . import tape

EVENT_DTYPE = np.dtype([("ts_us", "<i8"), ("kind", "<i1"), ("side", "<i1"), ("value", "<f8"), ("aux", "<f8"), ("stress", "<i1"), ("arm_ts_us", "<i8"), ("end_ts_us", "<i8")])
KINDS = {"liquidation_cluster": 1, "excursion": 2, "clock_funding": 3, "clock_us_open": 4}
KIND_NAME = {v: k for k, v in KINDS.items()}
MINUTE_US = 60 * tape.SECOND_US


def empty_events() -> np.ndarray:
    return np.zeros(0, dtype=EVENT_DTYPE)


def _rows(items: list[tuple]) -> np.ndarray:
    return np.array(items, dtype=EVENT_DTYPE) if items else empty_events()


# ----------------------------------------------------------------------------- minute series


def minute_series(ts_us: np.ndarray, values: np.ndarray, start_us: int | None = None, end_us: int | None = None) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """Last value of each minute on a dense minute grid (`minute_ts_us` = minute end), forward-filled;
    `observed` marks minutes with at least one value. Minutes before the first value are dropped."""
    ts_us = np.asarray(ts_us, dtype=np.int64)
    values = np.asarray(values, dtype=np.float64)
    keep = np.isfinite(values)
    ts_us, values = ts_us[keep], values[keep]
    if ts_us.size == 0:
        return np.zeros(0, dtype=np.int64), np.zeros(0), np.zeros(0, dtype=bool)
    order = np.argsort(ts_us, kind="stable")
    ts_us, values = ts_us[order], values[order]
    first = (int(ts_us[0]) // MINUTE_US + 1) * MINUTE_US if start_us is None else (int(start_us) // MINUTE_US + 1) * MINUTE_US
    last = (int(ts_us[-1]) // MINUTE_US + 1) * MINUTE_US if end_us is None else (int(end_us) // MINUTE_US + 1) * MINUTE_US
    grid = np.arange(first, last + 1, MINUTE_US, dtype=np.int64)
    idx = np.searchsorted(ts_us, grid, side="right") - 1
    valid = idx >= 0
    closes = np.where(valid, values[np.where(valid, idx, 0)], np.nan)
    observed = np.zeros(grid.size, dtype=bool)
    observed[1:] = idx[1:] > idx[:-1]
    observed[0] = valid[0]
    return grid, closes, observed


def mid_from_book_rows(rows: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    mid = (rows["bid_px"][:, 0] + rows["ask_px"][:, 0]) / 2.0
    return rows["ts_us"].astype(np.int64), mid


# ----------------------------------------------------------------------------- liquidation clusters


def liquidation_notionals(liq_rows: np.ndarray, ct_val: float, mark_ts_us: np.ndarray | None = None, mark_px: np.ndarray | None = None) -> tuple[np.ndarray, np.ndarray, np.ndarray, int]:
    """`(ts_us, signed_notional, notional)` per liquidation order: size × ctVal × price with the
    bankruptcy price when present, else the last mark price at or before the order; orders
    without any price are dropped (count returned). Sign: +1 for a forced buy, −1 for a forced
    sell, 0 for an unknown side (kept in `notional`, excluded from `signed_notional`)."""
    ts = liq_rows["ts_us"].astype(np.int64)
    price = liq_rows["bk_px"].astype(np.float64).copy()
    missing = ~np.isfinite(price) | (price <= 0)
    if missing.any() and mark_ts_us is not None and mark_px is not None and len(mark_ts_us):
        idx = np.searchsorted(np.asarray(mark_ts_us, dtype=np.int64), ts[missing], side="right") - 1
        fill = np.where(idx >= 0, np.asarray(mark_px, dtype=np.float64)[np.where(idx >= 0, idx, 0)], np.nan)
        price[missing] = fill
    usable = np.isfinite(price) & (price > 0) & np.isfinite(liq_rows["size"]) & (liq_rows["size"] > 0)
    notional = liq_rows["size"].astype(np.float64) * ct_val * price
    side = liq_rows["side"].astype(np.int64)
    signed = notional * np.where(side > 0, 1.0, np.where(side < 0, -1.0, 0.0))
    return ts[usable], signed[usable], notional[usable], int((~usable).sum())


def liquidation_clusters(liq_rows: np.ndarray, ct_val: float, abs_notional: float, percentile: float = 99.0, bin_s: int = 300, lookback_bins: int = 2016, min_history_bins: int = 288,
                         mark_ts_us: np.ndarray | None = None, mark_px: np.ndarray | None = None) -> tuple[np.ndarray, dict]:
    """Bins whose liquidated notional ≥ `abs_notional` and ≥ the `percentile` of the previous
    `lookback_bins` bins (zero bins included), after `min_history_bins` of history. Event instant =
    end of the bin = `arm_ts_us` (the cluster is known only when the bin closes: no earlier
    decision is possible); `end_ts_us` = the same instant; `value` = notional (fsum), `aux` =
    share of history bins strictly below it."""
    ts, signed, notional, dropped = liquidation_notionals(liq_rows, ct_val, mark_ts_us, mark_px)
    diag = {"orders": int(liq_rows.size), "orders_without_price": dropped, "bins_with_liquidation": 0, "bins_total": 0, "events": 0, "abs_notional": abs_notional, "percentile": percentile}
    if ts.size == 0:
        return empty_events(), diag
    bin_us = bin_s * tape.SECOND_US
    first_bin = int(ts.min()) // bin_us
    last_bin = int(ts.max()) // bin_us
    n_bins = last_bin - first_bin + 1
    total = np.zeros(n_bins)
    signed_total = np.zeros(n_bins)
    which = (ts // bin_us - first_bin).astype(np.int64)
    np.add.at(total, which, notional)
    np.add.at(signed_total, which, signed)
    counts = np.bincount(which, minlength=n_bins)
    diag["bins_total"] = int(n_bins)
    diag["bins_with_liquidation"] = int((total > 0).sum())
    items = []
    for b in np.flatnonzero(total > 0):
        if b < min_history_bins:
            continue
        lo = max(0, b - lookback_bins)
        history = total[lo:b]
        threshold = float(np.percentile(history, percentile)) if history.size else math.inf
        if total[b] < abs_notional or total[b] < threshold:
            continue
        rank = float(np.mean(history < total[b])) if history.size else math.nan
        side = -1 if signed_total[b] > 0 else 1  # forced buys dominate → provide on the sell side (an unknown side weighs nothing here)
        end_us = int((first_bin + b + 1) * bin_us)
        exact = math.fsum(notional[which == b].tolist())  # the reported notional is exact; the screen above used numpy's sum
        items.append((end_us, KINDS["liquidation_cluster"], side, exact, rank, -1, end_us, end_us))
    diag["events"] = len(items)
    diag["orders_in_event_bins"] = int(sum(int(counts[(int(item[0]) // bin_us) - first_bin - 1]) for item in items)) if items else 0
    return _rows(items), diag


# ----------------------------------------------------------------------------- conditional excursions


def _ema(values: np.ndarray, span: int) -> np.ndarray:
    alpha = 2.0 / (span + 1.0)
    out = np.empty_like(values)
    prev = math.nan
    for i, v in enumerate(values):
        if not math.isfinite(v):
            out[i] = prev
            continue
        prev = v if not math.isfinite(prev) else alpha * v + (1.0 - alpha) * prev
        out[i] = prev
    return out


def _rolling_rss(values: np.ndarray, window: int) -> np.ndarray:
    """sqrt of the rolling sum of squares over `window` points (NaN until the window is full; NaNs count as 0)."""
    sq = np.where(np.isfinite(values), values, 0.0) ** 2
    cum = np.concatenate([[0.0], np.cumsum(sq)])
    out = np.full(values.size, np.nan)
    if values.size >= window:
        out[window - 1:] = np.sqrt(np.maximum(cum[window:] - cum[:-window], 0.0))
    return out


def _lag(values: np.ndarray, by: int = 1) -> np.ndarray:
    out = np.full(values.size, np.nan)
    if values.size > by:
        out[by:] = values[:-by]
    return out


def excursions(minute_ts_us: np.ndarray, closes: np.ndarray, k: float = 1.5, ema_min: int = 15, sigma_min: int = 15, rv_minutes: int = 1440, median_days: int = 30, min_days: int = 30,
               cooldown_min: int = 15) -> tuple[np.ndarray, dict]:
    """Excursion events on a dense minute series; `value` = z = ln(p/EMA)/σ15, `aux` = rv24 / median30 (NaN when unknown)."""
    ts = np.asarray(minute_ts_us, dtype=np.int64)
    px = np.asarray(closes, dtype=np.float64)
    diag = {"minutes": int(ts.size), "events": 0, "stress_known_share": None, "k": k}
    if ts.size < max(sigma_min, ema_min) + 2:
        return empty_events(), diag
    logp = np.log(px)
    returns = np.full(ts.size, np.nan)
    returns[1:] = logp[1:] - logp[:-1]
    ema = _ema(px, ema_min)
    # Volatilities are lagged one minute: measured on the returns strictly before the minute judged (the
    # excursion's own return must not inflate the σ it is compared with).
    sigma15 = _lag(_rolling_rss(returns, sigma_min))
    rv24 = _lag(_rolling_rss(returns, rv_minutes))
    with np.errstate(divide="ignore", invalid="ignore"):
        z = np.where(sigma15 > 0, (logp - np.log(ema)) / sigma15, np.nan)
    # Daily samples of rv24 at the day boundaries (minute ending exactly at 00:00 UTC).
    day_end = (ts % (tape.DAY_S * tape.SECOND_US)) == 0
    sample_ts = ts[day_end & np.isfinite(rv24)]
    sample_rv = rv24[day_end & np.isfinite(rv24)]
    items = []
    last_event = -math.inf
    known = 0
    considered = 0
    cooldown_us = cooldown_min * MINUTE_US
    for i in np.flatnonzero(np.isfinite(z) & (np.abs(z) >= k)):
        t = int(ts[i])
        if t - last_event < cooldown_us:
            continue
        considered += 1
        last_event = t
        n_hist = int(np.searchsorted(sample_ts, t, side="left"))
        lo = max(0, n_hist - median_days)
        history = sample_rv[lo:n_hist]
        if history.size >= min_days and math.isfinite(rv24[i]):
            median = float(np.median(history))
            stress = 1 if rv24[i] > median else 0
            ratio = float(rv24[i] / median) if median > 0 else math.nan
            known += 1
        else:
            stress, ratio = -1, math.nan
        side = -1 if z[i] > 0 else 1
        items.append((t, KINDS["excursion"], side, float(z[i]), ratio, stress, t, t + cooldown_us))
    diag["events"] = len(items)
    diag["stress_known_share"] = (known / considered) if considered else None
    return _rows(items), diag


# ----------------------------------------------------------------------------- clock windows


def _nth_sunday(year: int, month: int, n: int) -> _date:
    first = _date(year, month, 1)
    offset = (6 - first.weekday()) % 7  # Monday = 0 … Sunday = 6
    return first + timedelta(days=offset + 7 * (n - 1))


def us_dst_active(day: _date) -> bool:
    """US daylight time: from the second Sunday of March to the first Sunday of November (exclusive), by calendar date."""
    return _nth_sunday(day.year, 3, 2) <= day < _nth_sunday(day.year, 11, 1)


def us_open_utc_minutes(day: _date) -> int:
    """Minutes after midnight UTC of the 09:30 New York cash open: 13:30 under daylight time, 14:30 otherwise."""
    return 13 * 60 + 30 if us_dst_active(day) else 14 * 60 + 30


def dates_span_us(dates: list[str]) -> tuple[int, int]:
    """`[start, end)` in µs of the calendar days listed (first day's midnight to the day after the last)."""
    days = [tape.day_of_date(d) for d in dates]
    return min(days) * tape.DAY_S * tape.SECOND_US, (max(days) + 1) * tape.DAY_S * tape.SECOND_US


def funding_times_from_tape(funding_rows: np.ndarray, dates: list[str]) -> np.ndarray:
    """Settlement instants (µs, sorted, unique) announced by the instrument's `funding` rows —
    `funding_time_us` and `next_funding_time_us` — inside the calendar span of `dates`. Empty when
    the tape holds no funding row: the caller then falls back to fixed hours (absent, not approximated).
    The first settlement of the first day may be missing when the rows announcing it belong to the
    previous day (not loaded): a known limitation, disclosed in the diagnostics (`funding_instants`)."""
    if funding_rows is None or funding_rows.size == 0:
        return np.zeros(0, dtype=np.int64)
    start_us, end_us = dates_span_us(dates)
    moments = np.concatenate([funding_rows["funding_time_us"].astype(np.int64), funding_rows["next_funding_time_us"].astype(np.int64)])
    moments = moments[(moments >= start_us) & (moments < end_us)]
    return np.unique(moments)


def clock_windows(dates: list[str], arm_min: int = 5, end_min: int = 1, funding_hours: tuple[int, ...] = (0, 8, 16), us_open: bool = True, weekdays_only: bool = True,
                  funding_times_us: np.ndarray | None = None) -> np.ndarray:
    """Clock windows of `dates`: funding settlements (the instants of `funding_times_us` when given —
    per instrument, from the tape — else every `funding_hours` hour of each day) and the US cash
    open on weekdays. `arm_ts_us = h − arm_min` (the decision instant), `end_ts_us = h + end_min`;
    `value` = hour of day (decimal), `aux` = NaN for funding, 1 / 0 (US daylight time) for the open."""
    items = []
    if funding_times_us is not None and len(funding_times_us):
        for t in np.unique(np.asarray(funding_times_us, dtype=np.int64)):
            t = int(t)
            hour = (t // tape.SECOND_US % tape.DAY_S) / 3600.0
            items.append((t, KINDS["clock_funding"], 0, hour, math.nan, -1, t - arm_min * MINUTE_US, t + end_min * MINUTE_US))
    for date in dates:
        day = datetime.strptime(date, "%Y-%m-%d").replace(tzinfo=timezone.utc)
        midnight_us = int(day.timestamp()) * tape.SECOND_US
        if funding_times_us is None or not len(funding_times_us):
            for hour in funding_hours:
                t = midnight_us + hour * 3600 * tape.SECOND_US
                items.append((t, KINDS["clock_funding"], 0, float(hour), math.nan, -1, t - arm_min * MINUTE_US, t + end_min * MINUTE_US))
        if us_open and (not weekdays_only or day.weekday() < 5):
            minutes = us_open_utc_minutes(day.date())
            t = midnight_us + minutes * MINUTE_US
            items.append((t, KINDS["clock_us_open"], 0, float(minutes) / 60.0, 1.0 if us_dst_active(day.date()) else 0.0, -1, t - arm_min * MINUTE_US, t + end_min * MINUTE_US))
    rows = _rows(items)
    return rows[np.argsort(rows["ts_us"], kind="stable")] if rows.size else rows


def summarize_events(rows: np.ndarray) -> dict:
    out: dict = {"events": int(rows.size), "by_kind": {}}
    for kind, code in KINDS.items():
        sel = rows[rows["kind"] == code]
        if sel.size == 0:
            continue
        entry = {"n": int(sel.size), "buy_side": int(np.sum(sel["side"] > 0)), "sell_side": int(np.sum(sel["side"] < 0)), "both": int(np.sum(sel["side"] == 0)),
                 "first": tape.iso_utc(int(sel["ts_us"][0])), "last": tape.iso_utc(int(sel["ts_us"][-1]))}
        if code == KINDS["excursion"]:
            entry["stress"] = {"1": int(np.sum(sel["stress"] == 1)), "0": int(np.sum(sel["stress"] == 0)), "unknown": int(np.sum(sel["stress"] == -1))}
        out["by_kind"][kind] = entry
    return out
