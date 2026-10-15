"""Event module (§4, §6 selection): residual shocks, exclusions and the control hours, written with their sha256
BEFORE any outcome is computed.

No event quantity reads a price after the decision hour t: every quantity uses hourly bars closing at or before t
(the β / σ window ends at t - 24 h). The C1 and C2 control hours are chosen here too, so the outcome module only
measures. C2 candidates are first crossings (owner decision of 2026-10-15): the first hour of each shared-shock
episode of a coin, then a 120 h per-coin refractory period, as for the events. Whether an hour is a candidate depends
only on that hour and the coin's earlier hours, but the matching is done after the fact over a pool of three calendar
months (M-1, M, M+1 around the event's month M, clipped to the decision period: Amendment A1 of 2026-10-15), so which
control an event gets depends on candidate hours up to a month after the event's month (§9.2). C1 likewise excludes
hours within ±120 h of the coin's events, later ones included (§6).
"""

from __future__ import annotations

import hashlib
import json
from collections import Counter, defaultdict
from collections.abc import Callable, Mapping
from pathlib import Path

import numpy as np

from ..research_swing2.okx_universe import _month_start_ms, _months
from . import data as dt
from . import protocol as pr
from . import universe as un

REASONS = ("ok", "coin_bar_invalid", "market_window_incomplete", "beta_window_insufficient", "degenerate_variance")
EVENTS_FILE = "h2-events.json"
FIELDS = ("R_i", "R_m", "beta", "sigma_e", "sigma_m", "e", "z", "z_m")


def half_of(month: str) -> int:
    """0 = 2023H1 ... 5 = 2025H2, 6 = 2026H1 with 2026Q3 joined."""
    year, mon = map(int, month.split("-"))
    return min((year - 2023) * 2 + (1 if mon > 6 else 0), 6)


HALF_LABELS = ("2023H1", "2023H2", "2024H1", "2024H2", "2025H1", "2025H2", "2026H1+Q3")


def decision_bars(month: str) -> tuple[int, int]:
    """First and last decision bar (inclusive) whose close falls in `month` and in [DECISION_FROM, DECISION_TO)."""
    start = max(_month_start_ms(month), pr.DECISION_FROM_MS)
    stop = min(_month_start_ms(_months(month, "2099-12")[1]), pr.DECISION_TO_MS)
    return dt.bar_of_close(start), dt.bar_of_close(stop - pr.HOUR_MS)


def _log_returns(close: np.ndarray, lo: int, hi: int) -> np.ndarray:
    """r[b] = ln C(b) - ln C(b-1) for b in [lo, hi]; NaN when either bar is invalid."""
    with np.errstate(invalid="ignore", divide="ignore"):
        return np.log(close[lo:hi + 1]) - np.log(close[lo - 1:hi])


def _window_sum(cum: np.ndarray, end: np.ndarray, length: int) -> np.ndarray:
    """Sum of the local values [end - length + 1, end] from a cumulative array with a leading zero."""
    return cum[end + 1] - cum[end + 1 - length]


