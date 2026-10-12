"""Fill-simulation and settlement checks on hand-built 1-minute candles."""

from __future__ import annotations

import pytest

np = pytest.importorskip("numpy")

from scripts.research_v2 import costs  # noqa: E402
from scripts.research_v2.bars import Candles, aggregate  # noqa: E402
from scripts.research_v2.fills import FillResult, PlanSpec, simulate  # noqa: E402

MINUTE = 60_000
HOUR = 60 * MINUTE


def _flat(n: int, price: float = 100.0, start_ms: int = 0) -> dict[str, np.ndarray]:
    return {
        "open_ms": start_ms + np.arange(n, dtype=np.int64) * MINUTE,
        "open": np.full(n, price),
        "high": np.full(n, price),
        "low": np.full(n, price),
        "close": np.full(n, price),
        "volume": np.ones(n),
    }


def _candles(arrays: dict[str, np.ndarray]) -> Candles:
    return Candles("TESTUSDT", arrays["open_ms"], arrays["open"], arrays["high"], arrays["low"], arrays["close"], arrays["volume"])


def _plan(**overrides) -> PlanSpec:
    base = dict(symbol="TESTUSDT", side=1, decision_index=0, entry_price=100.0, stop_price=97.0, target_price=109.0, trailing_distance=None, horizon_ms=24 * HOUR, ttl_ms=15 * MINUTE)
    base.update(overrides)
    return PlanSpec(**base)


def test_limit_entry_fills_when_price_trades_through_then_target() -> None:
    arrays = _flat(4 * 60)
    # Decision bar = first 15m bar (minutes 0..14). Entry rests from minute 15.
    arrays["low"][15] = 100.0  # touches the limit
    arrays["high"][40] = 109.5  # target later
    candles = _candles(arrays)
    bars = aggregate(candles, "15m")
    result = simulate(candles, bars, _plan(), end_ms=10 * HOUR)
    assert result.filled and result.fill_index == 15 and result.fill_ms == 16 * MINUTE
    assert result.exit_reason == "target" and result.exit_index == 40 and result.exit_price == 109.0


def test_entry_expires_after_ttl() -> None:
    arrays = _flat(4 * 60, price=100.0)
    arrays["low"][15:30] = 100.5  # never trades down to the limit inside the 15-minute TTL
    arrays["low"][45] = 99.0  # too late
    candles = _candles(arrays)
    result = simulate(candles, aggregate(candles, "15m"), _plan(), end_ms=10 * HOUR)
    assert result == FillResult(False)


def test_stop_wins_ties_and_fills_at_the_worse_of_stop_and_open() -> None:
    arrays = _flat(4 * 60)
    arrays["low"][15] = 100.0
    arrays["open"][30] = 95.0  # gap through the 97 stop
    arrays["low"][30] = 94.0
    arrays["high"][30] = 110.0  # target touched in the same candle: the stop wins
    candles = _candles(arrays)
    result = simulate(candles, aggregate(candles, "15m"), _plan(), end_ms=10 * HOUR)
    assert result.exit_reason == "stop" and result.exit_index == 30 and result.exit_price == 95.0


def test_target_is_not_credited_on_the_fill_candle() -> None:
    arrays = _flat(4 * 60)
    arrays["low"][15] = 100.0
    arrays["high"][15] = 112.0  # same candle as the fill
    candles = _candles(arrays)
    result = simulate(candles, aggregate(candles, "15m"), _plan(), end_ms=3 * HOUR)
    assert result.exit_reason == "window_end"
    assert result.exit_index == 3 * 60 - 1


def test_time_stop_exits_at_the_horizon_close() -> None:
    arrays = _flat(4 * 60)
    arrays["low"][15] = 100.0
    arrays["close"][2 * 60 - 1] = 101.0
    candles = _candles(arrays)
    result = simulate(candles, aggregate(candles, "15m"), _plan(horizon_ms=105 * MINUTE), end_ms=10 * HOUR)
    # Horizon from the decision close (minute 15): 15 + 105 = 120 -> last monitored candle is 119.
    assert result.exit_reason == "time" and result.exit_index == 119 and result.exit_price == 101.0


def test_short_side_mirrors_long() -> None:
    arrays = _flat(4 * 60)
    arrays["high"][15] = 100.0
    arrays["low"][50] = 90.0
    candles = _candles(arrays)
    plan = _plan(side=-1, stop_price=103.0, target_price=91.0)
    result = simulate(candles, aggregate(candles, "15m"), plan, end_ms=10 * HOUR)
    assert result.exit_reason == "target" and result.exit_index == 50 and result.exit_price == 91.0


