"""Grid, engine, statistics, controls and budget checks on synthetic candles."""

from __future__ import annotations

import pytest

np = pytest.importorskip("numpy")

from scripts.research_v2 import budgets, controls, costs, stats  # noqa: E402
from scripts.research_v2.bars import Candles  # noqa: E402
from scripts.research_v2.dataset import Funding  # noqa: E402
from scripts.research_v2.engine import OUTCOME_DTYPE, PairInputs, Window, plan_prices, run_configuration, run_pair  # noqa: E402
from scripts.research_v2.features import compute_features  # noqa: E402
from scripts.research_v2.grid import build_grid, exit_variants, parse_configuration  # noqa: E402
from scripts.research_v2.setups import CATALOGUE, SetupSignals, compute  # noqa: E402

MINUTE = 60_000
HOUR = 60 * MINUTE
DAY = 24 * HOUR
START_MS = 1_672_531_200_000 - 60 * DAY  # 60 days of warm-up before 2023-01-01


def _profiles() -> dict[str, costs.CostProfile]:
    return {
        "baseline": costs.CostProfile("baseline", 0.0, 0.0002, 0.0, 0.0, 0.0005, 0.0, 0.0003),
        "adverse": costs.CostProfile("adverse", 0.0, 0.001, 0.0, 0.0, 0.001, 0.0, 0.001),
    }


def _instrument(symbol: str = "SYNUSDT") -> costs.Instrument:
    return costs.Instrument(symbol, 0.01, 0.01, 0.01, 5.0, 1_000_000.0, 2.0)


def _synthetic_pair(symbol: str, days: int = 400, seed: int = 1) -> PairInputs:
    rng = np.random.default_rng(seed)
    n = days * 24 * 60
    steps = rng.normal(0.0, 0.0004, n)
    steps += 0.00002 * np.sin(np.arange(n) / (7 * 24 * 60))  # slow regime drift
    close = 100.0 * np.exp(np.cumsum(steps))
    open_ = np.concatenate(([100.0], close[:-1]))
    wick = np.abs(rng.normal(0.0, 0.0003, n)) * close
    high = np.maximum(open_, close) + wick
    low = np.minimum(open_, close) - wick
    volume = rng.uniform(1.0, 10.0, n)
    candles = Candles(symbol, START_MS + np.arange(n, dtype=np.int64) * MINUTE, open_, high, low, close, volume)
    funding_ms = START_MS + np.arange(0, n * MINUTE, 8 * HOUR, dtype=np.int64)
    funding = Funding(symbol, funding_ms, rng.normal(0.0001, 0.0002, funding_ms.size))
    four_hour = compute_features(candles, "4h", funding)
    fifteen = compute_features(candles, "15m", funding, context=four_hour)
    return PairInputs.build(candles, funding, {"4h": four_hour, "15m": fifteen}, _instrument(symbol))


@pytest.fixture(scope="module")
def pairs() -> dict[str, PairInputs]:
    return {"SYNUSDT": _synthetic_pair("SYNUSDT", seed=1), "ALTUSDT": _synthetic_pair("ALTUSDT", seed=2)}


def test_grid_matches_the_pre_registered_count() -> None:
    grid = build_grid()
    assert len(grid) == 436
    assert len({configuration.id for configuration in grid}) == 436
    families = {}
    for configuration in grid:
        families[configuration.family] = families.get(configuration.family, 0) + 1
    assert families == {"A": 216, "B": 88, "C": 60, "D": 72}
    assert [variant.id for variant in exit_variants("trend", "4h")] == ["S2.T3", "S2.T5", "S2.TR3", "S3.T3", "S3.T5", "S3.TR3"]
    assert [variant.id for variant in exit_variants("reversion", "15m")] == ["S1.5.X", "S1.5.T2", "S2.5.X", "S2.5.T2"]
    configuration = parse_configuration("B1.RSI2|15m|short|S1.5.X")
    assert configuration.side == -1 and configuration.variant.indicator_exit and configuration.variant.horizon_ms == 8 * HOUR
    with pytest.raises(KeyError):
        parse_configuration("B1.RSI2|15m|short|S9.X")