def month_panel(closes: Mapping[str, np.ndarray], members: list[str], month: str, market: str = "loo") -> dict[str, dict[str, np.ndarray]]:
    """Per member of `month`: decision-time quantities at every decision bar of the month.

    market `loo`: equal-weight mean of the hourly log returns of the month's other members with a valid return, at
    least `MIN_MARKET_MEMBERS` of them. market `btc`: BTC's hourly log return (sensitivity; BTC itself has no decision).
    """
    d_lo, d_hi = decision_bars(month)
    width, lag = pr.BETA_WINDOW_H, pr.BETA_LAG_H
    lo = d_lo - lag - width + 1
    if lo < 1:
        raise ValueError("the β window starts before the bar grid")
    empty = np.full(dt.n_hours(), np.nan)  # a member without any archived candle has no valid bar
    rets = np.vstack([_log_returns(closes.get(m, empty), lo, d_hi) for m in members]) if members else np.zeros((0, d_hi - lo + 1))
    valid = np.isfinite(rets)
    total = np.where(valid, rets, 0.0).sum(axis=0)
    count = valid.sum(axis=0)
    btc = _log_returns(closes[pr.BTC], lo, d_hi) if market == "btc" else None
    decisions = np.arange(d_lo, d_hi + 1)
    local = decisions - lo
    out = {}
    for k, inst in enumerate(members):
        if market == "btc":
            if inst == pr.BTC:
                continue
            rm = btc
            valid_m = np.isfinite(btc)
        else:
            others = count - valid[k]
            with np.errstate(invalid="ignore", divide="ignore"):
                rm = (total - np.where(valid[k], rets[k], 0.0)) / others
            valid_m = others >= pr.MIN_MARKET_MEMBERS
        rm = np.where(valid_m, rm, np.nan)
        pair = valid[k] & valid_m
        x = np.where(pair, rm, 0.0)
        y = np.where(pair, rets[k], 0.0)
        cums = {name: np.concatenate(([0.0], np.cumsum(arr))) for name, arr in
                (("n", pair.astype(np.float64)), ("x", x), ("y", y), ("xx", x * x), ("xy", x * y), ("yy", y * y))}
        end = local - lag
        n, sx, sy, sxx, sxy, syy = (_window_sum(cums[name], end, width) for name in ("n", "x", "y", "xx", "xy", "yy"))
        shock_n = _window_sum(np.concatenate(([0.0], np.cumsum(valid_m.astype(np.float64)))), local, pr.SHOCK_H)
        shock_sum = _window_sum(np.concatenate(([0.0], np.cumsum(np.where(valid_m, rm, 0.0)))), local, pr.SHOCK_H)
        close = closes.get(inst, empty)
        with np.errstate(invalid="ignore", divide="ignore"):
            r_i = np.log(close[decisions]) - np.log(close[decisions - pr.SHOCK_H])
            beta = sxy / sxx
            ssr = syy - 2 * beta * sxy + beta * beta * sxx
            mean_res = (sy - beta * sx) / n
            var_e = (ssr - n * mean_res * mean_res) / (n - 1)
            var_m = (sxx - sx * sx / n) / (n - 1)
            sigma_e = np.sqrt(pr.SHOCK_H * var_e)
            sigma_m = np.sqrt(pr.SHOCK_H * var_m)
            r_m = np.where(shock_n == pr.SHOCK_H, shock_sum, np.nan)
            e = r_i - beta * r_m
            z = e / sigma_e
            z_m = r_m / sigma_m
        reason = np.zeros(decisions.size, dtype=np.int8)
        reason[(reason == 0) & ~np.isfinite(r_i)] = 1
        reason[(reason == 0) & (shock_n < pr.SHOCK_H)] = 2
        reason[(reason == 0) & (n < pr.BETA_MIN_SHARE * width)] = 3
        degenerate = ~(np.isfinite(sigma_e) & (sigma_e > 0) & np.isfinite(sigma_m) & (sigma_m > 0) & (sxx > 0))
        reason[(reason == 0) & degenerate] = 4
        out[inst] = {"bar": decisions, "reason": reason, "n_beta": n.astype(np.int64), "R_i": r_i, "R_m": r_m, "beta": beta,
                     "sigma_e": sigma_e, "sigma_m": sigma_m, "e": e, "z": z, "z_m": z_m}
    return out


def build_table(closes: Mapping[str, np.ndarray], months_universe: Mapping[str, list[str]], months: list[str], market: str = "loo") -> dict[str, np.ndarray]:
    """All coin-hours of every month as flat arrays sorted by (instrument, bar)."""
    instruments = sorted({i for m in months for i in months_universe[m]})
    index = {inst: k for k, inst in enumerate(instruments)}
    parts = []
    for mi, month in enumerate(months):
        panel = month_panel(closes, sorted(months_universe[month]), month, market)
        for inst, cols in panel.items():
            size = cols["bar"].size
            parts.append({"inst": np.full(size, index[inst], dtype=np.int32), "month": np.full(size, mi, dtype=np.int32),
                          **{k: cols[k] for k in ("bar", "reason", "n_beta", *FIELDS)}})
    if not parts:
        raise ValueError("no coin-hour")
    table = {k: np.concatenate([p[k] for p in parts]) for k in parts[0]}
    order = np.lexsort((table["bar"], table["inst"]))
    table = {k: v[order] for k, v in table.items()}
    table["instruments"] = np.array(instruments)
    table["months"] = np.array(months)
    return table


def first_crossings(table: Mapping[str, np.ndarray], mask: np.ndarray, refractory: int) -> tuple[list[int], list[int]]:
    """Rows of `mask` kept as first crossings: per coin, in chronological order, a row is kept unless it falls less
    than `refractory` hours after the coin's last kept row (then it is suppressed and does not restart the period).
    Returns (kept rows, suppressed rows), both in (instrument id, hour) order."""
    rows = np.flatnonzero(mask)
    rows = rows[np.lexsort((table["bar"][rows], table["inst"][rows]))]
    kept, suppressed = [], []
    last: dict[int, int] = {}
    for row in rows:
        inst, bar = int(table["inst"][row]), int(table["bar"][row])
        if inst in last and bar - last[inst] < refractory:
            suppressed.append(int(row))
            continue
        last[inst] = bar
        kept.append(int(row))
    return kept, suppressed


