"""Scalping screen checks: fill modes, structural stops, doubled spreads, catalogue and grid."""

from __future__ import annotations

import pytest

np = pytest.importorskip("numpy")

from scripts.research_v2 import costs, scalping  # noqa: E402
from scripts.research_v2.bars import Candles, aggregate  # noqa: E402
from scripts.research_v2.dataset import Funding  # noqa: E402
from scripts.research_v2.engine import PairInputs, Window, plan_prices_from_distance, run_configuration, run_pair  # noqa: E402
from scripts.research_v2.features import compute_features  # noqa: E402
from scripts.research_v2.fills import FillResult, PlanSpec, simulate  # noqa: E402
from scripts.research_v2.stats import scalping_training_gates, scalping_validation_gates, summarize  # noqa: E402

MINUTE = 60_000
HOUR = 60 * MINUTE
DAY = 24 * HOUR
START_MS = 1_672_531_200_000 - 10 * DAY


def _flat(n: int, price: float = 100.0) -> dict[str, np.ndarray]:
    return {
        "open_ms": np.arange(n, dtype=np.int64) * MINUTE,
        "open": np.full(n, price),
        "high": np.full(n, price),
        "low": np.full(n, price),
        "close": np.full(n, price),
        "volume": np.ones(n),
    }


def _candles(arrays: dict[str, np.ndarray], symbol: str = "TESTUSDT") -> Candles:
    return Candles(symbol, arrays["open_ms"], arrays["open"], arrays["high"], arrays["low"], arrays["close"], arrays["volume"])


def test_limit_through_needs_one_tick_beyond_the_limit() -> None:
    arrays = _flat(3 * 60)
    arrays["low"][5] = 100.0  # touches the limit only
    arrays["low"][7] = 99.99  # trades through by one tick
    candles = _candles(arrays)
    bars = aggregate(candles, "5m")
    plan = PlanSpec("TESTUSDT", 1, 0, 100.0, 99.0, 101.5, None, 60 * MINUTE, 5 * MINUTE, entry_mode="limit_through", through=0.01)
    result = simulate(candles, bars, plan, end_ms=2 * HOUR)
    assert result.filled and result.fill_index == 7
    plan = PlanSpec("TESTUSDT", 1, 0, 100.0, 99.0, 101.5, None, 60 * MINUTE, 2 * MINUTE, entry_mode="limit_through", through=0.01)
    assert simulate(candles, bars, plan, end_ms=2 * HOUR) == FillResult(False)
    with pytest.raises(ValueError):
        PlanSpec("TESTUSDT", 1, 0, 100.0, 99.0, 101.5, None, 60 * MINUTE, 5 * MINUTE, entry_mode="limit_through", through=0.0)
    with pytest.raises(ValueError):
        PlanSpec("TESTUSDT", 1, 0, 100.0, 99.0, 101.5, None, 60 * MINUTE, 5 * MINUTE, entry_mode="fok")


def test_market_entry_fills_at_the_next_open() -> None:
    arrays = _flat(3 * 60)
    arrays["open"][5] = 100.3
    arrays["high"][5] = 100.4
    arrays["low"][5] = 100.2
    arrays["high"][9] = 101.0
    candles = _candles(arrays)
    bars = aggregate(candles, "5m")
    plan = PlanSpec("TESTUSDT", 1, 0, 100.3, 99.8, 100.9, None, 15 * MINUTE, MINUTE, entry_mode="market")
    result = simulate(candles, bars, plan, end_ms=2 * HOUR)
    assert result.filled and result.fill_index == 5 and result.fill_ms == 6 * MINUTE
    assert result.exit_reason == "target" and result.exit_index == 9


def test_plan_prices_from_distance_and_taker_costs() -> None:
    entry, stop, target = plan_prices_from_distance(1, 100.123, 0.5, 1.5, 0.01, "market")
    assert entry == 100.123 and stop == pytest.approx(99.62) and target == pytest.approx(100.88, abs=0.011)
    entry, stop, target = plan_prices_from_distance(-1, 100.123, 0.5, None, 0.01, "limit_through")
    assert entry == 100.12 and stop == 100.62 and target is None
    profile = costs.CostProfile("baseline", 0.0, 0.0002, 0.0, 0.0, 0.0005, 0.0, 0.0003)
    maker = costs.settle(1, 100.0, 101.0, 1.0, "target", profile, entry_role="maker")
    taker = costs.settle(1, 100.0, 101.0, 1.0, "target", profile, entry_role="taker")
    assert taker.fees_quote > maker.fees_quote
    assert taker.spread_quote == pytest.approx(0.0002 * 100) and taker.slippage_quote == pytest.approx(0.0005 * 100)
    assert costs.stop_risk_quote(1, 100.0, 99.0, 1.0, 1, profile, "taker") > costs.stop_risk_quote(1, 100.0, 99.0, 1.0, 1, profile, "maker")
    doubled = costs.doubled_spread(profile)
    assert doubled.stop_spread_rate == 0.0004 and doubled.stop_slippage_rate == 0.0005 and doubled.name == "baseline_spread_x2"
    with pytest.raises(ValueError):
        costs.settle(1, 100.0, 101.0, 1.0, "target", profile, entry_role="middle")