def test_every_setup_computes_on_every_declared_cadence(pairs) -> None:
    inputs = pairs["SYNUSDT"]
    for spec in CATALOGUE:
        for cadence in spec.cadences:
            for side in (1, -1):
                signals = compute(spec, inputs.features[cadence], side)
                assert signals.signal.shape == (inputs.features[cadence].size,)
                assert not np.any(signals.signal & ~signals.regime)
                if spec.family == "B":
                    assert signals.exit is not None
    with pytest.raises(ValueError):
        compute(CATALOGUE[0], inputs.features["4h"], 0)


def test_plan_prices_round_like_the_canonical_builder() -> None:
    entry, stop, target = plan_prices(1, 100.004, 2.0, 3.0, 5.0, 0.01)
    assert entry == 100.01  # rounded up to the tick for a long
    assert stop == 94.01  # 100.01 - 6 exactly on the tick
    assert target == pytest.approx(100.01 + 5 * 6.0)
    entry, stop, target = plan_prices(-1, 100.004, 2.0, 3.0, None, 0.01)
    assert entry == 100.0 and stop == 106.0 and target is None
    entry, stop, _ = plan_prices(1, 100.0, 0.001, 1.0, None, 0.01)
    assert stop == 99.99  # degenerate ATR still leaves one tick of distance


def test_run_pair_respects_one_plan_per_pair_and_settles_both_profiles(pairs) -> None:
    configuration = parse_configuration("A1.N20|4h|long|S3.T5")
    inputs = pairs["SYNUSDT"]
    window = Window(START_MS + 60 * DAY, START_MS + 400 * DAY)
    run = run_pair(configuration, inputs, window, _profiles())
    outcomes = run.outcomes
    assert run.signals >= run.admitted >= outcomes.size
    assert outcomes.dtype == OUTCOME_DTYPE
    if outcomes.size > 1:
        # No overlap: each decision comes after the previous exit.
        assert np.all(outcomes["decision_ms"][1:] >= outcomes["exit_ms"][:-1])
    for row in outcomes:
        assert row["fill_ms"] > row["decision_ms"] and row["exit_ms"] >= row["fill_ms"]
        assert row["risk_adverse"] > row["risk_base"] > 0
        assert row["net_adverse"] <= row["net_base"] + 1e-9
        assert row["notional"] <= 250.0
        if row["exit_reason"] == "target":
            assert row["gross"] > 0
        if row["exit_reason"] == "stop":
            assert row["gross"] < 0


def test_run_configuration_merges_pairs_in_time_order(pairs) -> None:
    configuration = parse_configuration("B1.RSI2|15m|long|S1.5.X")
    window = Window(START_MS + 60 * DAY, START_MS + 400 * DAY)
    outcomes, counters = run_configuration(configuration, pairs, window, _profiles())
    assert counters["filled"] == outcomes.size
    assert set(np.unique(outcomes["symbol"])) <= {"SYNUSDT", "ALTUSDT"}
    assert np.all(np.diff(outcomes["decision_ms"]) >= 0)
    assert set(np.unique(outcomes["exit_reason"])) <= {"indicator", "stop", "time", "window_end"}