def detect(table: Mapping[str, np.ndarray], k: float) -> tuple[list[int], list[int]]:
    """Trigger |z| >= k and |z_m| <= 1 on evaluable coin-hours; first crossing per coin, then no event for that coin
    during REFRACTORY_H hours. Returns (event rows, suppressed trigger rows), chronological per coin."""
    with np.errstate(invalid="ignore"):
        trig = (table["reason"] == 0) & (np.abs(table["z"]) >= k) & (np.abs(table["z_m"]) <= pr.ZM_MAX)
    return first_crossings(table, trig, pr.REFRACTORY_H)


def _sign_positive(value: float) -> bool:
    return bool(value > 0)


def c2_candidates(table: Mapping[str, np.ndarray]) -> tuple[list[int], list[int]]:
    """Shared-shock first crossings (owner decision of 2026-10-15): evaluable coin-hours with |z| < 1 and |z_m| >= 2;
    per coin the first hour of each episode, then no candidate for that coin during C2_REFRACTORY_H hours (the event
    rule). Returns (candidate rows, refractory-suppressed rows)."""
    with np.errstate(invalid="ignore"):
        mask = (table["reason"] == 0) & (np.abs(table["z"]) < pr.C2_Z_MAX) & (np.abs(table["z_m"]) >= pr.C2_ZM_MIN)
    return first_crossings(table, mask, pr.C2_REFRACTORY_H)


def c2_pool(table: Mapping[str, np.ndarray], candidates: list[int] | None = None) -> dict[tuple[int, bool], np.ndarray]:
    """The C2 candidates (`c2_candidates`) keyed by (month, R_i > 0), rows sorted by (bar, instrument) so ties go to
    the earliest hour, then the lowest instrument id. `match_c2` joins the pools of the months of an event's window."""
    rows = np.array(c2_candidates(table)[0] if candidates is None else candidates, dtype=np.int64)
    rows = rows[np.lexsort((table["inst"][rows], table["bar"][rows]))]
    pools: dict[tuple[int, bool], list[int]] = defaultdict(list)
    for row in rows:
        pools[(int(table["month"][row]), _sign_positive(table["R_i"][row]))].append(int(row))
    return {key: np.array(value, dtype=np.int64) for key, value in pools.items()}


def _calendar_numbers(table: Mapping[str, np.ndarray]) -> np.ndarray | None:
    """Calendar month number (year * 12 + month - 1) of each month index of the table; None when the table carries no
    month labels (then the month indices are taken as consecutive calendar months)."""
    if "months" not in table:
        return None
    return np.array([int(m[:4]) * 12 + int(m[5:7]) - 1 for m in (str(x) for x in table["months"])], dtype=np.int64)


def pool_months(table: Mapping[str, np.ndarray], month: int, half_width: int) -> list[int]:
    """Month indices of the C2 window of an event of month index `month`: the calendar months within ±half_width of
    its calendar month that belong to the table's months (the decision period), ascending. Clipped: at the first month
    of the period the window is M, M+1; at the last, M-1, M; a calendar month absent from the table is left out."""
    numbers = _calendar_numbers(table)
    if numbers is None:
        return [m for m in range(month - half_width, month + half_width + 1) if m >= 0]
    own = numbers[month]
    return [int(k) for k in np.flatnonzero(np.abs(numbers - own) <= half_width)]


