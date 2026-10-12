"""Reference checks for ``scripts.research_v2.indicators`` against brute-force definitions."""

from __future__ import annotations

import math

import pytest

np = pytest.importorskip("numpy")

from scripts.research_v2 import indicators as ind  # noqa: E402


def _series(n: int = 300, seed: int = 7) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
    rng = np.random.default_rng(seed)
    close = 100.0 + np.cumsum(rng.normal(0.0, 1.0, n))
    spread = np.abs(rng.normal(0.0, 0.8, n)) + 0.1
    high = close + spread
    low = close - spread
    volume = rng.uniform(10.0, 100.0, n)
    timestamps = 1_700_000_000_000 + np.arange(n, dtype=np.int64) * 900_000
    return timestamps, high, low, close, volume


def _brute_ema(values: np.ndarray, period: int) -> list[float]:
    alpha = 2.0 / (period + 1.0)
    out = [math.nan] * len(values)
    previous = sum(values[:period]) / period
    out[period - 1] = previous
    for i in range(period, len(values)):
        previous = alpha * values[i] + (1 - alpha) * previous
        out[i] = previous
    return out


def test_sma_and_ema_match_brute_force() -> None:
    _, _, _, close, _ = _series()
    expected_sma = [math.nan] * 19 + [float(np.mean(close[i - 19 : i + 1])) for i in range(19, close.size)]
    np.testing.assert_allclose(ind.sma(close, 20)[19:], expected_sma[19:], rtol=1e-12)
    assert np.all(np.isnan(ind.sma(close, 20)[:19]))
    np.testing.assert_allclose(ind.ema(close, 20)[19:], _brute_ema(close, 20)[19:], rtol=1e-12)


def test_wilder_and_atr_match_brute_force() -> None:
    _, high, low, close, _ = _series()
    tr = [high[0] - low[0]] + [
        max(high[i] - low[i], abs(high[i] - close[i - 1]), abs(low[i] - close[i - 1])) for i in range(1, close.size)
    ]
    expected = [math.nan] * len(tr)
    previous = sum(tr[:14]) / 14
    expected[13] = previous
    for i in range(14, len(tr)):
        previous = previous + (tr[i] - previous) / 14
        expected[i] = previous
    np.testing.assert_allclose(ind.atr(high, low, close, 14)[13:], expected[13:], rtol=1e-12)
    assert np.all(ind.atr(high, low, close, 14)[13:] > 0)


def test_rsi_bounds_and_monotone_cases() -> None:
    rising = np.arange(1.0, 60.0)
    falling = rising[::-1].copy()
    assert np.allclose(ind.rsi(rising, 14)[14:], 100.0)
    assert np.allclose(ind.rsi(falling, 14)[14:], 0.0)
    _, _, _, close, _ = _series()
    values = ind.rsi(close, 14)
    assert np.all(np.isnan(values[:14]))
    assert np.all((values[14:] >= 0) & (values[14:] <= 100))


def test_rsi_matches_wilder_reference() -> None:
    _, _, _, close, _ = _series(120)
    delta = np.diff(close)
    gains = np.where(delta > 0, delta, 0.0)
    losses = np.where(delta < 0, -delta, 0.0)
    ag = np.mean(gains[:14])
    al = np.mean(losses[:14])
    expected = [math.nan] * close.size
    expected[14] = 100 - 100 / (1 + ag / al)
    for i in range(14, delta.size):
        ag = ag + (gains[i] - ag) / 14
        al = al + (losses[i] - al) / 14
        expected[i + 1] = 100 - 100 / (1 + ag / al)
    np.testing.assert_allclose(ind.rsi(close, 14)[14:], expected[14:], rtol=1e-12)


def test_stochastic_k_is_in_range_and_smoothed() -> None:
    _, high, low, close, _ = _series()
    raw = ind.stochastic_k(high, low, close, 14, smooth=1)
    slow = ind.stochastic_k(high, low, close, 14, smooth=3)
    assert np.all((raw[13:] >= 0) & (raw[13:] <= 100))
    np.testing.assert_allclose(slow[15:], [np.mean(raw[i - 2 : i + 1]) for i in range(15, raw.size)], rtol=1e-12)


def test_adx_components_are_consistent() -> None:
    _, high, low, close, _ = _series(400)
    result = ind.adx(high, low, close, 14)
    valid = ~np.isnan(result.adx)
    assert valid.sum() > 300
    assert np.all((result.adx[valid] >= 0) & (result.adx[valid] <= 100))
    assert np.all(result.plus_di[~np.isnan(result.plus_di)] >= 0)
    assert np.all(result.minus_di[~np.isnan(result.minus_di)] >= 0)
    # A straight rising series has only +DM, so +DI dominates and ADX tends to 100.
    rising = np.arange(1.0, 200.0)
    trending = ind.adx(rising + 1, rising - 1, rising, 14)
    assert trending.adx[-1] > 99
    assert trending.minus_di[-1] == 0