def test_statistics_and_gates_on_a_synthetic_ledger(pairs) -> None:
    configuration = parse_configuration("A1.N20|15m|long|S1.5.T3")
    window = Window(START_MS + 60 * DAY, START_MS + 400 * DAY)
    outcomes, _ = run_configuration(configuration, pairs, window, _profiles())
    base = stats.summarize(outcomes, "base")
    adverse = stats.summarize(outcomes, "adverse")
    assert base["trades"] == outcomes.size > 20
    assert base["net"] == pytest.approx(float(outcomes["net_base"].sum()))
    assert base["net"] >= adverse["net"]
    assert abs(base["mean_r"] - float(np.mean(outcomes["net_base"] / outcomes["risk_base"]))) < 1e-12
    assert base["lower_bound_r"] < base["mean_r"]
    assert base["realized_drawdown"] >= 0 and base["max_exposure"] <= 500.0 + 1e-9
    assert sum(value["trades"] for value in base["by_year"].values()) == base["trades"]
    assert sum(value["trades"] for value in base["by_pair"].values()) == base["trades"]
    assert base["net_without_best_month"] == pytest.approx(base["net"] - base["by_month"][base["best_month"]], abs=0.01)
    reasons = stats.training_gates(base, adverse, "15m", years=(2023,), control={"median": 0.0, "p95": 10.0})
    assert any(reason.startswith("G5") for reason in reasons)  # p95 of 10 R cannot be beaten
    assert stats.training_gates({"trades": 0}, {"trades": 0}, "4h") == ["G1:baseline:trades<200", "G1:adverse:trades<200"]
    validation = stats.validation_gates(base, adverse, "15m", training_mean_r_base=base["mean_r"] * 4)
    assert "V:baseline:mean_r<50%_of_training" in validation or base["trades"] < 150


def test_bootstrap_and_drawdown_helpers() -> None:
    assert stats.bootstrap_lower_bound(np.array([1.0])) is None
    values = np.concatenate([np.full(50, 1.0), np.full(50, -0.5)])
    lower = stats.bootstrap_lower_bound(values, seed=1)
    assert 0.0 < lower < 0.25
    assert stats.realized_drawdown(np.array([1.0, -2.0, 0.5, -1.0])) == pytest.approx(2.5)
    assert stats.realized_drawdown(np.zeros(0)) == 0.0
    assert list(stats.years_of(np.array([1_672_531_200_000, 1_735_689_599_999]))) == [2023, 2024]
    assert list(stats.months_of(np.array([1_672_531_200_000]))) == ["2023-01"]


def test_controls_match_counts_per_pair_and_year(pairs) -> None:
    configuration = parse_configuration("A1.N20|4h|long|S3.T5")
    window = Window(START_MS + 60 * DAY, START_MS + 400 * DAY)
    outcomes, _ = run_configuration(configuration, pairs, window, _profiles())
    control = controls.run_control(configuration, pairs, window, _profiles(), outcomes, seed=3)
    assert control.dtype == OUTCOME_DTYPE
    assert 0 < control.size <= 2 * outcomes.size + 10
    distribution = controls.control_distribution(configuration, pairs, window, _profiles(), outcomes, seeds=3)
    assert distribution["seeds"] == 3 and len(distribution["mean_r"]) == 3
    assert distribution["median"] <= distribution["p95"] <= distribution["max"]
    pool = np.arange(100)
    years = np.where(pool < 50, 2023, 2024)
    sampled = controls.sample_decisions(pool, years, {2023: 10, 2024: 100}, np.random.default_rng(0))
    assert np.all(np.diff(sampled) > 0)
    assert int((sampled < 50).sum()) == 20 and int((sampled >= 50).sum()) == 50


def test_budget_study_scales_linearly_and_respects_minimums(pairs) -> None:
    configuration = parse_configuration("A1.N20|4h|long|S3.T5")
    window = Window(START_MS + 60 * DAY, START_MS + 400 * DAY)
    outcomes, _ = run_configuration(configuration, pairs, window, _profiles())
    instruments = {symbol: inputs.instrument for symbol, inputs in pairs.items()}
    study = budgets.budget_study(outcomes, instruments, "base", budgets=(500.0, 100_000.0))
    small, large = study["500"], study["100000"]
    assert small["attempts"] == large["attempts"] == outcomes.size
    assert large["taken"] == outcomes.size
    assert small["taken"] <= large["taken"]
    assert large["roi"] == pytest.approx(large["net"] / 100_000.0)
    assert 0 <= small["max_drawdown_share"] < 1
    tight = {symbol: costs.Instrument(symbol, 0.01, 1.0, 1.0, 5_000.0, 1e9, 2.0) for symbol in pairs}
    blocked = budgets.budget_study(outcomes, tight, "base", budgets=(500.0,))["500"]
    assert blocked["taken"] == 0 and blocked["pairs_never_tradable"] == sorted(pairs)