def match_c2(table: Mapping[str, np.ndarray], events: list[int], pools: Mapping[tuple[int, bool], np.ndarray], caliper: float | None,
             half_width: int | None = None) -> list[dict | None]:
    """Greedy, events in chronological order (ties: instrument id): the nearest |R_i| among the candidates of the same
    sign of R_i in the event's window of calendar months (M-1, M, M+1 by Amendment A1, `half_width` 1, clipped to the
    period; `half_width` 0 is the month-only pool of the v1 freeze), within the caliper; an unused candidate first, else
    an already used one (a counted reuse); else no match. A candidate used by one event is used for every later event
    whose window holds it. Candidate ties go to the earliest hour, then the lowest instrument id."""
    width = pr.c2_pool_half_width() if half_width is None else int(half_width)
    used = {key: np.zeros(rows.size, dtype=bool) for key, rows in pools.items()}
    order = sorted(events, key=lambda r: (int(table["bar"][r]), int(table["inst"][r])))
    result: dict[int, dict | None] = {}
    windows: dict[int, list[int]] = {}
    for row in order:
        r_e = float(table["R_i"][row])
        sign = _sign_positive(r_e)
        month = int(table["month"][row])
        if month not in windows:
            windows[month] = pool_months(table, month, width)
        keys = [(m, sign) for m in windows[month] if (m, sign) in pools and pools[(m, sign)].size]
        if not keys:
            result[row] = None
            continue
        # months ascending and each pool sorted by (bar, instrument): the concatenation is in (bar, instrument) order
        pool = np.concatenate([pools[key] for key in keys])
        taken = np.concatenate([used[key] for key in keys])
        size = np.abs(table["R_i"][pool])
        distance = np.abs(size - abs(r_e))
        ok = np.ones(pool.size, dtype=bool) if caliper is None else (np.abs(size / abs(r_e) - 1.0) <= caliper + 1e-12)
        candidates = ok & ~taken
        reuse = False
        if not candidates.any():
            candidates, reuse = ok, True
        if not candidates.any():
            result[row] = None
            continue
        pick = int(np.flatnonzero(candidates)[np.argmin(distance[candidates])])
        for key in keys:
            if pick < used[key].size:
                used[key][pick] = True
                break
            pick -= used[key].size
        chosen = int(pools[key][pick])
        result[row] = {"row": chosen, "reuse": reuse}
    return [result[row] for row in events]


def select_c1(table: Mapping[str, np.ndarray], events: list[int]) -> list[dict]:
    """Same coin, decision hours of the same month, evaluable, no event of that coin within ±C1_EXCLUSION_H hours,
    same σ_e tercile (terciles of the coin's evaluable hours in the month) and same sign of R_m; up to
    C1_PER_EVENT hours drawn without replacement (generator seeded with (SEED, event id); the event id is the event's
    index in detection order, i.e. events numbered in (instrument id, hour) order)."""
    by_coin: dict[int, list[int]] = defaultdict(list)
    for row in events:
        by_coin[int(table["inst"][row])].append(int(table["bar"][row]))
    rows_of: dict[tuple[int, int], np.ndarray] = {}
    ok = table["reason"] == 0
    for inst, month in {(int(table["inst"][r]), int(table["month"][r])) for r in events}:
        rows_of[(inst, month)] = np.flatnonzero(ok & (table["inst"] == inst) & (table["month"] == month))
    out = []
    for index, row in enumerate(events):
        inst, month, bar = int(table["inst"][row]), int(table["month"][row]), int(table["bar"][row])
        rows = rows_of[(inst, month)]
        sigma = table["sigma_e"][rows]
        cuts = np.quantile(sigma, [1 / 3, 2 / 3])
        tercile = np.digitize(sigma, cuts)
        own = np.digitize([table["sigma_e"][row]], cuts)[0]
        bars = table["bar"][rows]
        event_bars = np.array(by_coin[inst])
        far = ~np.any(np.abs(bars[:, None] - event_bars[None, :]) <= pr.C1_EXCLUSION_H, axis=1)
        same_sign = np.sign(table["R_m"][rows]) == np.sign(table["R_m"][row])
        eligible = rows[(tercile == own) & same_sign & far]
        rng = np.random.default_rng([pr.SEED, index])
        picked = eligible if eligible.size <= pr.C1_PER_EVENT else np.sort(rng.choice(eligible, pr.C1_PER_EVENT, replace=False))
        out.append({"rows": [int(r) for r in picked], "eligible": int(eligible.size)})
    return out


def _c1_near_suppressed(table: Mapping[str, np.ndarray], c1: list[dict], events: list[int], suppressed: list[int]) -> int:
    """C1 draws within ±C1_EXCLUSION_H hours of a refractory-suppressed trigger of the same coin (reported: C1
    excludes only the hours around events, §6)."""
    by_coin: dict[int, np.ndarray] = {}
    for row in suppressed:
        inst = int(table["inst"][row])
        by_coin[inst] = np.append(by_coin.get(inst, np.zeros(0, dtype=np.int64)), int(table["bar"][row]))
    total = 0
    for row, control in zip(events, c1):
        bars = by_coin.get(int(table["inst"][row]))
        if bars is None or not control["rows"]:
            continue
        drawn = table["bar"][np.array(control["rows"], dtype=np.int64)]
        total += int(np.any(np.abs(drawn[:, None] - bars[None, :]) <= pr.C1_EXCLUSION_H, axis=1).sum())
    return total