def test_grid_and_catalogue() -> None:
    grid = scalping.build_scalping_grid()
    assert len(grid) == 40
    assert len({configuration.id for configuration in grid}) == 40
    by_cadence = {}
    for configuration in grid:
        by_cadence[configuration.cadence] = by_cadence.get(configuration.cadence, 0) + 1
    assert by_cadence == {"5m": 36, "1m": 4}
    configuration = scalping.parse_scalp_configuration("S3.US|5m|long|SB.T2")
    assert configuration.variant.stop_atr is None and configuration.variant.entry_mode == "limit_through"
    micro = scalping.parse_scalp_configuration("S5|1m|short|S1.T1.5")
    assert micro.variant.entry_mode == "market" and micro.variant.min_stop_ticks == 2 and micro.variant.horizon_ms == 15 * MINUTE
    with pytest.raises(KeyError):
        scalping.parse_scalp_configuration("S1|1m|long|S1.T2")
    with pytest.raises(ValueError):
        scalping.scalp_exit_variants(scalping.SCALP_BY_ID["S1"], "4h")


def _synthetic_pair(symbol: str, days: int = 40, seed: int = 1) -> PairInputs:
    rng = np.random.default_rng(seed)
    n = days * 24 * 60
    close = 100.0 * np.exp(np.cumsum(rng.normal(0.0, 0.0005, n)))
    open_ = np.concatenate(([100.0], close[:-1]))
    wick = np.abs(rng.normal(0.0, 0.0004, n)) * close
    high = np.maximum(open_, close) + wick
    low = np.minimum(open_, close) - wick
    volume = rng.uniform(1.0, 10.0, n)
    taker_buy = volume * rng.uniform(0.2, 0.8, n)
    candles = Candles(symbol, START_MS + np.arange(n, dtype=np.int64) * MINUTE, open_, high, low, close, volume)
    funding_ms = START_MS + np.arange(0, n * MINUTE, 8 * HOUR, dtype=np.int64)
    funding = Funding(symbol, funding_ms, rng.normal(0.0001, 0.0002, funding_ms.size))
    four_hour = compute_features(candles, "4h", funding)
    fifteen = compute_features(candles, "15m", funding, context=four_hour)
    five = scalping.compute_scalping_features(candles, taker_buy, "5m", funding, fifteen)
    one = scalping.compute_scalping_features(candles, taker_buy, "1m", funding, fifteen)
    instrument = costs.Instrument(symbol, 0.01, 0.01, 0.01, 5.0, 1e9, 2.0)
    return PairInputs.build(candles, funding, {"5m": five, "1m": one}, instrument)


@pytest.fixture(scope="module")
def pairs() -> dict[str, PairInputs]:
    return {"SYNUSDT": _synthetic_pair("SYNUSDT", seed=1), "ALTUSDT": _synthetic_pair("ALTUSDT", seed=2)}


def _profiles() -> dict[str, costs.CostProfile]:
    baseline = costs.CostProfile("baseline", 0.0, 0.0002, 0.0, 0.0, 0.0005, 0.0, 0.0003)
    return {"baseline": baseline, "adverse": costs.CostProfile("adverse", 0.0, 0.001, 0.0, 0.0, 0.001, 0.0, 0.001), "doubled": costs.doubled_spread(baseline)}


def test_scalping_features_and_signals(pairs) -> None:
    features = pairs["SYNUSDT"].features["5m"]
    for name in ("atr14", "vwap", "vwap_sigma", "taker_share5", "dh12", "ctx_close", "ctx_ema50", "ob_us_high", "ob_asia_low"):
        assert name in features.columns
    share = features["taker_share5"]
    assert np.nanmin(share) >= 0 and np.nanmax(share) <= 1
    assert np.isnan(features["ob_us_high"]).sum() > 0.9 * features.size  # only 30 minutes a day
    for spec in scalping.SCALP_CATALOGUE:
        for cadence in spec.cadences:
            for side in (1, -1):
                signals = scalping.compute_scalp_signals(spec, pairs["SYNUSDT"].features[cadence], side)
                assert signals.signal.shape == (pairs["SYNUSDT"].features[cadence].size,)
                assert not np.any(signals.signal & ~signals.regime)
                assert (signals.stop_level is not None) == spec.structural_stop
                assert (signals.exit is not None) == spec.indicator_exit
    with pytest.raises(ValueError):
        scalping.compute_scalp_signals(scalping.SCALP_BY_ID["S6"], features, 1)


