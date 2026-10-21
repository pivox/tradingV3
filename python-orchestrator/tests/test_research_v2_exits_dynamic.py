"""Dynamic exits on identical entries (specs/dynamic_exits_v1.json) — synthetic data only."""

from __future__ import annotations

import json

import pytest

np = pytest.importorskip("numpy")

from scripts.research_v2 import costs, exits_dynamic as dx  # noqa: E402
from scripts.research_v2.bars import Candles, aggregate  # noqa: E402
from scripts.research_v2.fills import PlanSpec, simulate  # noqa: E402

MINUTE = 60_000
SPEC = dx.load_spec()
PARAMS = dx.method_params(SPEC)
PROFILES = dx.profiles_with_doubled(dx.synthetic_profiles())
BASE = PROFILES["baseline"]
ZERO = costs.CostProfile("zero", 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0)
TICK = 0.01


# --- helpers ------------------------------------------------------------------------------


def _flat(n: int = 120, price: float = 100.0) -> dict[str, np.ndarray]:
    return {key: np.full(n, price) for key in ("open", "high", "low", "close")}


def _set(arrays: dict, m: int, o: float, h: float, l: float, c: float) -> None:  # noqa: E741
    arrays["open"][m], arrays["high"][m], arrays["low"][m], arrays["close"][m] = o, h, l, c


def _close_bar(arrays: dict, bar: int, close: float) -> None:
    """Make every candle of 5m ``bar`` sit at ``close`` (a flat bar closing there)."""
    for m in range(5 * bar, 5 * bar + 5):
        _set(arrays, m, close, close, close, close)


def _candles(arrays: dict, symbol: str = "TESTUSDT") -> Candles:
    n = arrays["open"].size
    return Candles(symbol, np.arange(n, dtype=np.int64) * MINUTE, arrays["open"].copy(), arrays["high"].copy(), arrays["low"].copy(), arrays["close"].copy(), np.ones(n))


def _row(side: int = 1, entry: float = 100.0, r: float = 1.0, fill_index: int = 5, decision_index: int = 0, end_index: int = 65, atr: float = 1.0) -> np.void:
    stop = entry - side * r
    target = entry + side * 1.5 * r
    row = np.array(
        [("TESTUSDT", side, decision_index, (decision_index + 1) * 5 * MINUTE, fill_index, (fill_index + 1) * MINUTE, end_index, "time", fill_index + 120, False, entry, stop, target, r, atr, TICK, 2.5)],
        dtype=dx.ENTRY_DTYPE,
    )
    return row[0]


def _run(arrays: dict, params: str, row=None, atr: np.ndarray | None = None, latency: int = 0, profile=BASE) -> dx.ExitResult:
    candles = _candles(arrays)
    bars = aggregate(candles, "5m")
    atr = np.ones(bars.size) if atr is None else atr
    return dx.simulate_exit(candles, bars, atr, _row() if row is None else row, PARAMS[params], profile, latency)


def _mirror(arrays: dict, pivot: float = 100.0) -> dict:
    return {"open": 2 * pivot - arrays["open"], "high": 2 * pivot - arrays["low"], "low": 2 * pivot - arrays["high"], "close": 2 * pivot - arrays["close"]}


def _random_entries(seed: int, minutes: int = 4 * 1440, decisions: int = 120):
    pair, instrument = dx.synthetic_pair(f"R{seed}USDT", minutes, seed)
    rng = np.random.default_rng(seed + 99)
    valid = np.flatnonzero(~np.isnan(pair.atr))[:-13]
    chosen = np.sort(rng.choice(valid, size=min(decisions, valid.size), replace=False))
    end_ms = int(pair.candles.open_ms[-1]) + MINUTE
    tables = [dx.build_entry_table(pair.candles, pair.bars, pair.atr, chosen, side, instrument, end_ms, SPEC) for side in (1, -1)]
    entries = np.concatenate(tables)
    entries = entries[np.lexsort((entries["side"], entries["decision_ms"]))]
    return pair, instrument, entries


# --- spec ---------------------------------------------------------------------------------


def test_spec_is_hash_checked_and_carries_one_parameter_set_per_method(tmp_path) -> None:
    assert SPEC["version"] == "1.0.0" and set(SPEC["methods"]) == {"A", "B", "C"}
    assert PARAMS["A"].target_r == 1.5 and PARAMS["B"].target_r is None and PARAMS["C"].cap_r == 3.0
    tampered = tmp_path / "spec.json"
    tampered.write_text(dx.SPEC_PATH.read_text().replace('"stall_min_bars": 6', '"stall_min_bars": 7'))
    with pytest.raises(ValueError, match="hash mismatch"):
        dx.load_spec(tampered, dx.SPEC_HASH_PATH)