def _record(table: Mapping[str, np.ndarray], row: int) -> dict:
    inst = str(table["instruments"][table["inst"][row]])
    month = str(table["months"][table["month"][row]])
    bar = int(table["bar"][row])
    record = {"inst": inst, "base": un.base_of(inst), "bar": bar, "decision_ms": int(dt.bar_close_ms(bar)), "month": month,
              "half": half_of(month)}
    record.update({name: float(table[name][row]) for name in FIELDS})
    return record


def _exclusions(table: Mapping[str, np.ndarray]) -> dict:
    by_month = {}
    for mi, month in enumerate(table["months"]):
        counts = np.bincount(table["reason"][table["month"] == mi], minlength=len(REASONS))
        by_month[str(month)] = {REASONS[i]: int(c) for i, c in enumerate(counts)}
    total = np.bincount(table["reason"], minlength=len(REASONS))
    return {"coin_hours": int(table["reason"].size), "by_reason": {REASONS[i]: int(c) for i, c in enumerate(total)}, "by_month": by_month}


def compute(closes: Mapping[str, np.ndarray], universe: Mapping, months: list[str] | None = None) -> dict:
    """The whole event document from hourly closes and the monthly universe. Pure function (deterministic)."""
    months = un.decision_months() if months is None else months
    months_universe = {m: universe["months"][m]["universe"] for m in months}
    table = build_table(closes, months_universe, months, "loo")
    events, suppressed = detect(table, pr.K)
    candidates, candidates_suppressed = c2_candidates(table)
    pools = c2_pool(table, candidates)
    c2 = match_c2(table, events, pools, pr.caliper(), pr.c2_pool_half_width())
    c1 = select_c1(table, events)
    near_suppressed = _c1_near_suppressed(table, c1, events, suppressed)
    records = []
    for index, (row, match, control) in enumerate(zip(events, c2, c1)):
        record = {"id": index, **_record(table, row)}
        record["side"] = "long" if record["e"] < 0 else "short"
        record["secondary"] = un.secondary(record["inst"])
        record["c1"] = {"bars": [int(table["bar"][r]) for r in control["rows"]], "eligible": control["eligible"]}
        if match is None:
            record["c2"] = None
        else:
            other = _record(table, match["row"])
            record["c2"] = {k: other[k] for k in ("inst", "bar", "decision_ms", "month", "R_i", "beta", "z", "z_m")} | {"reuse": match["reuse"]}
        records.append(record)
    records.sort(key=lambda r: (r["decision_ms"], r["inst"]))
    sensitivity_k, _ = detect(table, pr.K_SENSITIVITY)
    sensitivities = {"k2.5": _minimal(table, sensitivity_k)}
    if pr.BTC in closes:
        btc_table = build_table(closes, months_universe, months, "btc")
        btc_events, _ = detect(btc_table, pr.K)
        sensitivities["btc_market"] = _minimal(btc_table, btc_events)
        btc_exclusions = _exclusions(btc_table)
    else:
        sensitivities["btc_market"] = None
        btc_exclusions = None
    per_hour = Counter(Counter(r["decision_ms"] for r in records).values())
    pool_sizes = {f"{table['months'][m]}|{'+' if s else '-'}": int(v.size) for (m, s), v in sorted(pools.items())}
    matched = sum(1 for r in records if r["c2"] is not None)
    outside = sum(1 for r in records if r["c2"] is not None and r["c2"]["month"] != r["month"])
    return {
        "schema": "h2-gate1-events.v1",
        "parameters": _parameters(),
        "universe": {"months": {m: months_universe[m] for m in months}},
        "events": records,
        "exclusions": {"loo": _exclusions(table), "btc_market": btc_exclusions,
                       "refractory_suppressed": [{"inst": str(table["instruments"][table["inst"][r]]), "bar": int(table["bar"][r]),
                                                  "z": float(table["z"][r])} for r in suppressed]},
        "events_per_hour": {str(k): v for k, v in sorted(per_hour.items())},
        "c2": {"caliper": pr.C2_CALIPER_NAME, "candidates": pr.C2_CANDIDATES, "refractory_h": pr.C2_REFRACTORY_H,
               "candidates_kept": len(candidates), "candidates_suppressed_by_refractory": len(candidates_suppressed),
               "pool": pr.C2_POOL, "pool_half_width_months": pr.c2_pool_half_width(),
               "pool_window": "calendar months M-1, M, M+1 around the event's month M, clipped to the decision months",
               "pool_sizes": pool_sizes, "matched": matched, "matched_outside_event_month": outside,
               "reused": sum(1 for r in records if r["c2"] is not None and r["c2"]["reuse"]),
               "match_rate_at_selection": matched / len(records) if records else None},
        "c1": {"events_without_control": sum(1 for r in records if not r["c1"]["bars"]),
               "events_with_fewer_than_20": sum(1 for r in records if len(r["c1"]["bars"]) < pr.C1_PER_EVENT),
               "draws_within_120h_of_a_suppressed_trigger": near_suppressed},
        "sensitivities": sensitivities,
    }


