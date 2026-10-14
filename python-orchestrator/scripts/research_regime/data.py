"""Daily market panel for regime-r2: assets = (symbol, segment), aligned on one UTC day axis.

Rules (pre-registration §3, annexes A and B):
- a missing day stays NaN (never reconstructed);
- a quotation gap longer than 7 days starts a new segment (new warm-up); no price threshold splits an asset;
- annex A symbols never load; annex B periods are masked to NaN before segmentation.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta
from pathlib import Path

import numpy as np

from . import annexes

EPOCH = date(1970, 1, 1)
GAP_DAYS = 7


def day_of(d: date) -> int:
    return (d - EPOCH).days


def date_of(day: int) -> date:
    return EPOCH + timedelta(days=int(day))


@dataclass
class Market:
    days: np.ndarray                      # (T,) UTC day numbers, contiguous
    asset_ids: list[str]                  # "SYMBOL#k"
    symbols: list[str]                    # symbol of each asset
    open: np.ndarray                      # (A, T) spot, NaN outside the segment or on a missing day
    close: np.ndarray
    qvol: np.ndarray
    seg_start: np.ndarray                 # (A,) first day index of the segment
    perp_open: np.ndarray                 # (A, T) USD-M perpetual of the same symbol, NaN when absent
    perp_close: np.ndarray
    fund_sum: np.ndarray                  # (A, T) sum of funding rates settled that UTC day
    fund_cnt: np.ndarray                  # (A, T) number of settlements that UTC day (0 = none)
    notes: dict = field(default_factory=dict)

    def index(self, d: date) -> int:
        return day_of(d) - int(self.days[0])


def segments(day_list: np.ndarray, gap: int = GAP_DAYS) -> list[np.ndarray]:
    """Split sorted available days into segments separated by more than ``gap`` missing days."""
    if len(day_list) == 0:
        return []
    breaks = np.where(np.diff(day_list) - 1 > gap)[0] + 1
    return np.split(day_list, breaks)


def build_market(raw_spot: dict[str, dict], raw_perp: dict[str, dict], raw_funding: dict[str, dict],
                 first: date, last: date, annex_a: set[str], annex_b: list[annexes.Exclusion]) -> Market:
    d0, d1 = day_of(first), day_of(last)
    T = d1 - d0 + 1
    days = np.arange(d0, d1 + 1, dtype=np.int64)
    ids, syms, rows, starts = [], [], [], []
    notes = {"segments_split": {}, "annex_b_masked_days": {}}
    for sym in sorted(raw_spot):
        if sym in annex_a:
            continue
        a = raw_spot[sym]
        keep = (a["day"] >= d0) & (a["day"] <= d1)
        dl = a["day"][keep]
        mask = np.array([not annexes.excluded_on(sym, date_of(x), annex_a, annex_b) for x in dl], dtype=bool)
        if (~mask).any():
            notes["annex_b_masked_days"][sym] = int((~mask).sum())
        sel = np.where(keep)[0][mask]
        segs = segments(a["day"][sel])
        if len(segs) > 1:
            notes["segments_split"][sym] = len(segs)
        for k, seg in enumerate(segs):
            pos = np.searchsorted(a["day"], seg)
            rows.append((sym, pos, seg))
            ids.append(f"{sym}#{k}")
            syms.append(sym)
            starts.append(int(seg[0] - d0))
    A = len(ids)
    op, cl, qv = (np.full((A, T), np.nan) for _ in range(3))
    po, pc, fs = (np.full((A, T), np.nan) for _ in range(3))
    fc = np.zeros((A, T), dtype=np.int16)
    for i, (sym, pos, seg) in enumerate(rows):
        a = raw_spot[sym]
        col = seg - d0
        op[i, col], cl[i, col], qv[i, col] = a["open"][pos], a["close"][pos], a["qvol"][pos]
        if sym in raw_perp:
            p = raw_perp[sym]
            k = (p["day"] >= seg[0]) & (p["day"] <= seg[-1])
            po[i, p["day"][k] - d0], pc[i, p["day"][k] - d0] = p["open"][k], p["close"][k]
        if sym in raw_funding:
            f = raw_funding[sym]
            fday = f["ts_ms"] // 86_400_000
            k = (fday >= seg[0]) & (fday <= seg[-1])
            sums = np.zeros(T)
            cnts = np.zeros(T, dtype=np.int16)
            np.add.at(sums, fday[k] - d0, f["rate"][k])
            np.add.at(cnts, fday[k] - d0, 1)
            fs[i] = np.where(cnts > 0, sums, np.nan)
            fc[i] = cnts
    return Market(days, ids, syms, op, cl, qv, np.array(starts), po, pc, fs, fc, notes)


def load_dir(root: Path) -> tuple[dict, dict, dict]:
    def read(kind):
        out = {}
        for p in sorted((root / kind).glob("*.npz")):
            with np.load(p) as z:
                out[p.stem] = {k: z[k] for k in z.files}
        return out
    return read("spot"), read("perp"), read("funding")


def universe(m: Market, size: int, min_history: int = 200, lookback: int = 30) -> np.ndarray:
    """Monthly top-``size`` by median quote volume of the 30 previous days, data known at the month start.

    Candidates: assets whose segment started at least ``min_history`` days before the month start.
    Returns a boolean (A, T) membership matrix.
    """
    A, T = m.close.shape
    member = np.zeros((A, T), dtype=bool)
    month_starts = [i for i in range(T) if date_of(m.days[i]).day == 1]
    for ms in month_starts:
        if ms - lookback < 0:
            continue
        window = m.qvol[:, ms - lookback:ms]
        avail = ~np.isnan(window)
        old = (ms - m.seg_start) >= min_history
        ok = old & avail.any(axis=1)
        med = np.full(A, -np.inf)
        idx = np.where(ok)[0]
        if len(idx):
            med[idx] = np.nanmedian(window[idx], axis=1)
        top = [i for i in np.argsort(-med) if np.isfinite(med[i])][:size]
        end = next((x for x in month_starts if x > ms), T)
        member[top, ms:end] = True
    return member