def test_power_calculation_matches_the_declared_minimums() -> None:
    power = SPEC["data_requirements"]["power"]
    diff = dx.required_trades(power["sd_paired_diff_r"], power["delta_diff_r"], power["alpha_one_sided"], power["power"], power["design_effect"])
    own = dx.required_trades(power["sd_trade_r"], power["delta_mean_r"], power["alpha_one_sided"], power["power"])
    assert diff == 1336 and own == 2993
    assert SPEC["data_requirements"]["min_trades"] >= max(diff, own)


# --- reuse: method A is the research_v2 fixed simulation ----------------------------------


@pytest.mark.parametrize("seed", [1, 2, 3])
def test_method_a_equals_fills_simulate_on_random_paths(seed: int) -> None:
    pair, instrument, entries = _random_entries(seed)
    assert entries.size > 20
    for row in entries:
        plan = PlanSpec(pair.candles.symbol, int(row["side"]), int(row["decision_index"]), float(row["entry"]), float(row["stop"]), float(row["target_a"]), None, 60 * MINUTE, 5 * MINUTE, entry_mode="limit_through", through=TICK)
        reference = simulate(pair.candles, pair.bars, plan, int(pair.candles.open_ms[-1]) + MINUTE)
        mine = dx.simulate_exit(pair.candles, pair.bars, pair.atr, row, PARAMS["A"], BASE)
        assert reference.fill_index == row["fill_index"]
        assert (mine.exit_index, mine.exit_price, mine.reason) == (reference.exit_index, reference.exit_price, reference.exit_reason)


# --- causality ----------------------------------------------------------------------------


def _favourable_scenario() -> dict:
    """Long 100, R 1: bar 1 closes at 101.2 (u = 1.2) without touching the 101.5 target."""
    arrays = _flat()
    for m in range(5, 10):
        _set(arrays, m, 100.5, 101.2, 100.4, 101.2)
    return arrays


def test_a_close_decision_acts_only_from_the_next_bar() -> None:
    arrays = _favourable_scenario()
    arrays["low"][9] = 100.05  # inside the decision candle: below break-even, above the stop
    breakeven = dx.breakeven_level(1, 100.0, BASE, TICK)
    _set(arrays, 10, 101.0, 101.0, breakeven, 100.9)  # first candle of bar 2 reaches break-even
    result = _run(arrays, "C")
    kinds = {(d.kind, d.reason) for d in result.decisions}
    assert ("stop", "breakeven") in kinds and ("target", "extend") in kinds
    assert all(d.bar == 1 and d.effective_index == 10 for d in result.decisions)
    assert result.exit_index == 10 and result.reason == "trailing" and result.exit_price == breakeven


def test_latency_of_one_bar_delays_the_effect_by_one_bar() -> None:
    arrays = _favourable_scenario()
    breakeven = dx.breakeven_level(1, 100.0, BASE, TICK)
    _set(arrays, 10, 101.0, 101.0, breakeven, 100.9)
    _close_bar(arrays, 2, 100.9)
    arrays["low"][10] = breakeven
    result = _run(arrays, "C", latency=1)
    assert all(d.effective_index == 15 for d in result.decisions if d.bar == 1)
    assert result.exit_index != 10  # break-even not yet in force on candle 10


@pytest.mark.parametrize("seed", [11, 12, 13, 14])
def test_permuting_future_candles_never_changes_past_decisions(seed: int) -> None:
    pair, instrument, entries = _random_entries(seed, decisions=60)
    rng = np.random.default_rng(seed)
    checked = 0
    for row in entries[:30]:
        for name in ("B", "C"):
            original = dx.simulate_exit(pair.candles, pair.bars, pair.atr, row, PARAMS[name], BASE)
            closes = sorted({d.bar for d in original.decisions})
            if not closes:
                continue
            cut_bar = closes[0]
            cut = int(pair.bars.last_minute[cut_bar])  # first candle after that close
            n = pair.candles.size
            order = np.concatenate([np.arange(cut), cut + rng.permutation(n - cut)])
            shuffled = Candles(pair.candles.symbol, pair.candles.open_ms, pair.candles.open[order], pair.candles.high[order], pair.candles.low[order], pair.candles.close[order], pair.candles.volume[order])
            bars = aggregate(shuffled, "5m")
            from scripts.research_v2 import indicators as ind

            atr = ind.atr(bars.high, bars.low, bars.close, 14)  # recomputed on the permuted series: no look-ahead in the ATR either
            replay = dx.simulate_exit(shuffled, bars, atr, row, PARAMS[name], BASE)
            key = lambda d: (d.bar, d.effective_index, d.kind, None if np.isnan(d.value) else d.value, d.reason)  # noqa: E731
            past = [key(d) for d in original.decisions if d.bar <= cut_bar]
            assert [key(d) for d in replay.decisions if d.bar <= cut_bar] == past
            span = cut - int(row["fill_index"])
            assert replay.stop_path[:span] == original.stop_path[:span]
            checked += 1
    assert checked > 0