def _minimal(table: Mapping[str, np.ndarray], rows: list[int]) -> list[dict]:
    out = []
    for row in rows:
        record = _record(table, row)
        record["side"] = "long" if record["e"] < 0 else "short"
        out.append(record)
    out.sort(key=lambda r: (r["decision_ms"], r["inst"]))
    return out


def _parameters() -> dict:
    names = ("K", "K_SENSITIVITY", "ZM_MAX", "REFRACTORY_H", "BETA_WINDOW_H", "BETA_LAG_H", "BETA_MIN_SHARE", "SHOCK_H",
             "BAR_MIN_MINUTES", "MIN_MARKET_MEMBERS", "C2_Z_MAX", "C2_ZM_MIN", "C2_CANDIDATES", "C2_REFRACTORY_H", "C2_POOL", "C2_CALIPER_NAME", "C1_PER_EVENT",
             "C1_EXCLUSION_H", "SEED", "DECISION_FROM_MS", "DECISION_TO_MS", "DATA_END_MS", "GRID_START_MS")
    return {name: getattr(pr, name) for name in names}


def canonical(document: Mapping) -> bytes:
    return (json.dumps(document, sort_keys=True, separators=(",", ":"), allow_nan=True) + "\n").encode()


def write(document: Mapping, out: Path) -> str:
    """Writes the event file and `<file>.sha256`; returns the sha256. Refuses to overwrite."""
    out = Path(out)
    target = out / EVENTS_FILE
    blob = canonical(document)
    digest = hashlib.sha256(blob).hexdigest()
    with open(target, "xb") as handle:
        handle.write(blob)
    with open(out / (EVENTS_FILE + ".sha256"), "x") as handle:
        handle.write(f"{digest}  {EVENTS_FILE}\n")
    return digest


def load_closes(sources: dt.Sources, guard: Callable[[], None] | None = None) -> tuple[dict, dict[str, np.ndarray], dict]:
    """Universe from the archives, then the hourly closes of every member (and BTC). Each instrument's candles are
    read once."""
    closes, coverage = {}, {}

    def keep(inst: str, minutes: dict) -> None:
        if minutes["ts_s"].size:
            closes[inst], _ = dt.hourly_bars(minutes["ts_s"], minutes["close"])
            coverage[inst] = {"minutes": int(minutes["ts_s"].size), "valid_bars": int(np.isfinite(closes[inst]).sum()),
                              "first_minute_s": int(minutes["ts_s"][0]), "last_minute_s": int(minutes["ts_s"][-1])}

    first_day, volumes, notes = un.gather(sources, guard, keep)
    universe = un.build(first_day, volumes)
    for inst in sorted(set(universe["members"]) | {pr.BTC}):
        if inst in closes:
            continue
        if guard is not None:
            guard()
        keep(inst, dt.load_minutes(sources, inst))
    closes = {inst: closes[inst] for inst in sorted(set(universe["members"]) | {pr.BTC}) if inst in closes}
    universe["volume_notes"] = notes
    universe["coverage"] = {inst: coverage[inst] for inst in closes}
    return universe, closes, universe["coverage"]


def run(sources: dt.Sources, out: Path, guard: Callable[[], None] | None = None) -> str:
    universe, closes, _ = load_closes(sources, guard)
    document = compute(closes, universe)
    document["universe"]["rule"] = universe["rule"]
    document["universe"]["eligible"] = {m: universe["months"][m]["eligible"] for m in universe["months"]}
    document["universe"]["volume_notes"] = universe["volume_notes"]
    document["universe"]["coverage"] = universe["coverage"]
    return write(document, out)