def test_macd_histogram_is_line_minus_signal() -> None:
    _, _, _, close, _ = _series()
    result = ind.macd(close, 12, 26, 9)
    assert np.all(np.isnan(result.macd[:25]))
    assert not np.isnan(result.macd[25])
    assert np.all(np.isnan(result.signal[:33]))
    assert not np.isnan(result.signal[33])
    valid = ~np.isnan(result.histogram)
    np.testing.assert_allclose(result.histogram[valid], (result.macd - result.signal)[valid], rtol=1e-12)


def test_bollinger_uses_population_std_and_width() -> None:
    _, _, _, close, _ = _series()
    bands = ind.bollinger(close, 20, 2.0)
    i = 57
    window = close[i - 19 : i + 1]
    assert bands.middle[i] == pytest.approx(window.mean())
    assert bands.upper[i] == pytest.approx(window.mean() + 2 * window.std())
    assert bands.lower[i] == pytest.approx(window.mean() - 2 * window.std())
    assert bands.width[i] == pytest.approx((bands.upper[i] - bands.lower[i]) / bands.middle[i])


def test_donchian_excludes_the_current_bar() -> None:
    high = np.array([1.0, 5.0, 2.0, 3.0, 9.0, 1.0])
    low = np.array([0.5, 4.0, 1.0, 2.0, 8.0, 0.2])
    upper, lower = ind.donchian(high, low, 3)
    assert np.all(np.isnan(upper[:3]))
    assert upper[3] == 5.0 and upper[4] == 5.0 and upper[5] == 9.0
    assert lower[3] == 0.5 and lower[4] == 1.0 and lower[5] == 1.0


def test_roc_and_percentile_rank() -> None:
    close = np.array([10.0, 11.0, 12.1, 11.0, 10.0])
    np.testing.assert_allclose(ind.roc(close, 2)[2:], [0.21, 0.0, 10.0 / 12.1 - 1.0])
    rank = ind.rolling_percentile_rank(np.array([1.0, 2.0, 3.0, 0.5, 2.5]), 4)
    assert np.all(np.isnan(rank[:3]))
    assert rank[3] == 0.0
    assert rank[4] == pytest.approx(2.0 / 3.0)


def test_supertrend_direction_follows_trend() -> None:
    rising = np.linspace(100.0, 200.0, 120)
    result = ind.supertrend(rising + 1.0, rising - 1.0, rising, 10, 3.0)
    assert result.direction[-1] == 1
    assert result.line[-1] < rising[-1]
    falling = rising[::-1].copy()
    result = ind.supertrend(falling + 1.0, falling - 1.0, falling, 10, 3.0)
    assert result.direction[-1] == -1
    assert result.line[-1] > falling[-1]
    assert np.all(result.direction[:9] == 0)


def test_ichimoku_displacement() -> None:
    _, high, low, close, _ = _series(120)
    result = ind.ichimoku(high, low, close)
    assert np.all(np.isnan(result.senkou_b[:77]))
    assert not np.isnan(result.senkou_b[77])
    assert result.chikou_reference[30] == close[4]
    mid = (np.max(high[0:9]) + np.min(low[0:9])) / 2
    assert result.tenkan[8] == pytest.approx(mid)


def test_fractal_pivots_and_narrowest_range() -> None:
    high = np.array([1.0, 2.0, 5.0, 2.0, 1.0, 3.0, 4.0])
    low = np.array([0.0, -1.0, 0.5, -2.0, -0.5, 0.0, 1.0])
    pivot_high, pivot_low = ind.fractal_pivots(high, low, 2)
    assert list(np.flatnonzero(pivot_high)) == [2]
    assert list(np.flatnonzero(pivot_low)) == [3]
    narrow = ind.narrowest_range(np.array([3.0, 3.0, 3.0, 3.0]), np.array([0.0, 1.0, 2.5, 2.0]), 3)
    assert list(narrow) == [False, False, True, False]


def test_anchored_vwap_resets_each_session() -> None:
    timestamps = np.array([0, 1, 2, 86_400_000, 86_400_001], dtype=np.int64)
    high = np.array([11.0, 12.0, 13.0, 21.0, 22.0])
    low = np.array([9.0, 10.0, 11.0, 19.0, 20.0])
    close = np.array([10.0, 11.0, 12.0, 20.0, 21.0])
    volume = np.array([1.0, 1.0, 2.0, 1.0, 3.0])
    result = ind.anchored_vwap(timestamps, high, low, close, volume)
    assert result.vwap[0] == 10.0
    assert result.vwap[2] == pytest.approx((10 + 11 + 24) / 4)
    assert result.vwap[3] == 20.0
    assert result.vwap[4] == pytest.approx((20 + 63) / 4)
    assert np.isnan(result.deviation_sigma[0]) and np.isnan(result.deviation_sigma[3])
    assert result.deviation_sigma[2] > 0


def test_short_inputs_return_nan_without_raising() -> None:
    short = np.array([1.0, 2.0])
    assert np.all(np.isnan(ind.ema(short, 5)))
    assert np.all(np.isnan(ind.rsi(short, 5)))
    assert np.all(np.isnan(ind.adx(short, short, short, 5).adx))
    with pytest.raises(ValueError):
        ind.sma(short, 0)
    with pytest.raises(ValueError):
        ind.sma(np.zeros((2, 2)), 1)
