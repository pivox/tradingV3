"""Cadence bars built from 1-minute candles by UTC-aligned timestamp bucketing.

A ``Candles`` object holds contiguous-or-not 1-minute candles of one pair as
numpy arrays. ``aggregate`` groups them into 15-minute, 1-hour, 4-hour, ...
bars whose open time is a multiple of the cadence since the Unix epoch (UTC).
Bucketing by timestamp rather than by position keeps gaps (exchange downtime)
from shifting the bars. A bar that has no 1-minute candle is simply absent.

Each cadence bar remembers the index range of its 1-minute candles so that a
decision taken at the bar close can be simulated on the following candles.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

MINUTE_MS = 60_000
CADENCE_MS = {"1m": MINUTE_MS, "5m": 5 * MINUTE_MS, "15m": 15 * MINUTE_MS, "1h": 60 * MINUTE_MS, "4h": 240 * MINUTE_MS, "1d": 1_440 * MINUTE_MS}


@dataclass(frozen=True)
class Candles:
    """1-minute candles of one symbol, sorted by open time, timestamps in ms (UTC)."""

    symbol: str
    open_ms: np.ndarray  # int64
    open: np.ndarray
    high: np.ndarray
    low: np.ndarray
    close: np.ndarray
    volume: np.ndarray

    def __post_init__(self) -> None:
        sizes = {self.open_ms.size, self.open.size, self.high.size, self.low.size, self.close.size, self.volume.size}
        if len(sizes) != 1:
            raise ValueError("candle arrays must have the same length")
        if self.open_ms.size and np.any(np.diff(self.open_ms) <= 0):
            raise ValueError("candle timestamps must be strictly increasing")

    @property
    def size(self) -> int:
        return int(self.open_ms.size)

    def slice(self, start: int, stop: int) -> "Candles":
        return Candles(
            self.symbol,
            self.open_ms[start:stop],
            self.open[start:stop],
            self.high[start:stop],
            self.low[start:stop],
            self.close[start:stop],
            self.volume[start:stop],
        )


@dataclass(frozen=True)
class Bars:
    """Cadence bars with, for each bar, the half-open 1-minute index range [first, last)."""

    symbol: str
    cadence: str
    open_ms: np.ndarray  # bar open time, multiple of the cadence
    close_ms: np.ndarray  # bar open time + cadence (exclusive end)
    open: np.ndarray
    high: np.ndarray
    low: np.ndarray
    close: np.ndarray
    volume: np.ndarray
    first_minute: np.ndarray  # int64 index into the source candles
    last_minute: np.ndarray  # exclusive
    complete: np.ndarray  # bool: every expected 1-minute candle is present

    @property
    def size(self) -> int:
        return int(self.open_ms.size)


def aggregate(candles: Candles, cadence: str) -> Bars:
    """Group 1-minute candles into UTC-aligned bars of ``cadence``."""
    if cadence not in CADENCE_MS:
        raise ValueError(f"unknown cadence {cadence!r}")
    step = CADENCE_MS[cadence]
    if candles.size == 0:
        empty_f = np.zeros(0)
        empty_i = np.zeros(0, dtype=np.int64)
        return Bars(candles.symbol, cadence, empty_i, empty_i, empty_f, empty_f, empty_f, empty_f, empty_f, empty_i, empty_i, np.zeros(0, dtype=bool))
    bucket = candles.open_ms // step
    boundaries = np.flatnonzero(np.diff(bucket)) + 1
    starts = np.concatenate(([0], boundaries)).astype(np.int64)
    ends = np.concatenate((boundaries, [candles.size])).astype(np.int64)
    open_ms = bucket[starts] * step
    return Bars(
        symbol=candles.symbol,
        cadence=cadence,
        open_ms=open_ms,
        close_ms=open_ms + step,
        open=candles.open[starts],
        high=np.maximum.reduceat(candles.high, starts),
        low=np.minimum.reduceat(candles.low, starts),
        close=candles.close[ends - 1],
        volume=np.add.reduceat(candles.volume, starts),
        first_minute=starts,
        last_minute=ends,
        complete=(ends - starts) == step // MINUTE_MS,
    )


def bar_index_at_or_before(bars: Bars, timestamp_ms: int) -> int:
    """Index of the last bar whose close time is <= ``timestamp_ms`` (-1 when none)."""
    return int(np.searchsorted(bars.close_ms, timestamp_ms, side="right")) - 1


def align_higher_cadence(lower: Bars, higher: Bars) -> np.ndarray:
    """For each bar of ``lower``, the index of the last *closed* bar of ``higher`` (-1 when none).

    A higher bar is closed for a lower bar when the higher bar's close time is at or before the
    lower bar's close time; the higher bar that contains the lower bar is still forming and is
    excluded, which prevents look-ahead.
    """
    return np.searchsorted(higher.close_ms, lower.close_ms, side="right").astype(np.int64) - 1
