"""Checks for ``scripts.research_v2.bars`` (UTC bucketing, gaps, look-ahead alignment)."""

from __future__ import annotations

import pytest

np = pytest.importorskip("numpy")

from scripts.research_v2.bars import Candles, aggregate, align_higher_cadence, bar_index_at_or_before  # noqa: E402

MINUTE = 60_000


def _candles(n: int, start_ms: int = 0, skip: set[int] | None = None) -> Candles:
    keep = [i for i in range(n) if not skip or i not in skip]
    index = np.array(keep, dtype=np.int64)
    close = 100.0 + index.astype(float)
    return Candles(
        "TESTUSDT",
        start_ms + index * MINUTE,
        close - 0.5,
        close + 1.0,
        close - 1.0,
        close,
        np.full(index.size, 2.0),
    )


def test_aggregate_aligns_bars_on_utc_multiples() -> None:
    candles = _candles(45, start_ms=7 * MINUTE)  # starts at 00:07, so the first 15m bar is partial
    bars = aggregate(candles, "15m")
    assert bars.size == 4
    assert list(bars.open_ms) == [0, 15 * MINUTE, 30 * MINUTE, 45 * MINUTE]
    assert list(bars.complete) == [False, True, True, False]
    assert bars.open[1] == candles.open[8]
    assert bars.close[1] == candles.close[22]
    assert bars.high[1] == candles.high[22]
    assert bars.low[1] == candles.low[8]
    assert bars.volume[1] == 30.0
    assert bars.first_minute[1] == 8 and bars.last_minute[1] == 23


def test_aggregate_keeps_gaps_from_shifting_bars() -> None:
    candles = _candles(60, skip={20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44})
    bars = aggregate(candles, "15m")
    assert list(bars.open_ms) == [0, 15 * MINUTE, 45 * MINUTE]
    assert list(bars.complete) == [True, False, True]
    assert bars.first_minute[2] == 20 and bars.last_minute[2] == 35
    assert bars.close[2] == candles.close[-1]


def test_aggregate_rejects_unknown_cadence_and_handles_empty() -> None:
    candles = _candles(3)
    with pytest.raises(ValueError):
        aggregate(candles, "7m")
    empty = aggregate(candles.slice(0, 0), "4h")
    assert empty.size == 0


def test_candles_validate_their_arrays() -> None:
    with pytest.raises(ValueError):
        Candles("X", np.array([0, 0], dtype=np.int64), np.zeros(2), np.zeros(2), np.zeros(2), np.zeros(2), np.zeros(2))
    with pytest.raises(ValueError):
        Candles("X", np.array([0], dtype=np.int64), np.zeros(2), np.zeros(2), np.zeros(2), np.zeros(2), np.zeros(2))


def test_higher_cadence_alignment_excludes_the_forming_bar() -> None:
    candles = _candles(8 * 60)  # eight hours
    low = aggregate(candles, "15m")
    high = aggregate(candles, "4h")
    aligned = align_higher_cadence(low, high)
    # The first 15 fifteen-minute bars close before 04:00, while the first 4h bar is forming: -1.
    assert list(aligned[:15]) == [-1] * 15
    # The bar closing at 04:00 and the next fifteen see the first 4h bar, closed at 04:00.
    assert list(aligned[15:31]) == [0] * 16
    assert aligned[31] == 1
    assert bar_index_at_or_before(high, 4 * 60 * MINUTE) == 0
    assert bar_index_at_or_before(high, 4 * 60 * MINUTE - 1) == -1