# --- symmetry -----------------------------------------------------------------------------


@pytest.mark.parametrize("name", ["A", "B", "C"])
@pytest.mark.parametrize("seed", [21, 22])
def test_long_and_short_are_mirror_images(name: str, seed: int) -> None:
    pair, _, entries = _random_entries(seed, decisions=40)
    arrays = {"open": pair.candles.open, "high": pair.candles.high, "low": pair.candles.low, "close": pair.candles.close}
    pivot = 100.0
    mirrored = _mirror(arrays, pivot)
    mc = Candles(pair.candles.symbol, pair.candles.open_ms, mirrored["open"], mirrored["high"], mirrored["low"], mirrored["close"], pair.candles.volume)
    mbars = aggregate(mc, "5m")
    from scripts.research_v2 import indicators as ind

    matr = ind.atr(mbars.high, mbars.low, mbars.close, 14)
    np.testing.assert_allclose(matr[~np.isnan(matr)], pair.atr[~np.isnan(pair.atr)], rtol=1e-9)
    for row in entries[entries["side"] == 1][:20]:
        mrow = row.copy()
        mrow["side"] = -1
        mrow["entry"], mrow["stop"], mrow["target_a"] = 2 * pivot - row["entry"], 2 * pivot - row["stop"], 2 * pivot - row["target_a"]
        long_result = dx.simulate_exit(pair.candles, pair.bars, pair.atr, row, PARAMS[name], ZERO)
        short_result = dx.simulate_exit(mc, mbars, matr, mrow, PARAMS[name], ZERO)
        assert (short_result.exit_index, short_result.reason) == (long_result.exit_index, long_result.reason)
        assert short_result.exit_price == pytest.approx(2 * pivot - long_result.exit_price, abs=1e-9)
        assert [(d.bar, d.kind, d.reason) for d in short_result.decisions] == [(d.bar, d.kind, d.reason) for d in long_result.decisions]


# --- execution details --------------------------------------------------------------------


@pytest.mark.parametrize("name", ["A", "B", "C"])
def test_a_gap_through_the_stop_fills_at_the_open(name: str) -> None:
    arrays = _flat()
    _set(arrays, 7, 98.5, 98.7, 98.2, 98.4)  # opens 0.5 below the 99 stop
    result = _run(arrays, name)
    assert result.exit_index == 7 and result.reason == "stop" and result.exit_price == 98.5


@pytest.mark.parametrize("name", ["A", "C"])
def test_stop_wins_when_stop_and_target_share_a_minute(name: str) -> None:
    arrays = _flat()
    _set(arrays, 8, 100.0, 101.6, 98.9, 100.0)
    result = _run(arrays, name)
    assert result.reason == "stop" and result.exit_price == 99.0


def test_initial_target_is_not_credited_on_the_fill_candle() -> None:
    arrays = _flat()
    _set(arrays, 5, 100.0, 101.6, 100.0, 100.0)
    _set(arrays, 6, 100.0, 101.5, 100.0, 100.0)  # touch is enough for the initial (resting) target
    result = _run(arrays, "A")
    assert result.exit_index == 6 and result.reason == "target" and result.exit_price == 101.5


def test_a_moved_target_loses_priority_and_needs_a_trade_through() -> None:
    arrays = _favourable_scenario()
    result_targets = [d for d in _run(arrays, "C").decisions if d.kind == "target"]
    assert result_targets and result_targets[0].value == pytest.approx(102.2)
    _set(arrays, 10, 101.2, 102.2, 101.1, 101.6)  # touches the new 102.2 target (and the old 101.5) without trading through
    _set(arrays, 11, 101.6, 102.21, 101.5, 102.0)  # one tick through
    result = _run(arrays, "C")
    assert result.exit_index == 11 and result.reason == "target" and result.exit_price == pytest.approx(102.2)
    # With one bar of latency the old 101.5 target is still in force on candle 10.
    late = _run(arrays, "C", latency=1)
    assert late.exit_index == 10 and late.exit_price == 101.5