def test_trailing_stop_ratchets_at_bar_closes() -> None:
    arrays = _flat(6 * 60)
    arrays["low"][15] = 100.0
    # Bar 1 (minutes 15..29) closes at 110; bar 2 trades at 118 and closes at 120; bar 3 dips to 114.
    arrays["close"][29] = 110.0
    arrays["high"][29] = 110.0
    for name in ("open", "high", "low", "close"):
        arrays[name][30:60] = 118.0
    arrays["close"][44] = 120.0
    arrays["high"][44] = 120.0
    arrays["low"][50] = 114.0  # 120 - 5 = 115 trailing stop is touched; the candle opened at 118
    candles = _candles(arrays)
    plan = _plan(stop_price=97.0, target_price=None, trailing_distance=5.0)
    result = simulate(candles, aggregate(candles, "15m"), plan, end_ms=10 * HOUR)
    assert result.exit_reason == "trailing" and result.exit_index == 50 and result.exit_price == 115.0
    assert result.extreme_close == 120.0


def test_indicator_exit_closes_at_the_bar_close() -> None:
    arrays = _flat(6 * 60)
    arrays["low"][15] = 100.0
    arrays["close"][59] = 103.0
    candles = _candles(arrays)
    bars = aggregate(candles, "15m")
    flags = np.zeros(bars.size, dtype=bool)
    flags[0] = True  # the decision bar itself must not trigger
    flags[3] = True  # minutes 45..59
    plan = _plan(stop_price=97.0, target_price=None)
    result = simulate(candles, bars, plan, end_ms=10 * HOUR, exit_flags=flags)
    assert result.exit_reason == "indicator" and result.exit_index == 59 and result.exit_price == 103.0


def test_plan_spec_validation() -> None:
    with pytest.raises(ValueError):
        _plan(stop_price=101.0)
    with pytest.raises(ValueError):
        _plan(target_price=99.0)
    with pytest.raises(ValueError):
        _plan(side=2)
    with pytest.raises(ValueError):
        _plan(trailing_distance=0.0)


def _profile() -> costs.CostProfile:
    return costs.CostProfile("baseline", 0.0, 0.0002, 0.0, 0.0, 0.0005, 0.0, 0.0003)


def test_stop_risk_matches_the_campaign_plan_row() -> None:
    # AVAXUSDT long, entry 13.547, stop 12.82, quantity 18, 42 funding intervals (research-plan.v1 row).
    risk = costs.stop_risk_quote(1, 13.547, 12.82, 18.0, 42, _profile())
    assert risk == pytest.approx(16.4841408, abs=1e-9)


def test_settlement_charges_market_like_exits_and_funding() -> None:
    profile = _profile()
    target = costs.settle(1, 100.0, 110.0, 2.0, "target", profile)
    assert target.gross_quote == pytest.approx(20.0)
    assert target.fees_quote == pytest.approx(0.0002 * 200 + 0.0005 * 220)
    assert target.spread_quote == 0.0 and target.slippage_quote == 0.0
    stop = costs.settle(1, 100.0, 97.0, 2.0, "stop", profile, funding_paid_quote=0.5)
    assert stop.spread_quote == pytest.approx(0.0002 * 194)
    assert stop.slippage_quote == pytest.approx(0.0005 * 194)
    assert stop.funding_quote == -0.5
    assert stop.net_quote == pytest.approx(-6.0 - stop.fees_quote - stop.spread_quote - stop.slippage_quote - 0.5)
    with pytest.raises(ValueError):
        costs.settle(1, 100.0, 97.0, 2.0, "nope", profile)


def test_funding_paid_uses_events_after_the_fill_up_to_the_exit() -> None:
    funding_ms = np.array([0, 8 * HOUR, 16 * HOUR, 24 * HOUR], dtype=np.int64)
    rates = np.array([0.0001, 0.0002, -0.0001, 0.0003])
    marks = np.array([100.0, 100.0, 100.0, 100.0])
    paid = costs.funding_paid(1, 2.0, fill_ms=1 * HOUR, exit_ms=16 * HOUR, funding_ms=funding_ms, funding_rate=rates, mark_price=marks)
    assert paid == pytest.approx(2.0 * (0.0002 - 0.0001) * 100.0)
    received = costs.funding_paid(-1, 2.0, fill_ms=1 * HOUR, exit_ms=16 * HOUR, funding_ms=funding_ms, funding_rate=rates, mark_price=marks)
    assert received == pytest.approx(-paid)
    assert costs.funding_paid(1, 2.0, 1, 2, funding_ms, rates, marks) == 0.0


def test_round_quantity_respects_step_and_minimums() -> None:
    btc = costs.Instrument("BTCUSDT", 0.1, 0.001, 0.001, 50.0, 1000.0, 2.0)
    assert costs.round_quantity(250.0, 60_000.0, btc) == 0.004
    assert costs.round_quantity(250.0, 110_000.0, btc) == 0.002
    assert costs.round_quantity(40.0, 60_000.0, btc) == 0.0  # 0.000 after rounding
    assert costs.round_quantity(100.0, 110_000.0, btc) == 0.0  # 0.000 after rounding
    ada = costs.Instrument("ADAUSDT", 0.0001, 1.0, 1.0, 5.0, 30_000_000.0, 2.0)
    assert costs.round_quantity(250.0, 0.9, ada) == 277.0
    assert costs.round_quantity(4.0, 0.9, ada) == 0.0  # below the 5 USDT minimum notional
