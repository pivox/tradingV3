"""r13 (PREREGISTRATION-r13.md): open-interest positioning features from the Binance daily `metrics`
archives (`acquire.py metrics`), projected on the one-second bars of the execution tape.

A snapshot stamped `T` (5-minute grid) is usable from second `T + METRICS_LAG_S`: the decision
seconds of the O rules are those seconds, for every `T` whose snapshot fifteen minutes earlier
(`T − OI_WINDOW_S`) also exists; `oi_chg_15` is the relative change of the open interest between
the two snapshots, defined at the decision seconds only.
"""

from __future__ import annotations

from pathlib import Path

import numpy as np

METRICS_STEP_S = 300
METRICS_LAG_S = 60  # pre-declared availability lag of a 5-minute snapshot (§2)
OI_WINDOW_S = 900  # the open-interest and price windows: fifteen minutes (§2)
METRICS_FEATURES = ("metrics_tick", "oi_chg_15")


def oi_rule_catalogue() -> list[str]:
    """r13 §3: `O1` flush reversal and `O2` build-up continuation, open-interest threshold 1 % or 2 %
    over fifteen minutes, price move of at least 1.5 sigma over the same fifteen minutes."""
    return [f"O{n}-T{threshold}-K1.5" for n in (1, 2) for threshold in (1, 2)]


def load_metrics(paths: tuple[Path, ...]) -> dict[str, np.ndarray]:
    """The `ts_s` and `oi` records of the given daily files, concatenated, sorted, one per grid point
    (the last wins)."""
    stamps, values = [], []
    for path in paths:
        with np.load(path) as data:
            stamps.append(data["ts_s"].astype(np.int64))
            values.append(data["oi"].astype(np.float64))
    if not stamps:
        return {"ts_s": np.zeros(0, dtype=np.int64), "oi": np.zeros(0)}
    ts_s, oi = np.concatenate(stamps), np.concatenate(values)
    order = np.argsort(ts_s, kind="stable")
    ts_s, oi = ts_s[order], oi[order]
    keep = np.ones(ts_s.size, dtype=bool)
    keep[:-1] = ts_s[1:] != ts_s[:-1]
    return {"ts_s": ts_s[keep], "oi": oi[keep]}


def oi_features(metrics: dict[str, np.ndarray], start_s: int, end_s: int, lag_s: int = METRICS_LAG_S, window_s: int = OI_WINDOW_S) -> dict[str, np.ndarray]:
    """Per-second arrays over `start_s ≤ s < end_s`: `metrics_tick` True at `T + lag_s` for each
    snapshot `T` with a snapshot at `T − window_s` and positive, finite open interests at both;
    `oi_chg_15` = OI(T) / OI(T − window_s) − 1 at those seconds, NaN elsewhere."""
    n = int(end_s - start_s)
    tick = np.zeros(n, dtype=bool)
    change = np.full(n, np.nan)
    ts, oi = metrics["ts_s"], metrics["oi"]
    if ts.size:
        previous = np.searchsorted(ts, ts - window_s)
        safe = np.minimum(previous, ts.size - 1)
        ok = (previous < ts.size) & (ts[safe] == ts - window_s) & np.isfinite(oi) & np.isfinite(oi[safe]) & (oi > 0) & (oi[safe] > 0)
        seconds = ts + lag_s - int(start_s)
        ok &= (seconds >= 0) & (seconds < n)
        tick[seconds[ok]] = True
        with np.errstate(invalid="ignore", divide="ignore"):
            change[seconds[ok]] = oi[ok] / oi[safe[ok]] - 1.0
    return {"metrics_tick": tick, "oi_chg_15": change}


def metrics_loader(paths: tuple[Path, ...], start_s: int, end_s: int):
    """Deferred computation of the positioning features of one series from its daily metrics files
    (days without a file give no decision; the screen lists them in `missing_days`)."""

    def load() -> dict[str, np.ndarray]:
        return oi_features(load_metrics(tuple(paths)), start_s, end_s)

    return load