def test_invalidation_closes_at_the_next_open_only_with_expanding_atr() -> None:
    arrays = _flat()
    _close_bar(arrays, 1, 99.4)  # u = -0.6 at the close of bar 1
    _set(arrays, 10, 99.45, 99.5, 99.3, 99.4)
    atr = np.ones(24)
    atr[1] = 1.6
    result = _run(arrays, "C", atr=atr)
    assert result.reason == "invalidation" and result.exit_index == 10 and result.exit_price == 99.45
    atr[1] = 1.4
    assert _run(arrays, "C", atr=atr).reason != "invalidation"


def test_stall_exit_after_six_bars_without_progress() -> None:
    arrays = _flat(120, 100.1)
    result = _run(arrays, "C")
    assert result.reason == "stall" and result.exit_index == 35  # closes of bars 1..6, effect at bar 7
    assert _run(arrays, "A").reason == "time"


def test_giveback_tightens_to_half_current_atr_below_the_close() -> None:
    arrays = _flat()
    for m in range(5, 10):
        _set(arrays, m, 100.5, 101.4, 100.4, 101.4)
    _close_bar(arrays, 2, 101.45)
    _close_bar(arrays, 3, 100.9)  # u 0.9 <= MFE 1.45 - 0.5
    result = _run(arrays, "C")
    giveback = [d for d in result.decisions if d.reason == "giveback"]
    assert len(giveback) == 1 and giveback[0].bar == 3 and giveback[0].value == pytest.approx(100.4) and giveback[0].effective_index == 20


def test_b_trails_one_entry_atr_below_the_best_close_after_activation() -> None:
    arrays = _flat(120, 101.0)
    _close_bar(arrays, 1, 100.9)  # best 0.9 R: not active
    _close_bar(arrays, 2, 101.1)  # active: 101.1 - 1
    result = _run(arrays, "B")
    trail = [d for d in result.decisions if d.kind == "stop"]
    assert [(d.bar, round(d.value, 2), d.effective_index) for d in trail] == [(2, 100.1, 15)]
    assert result.reason == "time"  # no target for B


# --- costs --------------------------------------------------------------------------------


def test_a_stop_at_the_entry_price_loses_the_costs_and_the_break_even_stop_covers_them() -> None:
    at_entry = costs.settle(1, 100.0, 100.0, 2.5, "trailing", BASE)
    assert at_entry.gross_quote == 0 and at_entry.net_quote < 0
    for side in (1, -1):
        level = dx.breakeven_level(side, 100.0, BASE, TICK)
        assert side * (level - 100.0) > 0
        assert costs.settle(side, 100.0, level, 2.5, "trailing", BASE).net_quote >= 0
        assert costs.settle(side, 100.0, level - side * TICK, 2.5, "trailing", BASE).net_quote < 0


def test_market_exits_pay_taker_spread_and_slippage() -> None:
    row = _row()
    result = dx.ExitResult("C", 10, 11 * MINUTE, 99.5, "invalidation")
    record = np.array([dx.settle_exit(row, result, PROFILES, SPEC)], dtype=dx.OUTCOME_DTYPE)[0]
    expected = costs.settle(1, 100.0, 99.5, 2.5, "stop", BASE)
    assert record["net_base"] == pytest.approx(expected.net_quote)
    assert record["friction_base"] == pytest.approx(expected.spread_quote + expected.slippage_quote) and record["friction_base"] > 0


# --- stop never loosens (property) --------------------------------------------------------


@pytest.mark.parametrize("seed", [31, 32, 33, 34, 35])
@pytest.mark.parametrize("latency", [0, 1])
def test_the_stop_never_loosens_on_random_paths(seed: int, latency: int) -> None:
    pair, _, entries = _random_entries(seed, decisions=80)
    moved = 0
    for row in entries:
        for name in ("B", "C"):
            result = dx.simulate_exit(pair.candles, pair.bars, pair.atr, row, PARAMS[name], BASE, latency)
            path = np.array(result.stop_path)
            assert path[0] == row["stop"]
            steps = np.diff(path) * int(row["side"])
            assert np.all(steps >= 0)
            moved += int(np.any(steps > 0))
    assert moved > 0


# --- identical entries, risk and size; common diagnostic horizon; determinism --------------