def test_second_pass_filters_and_selection(pairs) -> None:
    from scripts.research_v2 import select
    from scripts.research_v2.filters import FILTER_IDS, filter_mask

    inputs = pairs["SYNUSDT"]
    features = inputs.features["4h"]
    for filter_id in FILTER_IDS:
        mask = filter_mask(filter_id, features, 1)
        assert mask.shape == (features.size,) and mask.dtype == bool
    us = filter_mask("E2.US", features, 1)
    hours = (features.bars.close_ms // HOUR) % 24
    assert np.all(hours[us] >= 12) and np.all(hours[us] <= 20)
    weekdays = ((features.bars.close_ms // DAY) + 3) % 7
    assert np.all(weekdays[filter_mask("E3", features, 1)] < 5)
    with pytest.raises(KeyError):
        filter_mask("E9", features, 1)
    filtered = parse_configuration("A1.N20|4h|long|S3.T5|E3")
    assert filtered.filter_id == "E3" and filtered.base_id == "A1.N20|4h|long|S3.T5"
    with pytest.raises(KeyError):
        parse_configuration("A1.N20|4h|long|S3.T5|E9")
    window = Window(START_MS + 60 * DAY, START_MS + 400 * DAY)
    plain = run_pair(parse_configuration("A1.N20|4h|long|S3.T5"), inputs, window, _profiles())
    weekdays_only = run_pair(filtered, inputs, window, _profiles())
    assert weekdays_only.signals <= plain.signals
    assert np.all((((weekdays_only.outcomes["decision_ms"] // DAY) + 3) % 7) < 5)

    def row(identifier: str, lower: float, passes: bool = True) -> dict:
        family, cadence = identifier.split(".")[0][0], identifier.split("|")[1]
        return {"id": identifier, "family": family, "cadence": cadence, "passes": passes, "baseline": {"lower_bound_r": lower, "mean_r": lower + 0.1, "trades": 300}}

    screen = {"code": {"package_sha256": "x"}, "rows": [row("A1.N20|4h|long|S3.T5", 0.2), row("A1.N55|4h|long|S3.T5", 0.3), row("A2.N20.K1|4h|long|S2.T3", 0.25), row("A3|4h|long|S2.T3", 0.4), row("D3|4h|long|S2.T5", 0.1), row("B2|4h|long|S2.X", 0.5, passes=False)]}
    survivors = select.select(screen)
    assert [entry["id"] for entry in survivors["survivors"]] == ["A3|4h|long|S2.T3", "A1.N55|4h|long|S3.T5", "A2.N20.K1|4h|long|S2.T3", "D3|4h|long|S2.T5"]
    assert survivors["passing"] == 5 and len(survivors["second_pass_configurations"]) == 4 * len(FILTER_IDS)
    second = {"code": {"package_sha256": "y"}, "rows": [row("A3|4h|long|S2.T3|E3", 0.45), row("A3|4h|long|S2.T3|E1", 0.6, passes=False), row("D3|4h|long|S2.T5|E4", 0.05)]}
    merged = select.merge_second_pass(survivors, second)
    assert merged["validation_configurations"] == ["A3|4h|long|S2.T3|E3", "A1.N55|4h|long|S3.T5", "A2.N20.K1|4h|long|S2.T3", "D3|4h|long|S2.T5"]
