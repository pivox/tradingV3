"""Second-pass filters E1–E4 (PREREGISTRATION.md, section 3E), applied to survivors only.

A filter is a boolean mask over the cadence bars: True where the decision may be taken. Time
filters use the decision time, that is the bar close.
"""

from __future__ import annotations

import numpy as np

from .features import DAY_MS, HOUR_MS, Features

FILTER_IDS = ("E1", "E2.US", "E2.ASIA", "E3", "E4")
FUNDING_THRESHOLD = 0.0003  # 0.03 % per 8 hours
VOLUME_RATIO = 1.5


def filter_mask(filter_id: str, f: Features, side: int) -> np.ndarray:
    close_ms = f.bars.close_ms
    if filter_id == "E1":
        rate = f["funding_last"]
        crowded = (rate > FUNDING_THRESHOLD) if side == 1 else (rate < -FUNDING_THRESHOLD)
        return ~np.where(np.isnan(rate), False, crowded)
    if filter_id == "E2.US":
        hour = (close_ms // HOUR_MS) % 24
        return (hour >= 12) & (hour <= 20)
    if filter_id == "E2.ASIA":
        hour = (close_ms // HOUR_MS) % 24
        return hour <= 8
    if filter_id == "E3":
        weekday = ((close_ms // DAY_MS) + 3) % 7  # Monday = 0
        return weekday < 5
    if filter_id == "E4":
        with np.errstate(invalid="ignore", divide="ignore"):
            ratio = f["volume"] / f["vol_avg20"]
        return np.where(np.isnan(ratio), False, ratio > VOLUME_RATIO)
    raise KeyError(filter_id)