def test_the_three_methods_share_entries_size_and_risk() -> None:
    pair, instrument, entries = _random_entries(41)
    outcomes = dx.run_methods(entries, {pair.candles.symbol: pair}, PROFILES, SPEC)
    a = outcomes["A"]
    for name in ("B", "C"):
        other = outcomes[name]
        for column in ("symbol", "side", "decision_ms", "fill_ms", "entry", "stop", "quantity", "notional", "risk_base", "risk_adverse", "risk_doubled"):
            np.testing.assert_array_equal(other[column], a[column])
    broken = dict(outcomes)
    broken["B"] = outcomes["B"].copy()
    broken["B"]["risk_base"] *= 1.01
    with pytest.raises(ValueError, match="same entries"):
        dx.paired_comparisons(broken, "baseline", SPEC)


def test_the_entry_table_admission_does_not_depend_on_the_exit() -> None:
    pair, instrument, _ = _random_entries(42)
    end_ms = int(pair.candles.open_ms[-1]) + MINUTE
    valid = np.flatnonzero(~np.isnan(pair.atr))[:-13]
    table = dx.build_entry_table(pair.candles, pair.bars, pair.atr, valid, 1, instrument, end_ms, SPEC)
    assert table.size > 10
    assert np.all(np.diff(table["decision_ms"]) >= 60 * MINUTE)  # filled plans reserve the pair for the whole horizon
    again = dx.build_entry_table(pair.candles, pair.bars, pair.atr, valid, 1, instrument, end_ms, SPEC)
    np.testing.assert_array_equal(table, again)


def test_diagnostics_use_a_common_120_minute_horizon_after_the_fill() -> None:
    pair, instrument, entries = _random_entries(43)
    inner = entries[~entries["diag_truncated"]]
    assert inner.size and np.all(inner["diag_end_index"] - inner["fill_index"] == 120)
    outcomes = dx.run_methods(entries, {pair.candles.symbol: pair}, PROFILES, SPEC)
    diag = dx.diagnostics(entries, outcomes["A"], {pair.candles.symbol: pair}, SPEC)
    assert diag["trades"] == entries.size and 0 <= diag["mfe_ge_1R_share"] <= diag["mfe_ge_0.5R_share"] <= 1
    assert diag["mfe_common_horizon_r_mean"] >= 0 and "never a realisable gain" in diag["note"]


def test_the_report_is_deterministic_and_insufficient_data_is_declared() -> None:
    pair, instrument, entries = _random_entries(44)
    pairs, instruments = {pair.candles.symbol: pair}, {pair.candles.symbol: instrument}
    first = json.dumps(dx.build_report(entries, pairs, dx.synthetic_profiles(), instruments, SPEC), sort_keys=True, default=float)
    second = json.dumps(dx.build_report(entries, pairs, dx.synthetic_profiles(), instruments, SPEC), sort_keys=True, default=float)
    assert first == second
    report = json.loads(first)
    assert report["verdict"]["verdict"] == "INSUFFICIENT_DATA"
    assert set(report["portfolio"]) == {"A", "B", "C"}
    assert {"baseline", "adverse", "doubled", "latency_1_bar", "diagnostics"} <= set(report)


# --- statistics ---------------------------------------------------------------------------


def test_holm_and_day_block_bootstrap() -> None:
    out = dx.holm({"B-A": 0.04, "C-A": 0.08}, 0.10)
    assert out["B-A"]["level"] == pytest.approx(0.05) and out["B-A"]["rejected"]
    assert out["C-A"]["level"] == pytest.approx(0.10) and out["C-A"]["rejected"] and out["C-A"]["p_holm"] == pytest.approx(0.08)
    out = dx.holm({"B-A": 0.06, "C-A": 0.04}, 0.10)
    assert out["C-A"]["rejected"] and out["B-A"]["rejected"] and out["C-A"]["p_holm"] == pytest.approx(0.08)
    out = dx.holm({"B-A": 0.07, "C-A": 0.09}, 0.10)  # 0.07 > 0.05: step-down stops, nothing rejected
    assert not out["B-A"]["rejected"] and not out["C-A"]["rejected"]
    values = np.array([1.0, 1.0, -1.0, -1.0, 2.0])
    days = np.array([0, 0, 1, 1, 2])
    means = dx.day_block_bootstrap(values, days, 500, 3)
    assert means.shape == (500,) and means.min() >= -1.0 and means.max() <= 2.0
    np.testing.assert_array_equal(means, dx.day_block_bootstrap(values, days, 500, 3))
    constant = dx.day_block_bootstrap(np.full(5, 0.3), days, 200, 1)
    np.testing.assert_allclose(constant, 0.3)


def test_real_data_run_is_refused_without_owner_authorization(capsys) -> None:
    assert dx.main(["run"]) == 2
    assert "authorization" in capsys.readouterr().err