def test_scalping_configurations_replay_with_three_profiles(pairs) -> None:
    window = Window(START_MS + 5 * DAY, START_MS + 40 * DAY)
    for identifier in ("S1|5m|long|S1.T1.5", "S3.US|5m|short|SB.T2", "S4|5m|long|S1.5.X", "S5|1m|long|S1.T1.5", "S6|1m|short|S1.X"):
        configuration = scalping.parse_scalp_configuration(identifier)
        outcomes, counters = run_configuration(configuration, pairs, window, _profiles())
        assert counters["filled"] == outcomes.size
        if outcomes.size == 0:
            continue
        assert np.all(~np.isnan(outcomes["net_doubled"]))
        assert np.all(outcomes["net_doubled"] <= outcomes["net_base"] + 1e-9)
        assert np.all(outcomes["risk_doubled"] >= outcomes["risk_base"])
        holding = outcomes["exit_ms"] - outcomes["fill_ms"]
        assert np.all(holding <= configuration.variant.horizon_ms + MINUTE)
        if configuration.variant.entry_mode == "market":
            assert np.all(outcomes["fill_ms"] == outcomes["decision_ms"] + MINUTE)
        if configuration.setup.structural_stop:
            assert np.all(configuration.side * (outcomes["entry"] - outcomes["stop"]) > 0)
        doubled = summarize(outcomes, "doubled")
        base = summarize(outcomes, "base")
        assert doubled["net"] <= base["net"]
    run = run_pair(scalping.parse_scalp_configuration("S2|5m|long|S1.T2"), pairs["SYNUSDT"], window, {"baseline": _profiles()["baseline"], "adverse": _profiles()["adverse"]})
    if run.outcomes.size:
        assert np.all(np.isnan(run.outcomes["net_doubled"]))


def test_scalping_gates() -> None:
    year = {"2023": {"trades": 400, "net": 10.0, "mean_r": 0.1, "win_rate": 0.5}, "2024": {"trades": 700, "net": 10.0, "mean_r": 0.1, "win_rate": 0.5}}
    good = {"trades": 1100, "mean_r": 0.08, "lower_bound_r": 0.02, "profit_factor": 1.3, "net_without_best_month": 5.0, "realized_drawdown": 10.0, "pairs_positive": 7, "by_year_decision": year, "net": 20.0}
    adverse = dict(good, profit_factor=1.05, mean_r=0.02)
    doubled = {"trades": 1100, "net": 1.0}
    assert scalping_training_gates(good, adverse, doubled, "5m", control={"median": 0.0, "p95": 0.05}) == []
    reasons = scalping_training_gates(good, adverse, {"trades": 1100, "net": -1.0}, "5m", control={"median": 0.05, "p95": 0.09})
    assert "G8:doubled_spread:net<=0" in reasons and "G5:mean_r<=control_p95" in reasons and "G5:edge<0.05" in reasons
    assert scalping_training_gates({"trades": 10}, {"trades": 10}, doubled, "1m") == ["G1:baseline:trades<2000", "G1:adverse:trades<2000"]
    validation = {"trades": 350, "net": 5.0, "mean_r": 0.03, "profit_factor": 1.2}
    assert scalping_validation_gates(validation, dict(validation, profit_factor=1.0), "5m", 0.05) == []
    assert "V:baseline:mean_r<50%_of_training" in scalping_validation_gates(validation, validation, "5m", 0.2)
    assert scalping_validation_gates({"trades": 100}, {"trades": 100}, "1m", 0.1) == ["V:baseline:trades<600", "V:adverse:trades<600"]


def test_controls_keep_structural_stops(pairs) -> None:
    from scripts.research_v2 import controls

    configuration = scalping.parse_scalp_configuration("S3.US|5m|long|SB.T1.5")
    window = Window(START_MS + 5 * DAY, START_MS + 40 * DAY)
    outcomes, _ = run_configuration(configuration, pairs, window, _profiles())
    control = controls.run_control(configuration, pairs, window, _profiles(), outcomes, seed=1)
    assert control.dtype == outcomes.dtype
    if control.size:
        assert np.all(control["entry"] > control["stop"])
