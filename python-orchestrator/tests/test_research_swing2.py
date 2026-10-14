"""Tests of the swing v2 screen: signals, ranking, entries, exits, sizing, portfolio, control, gates, claim, data."""

from __future__ import annotations

import json
import math
from array import array

import pytest

np = pytest.importorskip("numpy")

from app.backtesting.research import exploration as ex  # noqa: E402
from app.backtesting.research import swing as sw  # noqa: E402
from scripts.research_swing2 import data as dt  # noqa: E402
from scripts.research_swing2 import engine as en  # noqa: E402
from scripts.research_swing2 import screen as sc  # noqa: E402
from scripts.research_swing2 import stats as st  # noqa: E402

DAY = en.DAY
MINUTE = en.MINUTE
BAR = en.BAR_MINUTES
START = 1_672_531_200_000  # 2023-01-01
BARS = 270
DECISION = 255  # a bar index with a complete 250-bar window
INSTRUMENT = {"symbol": "AAAUSDT", "tick_size": 0.01, "quantity_step": 0.001, "min_quantity": 0.001, "min_notional": 5.0, "contract_size": 1.0}
PROFILES = {
    "baseline": {"entry_spread_rate": 0, "stop_spread_rate": 0.0002, "target_spread_rate": 0, "entry_slippage_rate": 0,
                 "stop_slippage_rate": 0.0005, "target_slippage_rate": 0, "funding_provision_rate": 0.0003},
    "adverse": {"entry_spread_rate": 0, "stop_spread_rate": 0.001, "target_spread_rate": 0, "entry_slippage_rate": 0,
                "stop_slippage_rate": 0.001, "target_slippage_rate": 0, "funding_provision_rate": 0.001},
}


def frame_for(symbol, close=100.0, ema=90.0, atr=1.0, high=None, low=None, dh=99.0, dl=101.0, overrides=None):
    columns = {name: [None] * BARS for name in sw.COLUMNS}
    for i in range(BARS):
        columns["close"][i] = close
        columns["high"][i] = high if high is not None else close + 0.5
        columns["low"][i] = low if low is not None else close - 0.5
        columns["ema200"][i] = ema
        columns["atr"][i] = atr
        for n in sw.DONCHIAN_BARS:
            columns[f"dh{n}"][i] = 1e9
            columns[f"dl{n}"][i] = -1e9
    for (name, index), value in (overrides or {}).items():
        columns[name][index] = value
    return sw.Frame(symbol, en.FOUR_HOURS, START, columns)


def candles_for(symbol, price=100.0, edits=None):
    count = BARS * BAR + 20 * DAY // MINUTE
    o, h, l, c = ([price] * count for _ in range(4))
    for index, (open_, high, low, close) in (edits or {}).items():
        o[index], h[index], l[index], c[index] = open_, high, low, close
    return ex.CandleSeries(symbol, START, *(array("d", values) for values in (o, h, l, c)))


def market_for(frames, candles):
    return en.Market(frames, candles, {s: sw.extremes(c) for s, c in candles.items()}, {},
                     {s: {**INSTRUMENT, "symbol": s} for s in frames})


def first_minute(bar):
    """Candle index of the first minute after the close of 4h bar ``bar``."""
    return (bar + 1) * BAR


WINDOW = (START, START + 400 * DAY)


# --------------------------------------------------------------------------- signals
def test_strength_requires_regime_and_breakout():
    frame = frame_for("AAAUSDT", close=102.0, ema=90.0, atr=2.0, overrides={("dh30", DECISION): 101.0})
    assert en.strength_at(frame, DECISION, "long", 30) == pytest.approx(0.5)
    assert en.strength_at(frame, DECISION, "short", 30) is None  # above the EMA200
    below = frame_for("AAAUSDT", close=80.0, ema=90.0, atr=2.0, overrides={("dl60", DECISION): 81.0})
    assert en.strength_at(below, DECISION, "short", 60) == pytest.approx(0.5)
    equal = frame_for("AAAUSDT", close=101.0, overrides={("dh30", DECISION): 101.0})
    assert en.strength_at(equal, DECISION, "long", 30) is None  # a breakout is strict


def test_signals_filter_by_strength_and_rank_by_decreasing_strength():
    frames = {
        "AAAUSDT": frame_for("AAAUSDT", close=102.0, overrides={("dh30", DECISION): 101.8}),  # 0.2 ATR
        "BBBUSDT": frame_for("BBBUSDT", close=102.0, overrides={("dh30", DECISION): 101.0}),  # 1.0 ATR
        "CCCUSDT": frame_for("CCCUSDT", close=102.0, overrides={("dh30", DECISION): 101.5}),  # 0.5 ATR
    }
    market = market_for(frames, {s: candles_for(s) for s in frames})
    config = en.Config(30, 0.0, en.Geometry("long", "L", 2.0, "T3", 7))
    assert [row[2] for row in en.signals(market, config, WINDOW)] == ["BBBUSDT", "CCCUSDT", "AAAUSDT"]
    strict = en.Config(30, 0.25, en.Geometry("long", "L", 2.0, "T3", 7))
    assert [row[2] for row in en.signals(market, strict, WINDOW)] == ["BBBUSDT", "CCCUSDT"]


def test_grid_has_144_configurations_per_side_and_ids_round_trip():
    configs = en.grid()
    assert len(configs) == 288 and sum(c.side == "long" for c in configs) == 144
    assert len({c.id for c in configs}) == 288
    for config in configs[:20]:
        assert en.Config.parse(config.id) == config


# --------------------------------------------------------------------------- entries
def test_limit_entry_fills_on_the_first_bar_trading_through_the_close():
    first = first_minute(DECISION)
    frame = frame_for("AAAUSDT", close=100.0, overrides={("dh30", DECISION): 99.0})
    candles = candles_for("AAAUSDT", 100.5, {first + i: (100.5, 100.6, 100.4, 100.5) for i in range(5)} | {first + 5: (100.5, 100.5, 99.99, 100.2)})
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "T3", 7), WINDOW[1])
    assert path.filled and path.fill_index == first + 5 and path.entry == 100.0
    assert path.initial_stop == 98.0 and path.target == 106.0


def test_limit_entry_expires_after_sixty_minutes():
    frame = frame_for("AAAUSDT", close=100.0)
    candles = candles_for("AAAUSDT", 101.0)
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "T3", 7), WINDOW[1])
    assert not path.filled and path.release_ms == frame.close_ms(DECISION) + 60 * MINUTE


def test_stop_limit_fills_at_the_worse_of_trigger_and_open_within_the_limit():
    first = first_minute(DECISION)
    frame = frame_for("AAAUSDT", close=100.0, high=100.5, atr=2.0)  # trigger 100.5, limit 101.0
    edits = {first: (100.2, 100.4, 100.1, 100.3), first + 1: (100.7, 100.9, 100.6, 100.8)}
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 100.8, edits)})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "C", 2.0, "T3", 7), WINDOW[1])
    assert path.filled and path.fill_index == first + 1 and path.entry == 100.7


def test_stop_limit_gapping_beyond_the_limit_rests_at_the_limit():
    first = first_minute(DECISION)
    frame = frame_for("AAAUSDT", close=100.0, high=100.5, atr=2.0)  # limit 101.0
    edits = {first: (101.5, 101.8, 101.2, 101.6), first + 1: (101.6, 101.7, 101.3, 101.4), first + 2: (101.4, 101.5, 100.9, 101.0)}
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 101.2, edits)})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "C", 2.0, "T3", 7), WINDOW[1])
    assert path.filled and path.fill_index == first + 2 and path.entry == 101.0


def test_short_stop_limit_triggers_below_the_decision_low():
    first = first_minute(DECISION)
    frame = frame_for("AAAUSDT", close=80.0, ema=90.0, low=79.5, atr=2.0)
    edits = {first: (79.8, 79.9, 79.6, 79.7), first + 1: (79.4, 79.5, 79.0, 79.1)}
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 79.1, edits)})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("short", "C", 2.0, "T3", 7), WINDOW[1])
    assert path.filled and path.entry == 79.4 and path.initial_stop == 83.4


def test_stop_limit_never_triggered_is_unfilled_after_one_bar():
    frame = frame_for("AAAUSDT", close=100.0, high=100.5)
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 100.0)})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "C", 2.0, "T3", 7), WINDOW[1])
    assert not path.filled and path.release_ms == frame.close_ms(DECISION) + en.FOUR_HOURS


# --------------------------------------------------------------------------- exits
def test_fixed_target_and_stop_priority_on_a_bar_reaching_both():
    first = first_minute(DECISION)
    frame = frame_for("AAAUSDT", close=100.0)
    edits = {first: (100.0, 100.1, 99.9, 100.0), first + 30: (100.0, 106.5, 97.0, 100.0)}
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 100.0, edits)})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "T3", 7), WINDOW[1])
    assert path.reason == "stop" and path.exit_price == 98.0 and path.ambiguous
    edits[first + 30] = (100.0, 106.5, 99.0, 106.0)
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 100.0, edits)})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "T3", 7), WINDOW[1])
    assert path.reason == "target" and path.exit_price == 106.0


def test_time_exit_at_the_prior_close_and_the_phase_end_clips_the_deadline():
    first = first_minute(DECISION)
    frame = frame_for("AAAUSDT", close=100.0)
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 100.0, {first: (100.0, 100.1, 99.9, 100.0)})})
    end = frame.close_ms(DECISION) + 2 * DAY
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "T3", 7), end)
    assert path.reason == "time" and path.exit_ms == end and path.exit_price == 100.0


def test_chandelier_ratchets_up_and_exits_as_trailing():
    first = first_minute(DECISION)
    edits = {first: (100.0, 100.1, 99.9, 100.0)}
    for i in range(first + 1, first + BAR):  # the first 4h bar after the fill climbs to 110
        edits[i] = (110.0, 110.0, 109.9, 110.0)
    edits[first + BAR + 10] = (110.0, 110.0, 107.0, 107.5)  # falls through 110 - 2 × 1 = 108
    frame = frame_for("AAAUSDT", close=100.0, atr=1.0)
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 110.0, edits)})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "CH", 7), WINDOW[1])
    assert path.reason == "trailing" and path.exit_price == 108.0 and path.exit_index == first + BAR + 10


def test_chandelier_never_moves_the_stop_backwards():
    first = first_minute(DECISION)
    edits = {first: (100.0, 100.1, 99.9, 100.0)}
    for i in range(first + 1, first + BAR):
        edits[i] = (104.0, 104.0, 103.9, 104.0)
    frame = frame_for("AAAUSDT", close=100.0, atr=1.0, overrides={("atr", DECISION + 2): 5.0})  # ATR widens later
    edits[first + BAR + 5] = (104.0, 104.0, 101.5, 101.6)  # through 104 - 2 = 102, not through 104 - 10
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles_for("AAAUSDT", 104.0, edits)})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "CH", 7), WINDOW[1])
    assert path.reason == "trailing" and path.exit_price == 102.0


# --------------------------------------------------------------------------- sizing and portfolio
def test_sizing_targets_one_percent_risk_and_caps_one_position_at_a_quarter_of_equity():
    geometry = en.Geometry("long", "L", 2.0, "T3", 7)
    quantity = en.size(100.0, 90.0, geometry, PROFILES["baseline"], INSTRUMENT, 10_000.0)
    loss = quantity * en.planned_loss(100.0, 90.0, geometry, PROFILES["baseline"], 1.0)
    assert loss == pytest.approx(100.0, abs=0.02)
    tight = en.size(100.0, 99.5, geometry, PROFILES["baseline"], INSTRUMENT, 10_000.0)
    assert tight * 100.0 == pytest.approx(2_500.0, abs=0.2)
    assert en.size(100.0, 90.0, geometry, PROFILES["baseline"], {**INSTRUMENT, "min_notional": 1_000.0}, 10_000.0) == 0.0


def _breakout_market(symbols):
    frames = {s: frame_for(s, close=100.0, overrides={("dh30", DECISION): 99.0 - k * 0.1}) for k, s in enumerate(symbols)}
    edits = {first_minute(DECISION): (100.0, 100.1, 99.9, 100.0)}
    return market_for(frames, {s: candles_for(s, 100.0, edits) for s in symbols})


def test_portfolio_admits_four_positions_by_strength_and_one_per_symbol():
    symbols = ["AAAUSDT", "BBBUSDT", "CCCUSDT", "DDDUSDT", "EEEUSDT", "FFFUSDT"]
    market = _breakout_market(symbols)
    config = en.Config(30, 0.0, en.Geometry("long", "L", 2.0, "T3", 7))
    rows = en.signals(market, config, WINDOW)
    run = en.run_stream(market, rows, {"long": config.geometry}, WINDOW, PROFILES)
    assert len(run.trades) == 4 and run.rejections["portfolio_full"] == 2
    assert sorted(t.symbol for t in run.trades) == ["CCCUSDT", "DDDUSDT", "EEEUSDT", "FFFUSDT"]  # the four strongest
    trade = run.trades[0]
    assert trade.quantity * trade.entry == pytest.approx(2_500.0, abs=0.2)  # 1 % risk would exceed a quarter of the equity
    assert trade.risk["baseline"] < 100.0 and trade.r("baseline") == pytest.approx(trade.net["baseline"] / trade.risk["baseline"])


def test_control_matches_counts_and_regime_and_is_deterministic():
    symbols = ["AAAUSDT", "BBBUSDT"]
    market = _breakout_market(symbols)
    market.frames["BBBUSDT"].columns["ema200"][DECISION + 3] = 200.0  # one bar outside the long regime
    config = en.Config(30, 0.0, en.Geometry("long", "L", 2.0, "T3", 7))
    rows = en.control_signals(market, config, WINDOW, seed=3)
    assert sorted(r[2] for r in rows) == symbols  # one draw per pair, as the configuration
    assert rows == en.control_signals(market, config, WINDOW, seed=3)
    assert all(r[3] != DECISION + 3 or r[2] != "BBBUSDT" for r in rows)
    draws = {en.control_signals(market, config, WINDOW, seed=s)[0][3] for s in range(20)}
    assert len(draws) > 1


# --------------------------------------------------------------------------- gates and selection
def _summary(trades=200, mean=0.1, pf=1.3, dd=5.0, years=None, pairs=6):
    return {"trades": trades, "pairs_with_trades": pairs, "mean_net_r": mean, "profit_factor": pf, "drawdown_r": dd,
            "by_year": years or {"2023": {"trades": 100, "net": 10.0}, "2024": {"trades": 100, "net": 10.0}}}


def test_gates_restate_r1_r2_with_drawdown_in_r_and_the_year_rules():
    assert st.gate_reasons(_summary(), "training", "baseline", ("2023", "2024")) == []
    assert "profit_factor_below_threshold" in st.gate_reasons(_summary(pf=1.1), "training", "baseline", ("2023", "2024"))
    assert st.gate_reasons(_summary(pf=1.1), "training", "adverse", ("2023", "2024")) == []
    assert "drawdown_exceeded" in st.gate_reasons(_summary(dd=20.5), "training", "baseline", ("2023", "2024"))
    thin = {"2023": {"trades": 20, "net": -5.0}, "2024": {"trades": 100, "net": 10.0}, "2025": {"trades": 80, "net": 3.0}}
    assert "insufficient_year_trades:2023" in st.gate_reasons(_summary(years=thin), "training", "baseline", ("2023", "2024"))
    assert st.gate_reasons(_summary(years=thin), "validation", "baseline", ("2023", "2024", "2025")) == []  # thin year reported, not blocking
    thin["2023"]["trades"] = 30
    assert st.gate_reasons(_summary(years=thin), "validation", "baseline", ("2023", "2024", "2025")) == ["nonpositive_year:2023"]


def test_control_position_and_benjamini_hochberg():
    position = st.control_position(0.3, [0.0, 0.1, 0.2, 0.4])
    assert position["surplus"] == pytest.approx(0.15) and position["percentile"] == 0.75 and position["p_empirical"] == pytest.approx(2 / 5)
    q = st.benjamini_hochberg({"a": 0.01, "b": 0.04, "c": 0.5})
    assert q["a"] == pytest.approx(0.03) and q["b"] == pytest.approx(0.06) and q["c"] == pytest.approx(0.5)


def _row(id_, side, surplus, p=0.01, gates=True):
    position = {"surplus": surplus, "p_empirical": p}
    return {"id": id_, "side": side, "passes_gates": gates, "control": {"baseline": position, "adverse": position}}


def test_selection_keeps_at_most_three_per_side_by_surplus():
    rows = [_row(f"L{i}", "long", 0.1 * i) for i in range(1, 6)] + [_row("S1", "short", 0.2), _row("S2", "short", -0.1),
                                                                   _row("S3", "short", 0.3, p=0.2), _row("S4", "short", 0.5, gates=False)]
    assert st.select(rows) == ["L5", "L4", "L3", "S1"]


def test_verdict_decides_only_selected_rows():
    def row(id_, ok):
        summary = {"gate_reasons": [], "mean_net_r": 0.1, "trades": 60}
        return {"id": id_, "passes_gates": ok, "baseline": summary, "adverse": summary,
                "control": {"baseline": {"surplus": 0.1}, "adverse": {"surplus": 0.1}}}
    out = sc.verdict({"phase": "validation", "configurations": [row("A", True), row("B", True), row("C", False)]}, ["A", "C"])
    assert out["validated"] == ["A"] and out["status"] == "validated"
    assert [r["selected"] for r in out["rows"]] == [True, False, True]


# --------------------------------------------------------------------------- holdout claim
def test_claim_is_exclusive_and_needs_a_validated_configuration(tmp_path):
    verdict = tmp_path / "verdict-validation.json"
    verdict.write_text(json.dumps({"phase": "validation", "validated": []}))
    with pytest.raises(SystemExit):
        sc.claim(tmp_path, verdict)
    verdict.write_text(json.dumps({"phase": "validation", "validated": ["X"]}))
    out = tmp_path / "screen-holdout.json"
    with pytest.raises(SystemExit):
        sc._holdout_guard(tmp_path, out, ["X"])
    sc.claim(tmp_path, verdict)
    with pytest.raises(FileExistsError):
        sc.claim(tmp_path, verdict)
    sc._holdout_guard(tmp_path, out, ["X"])
    with pytest.raises(SystemExit):
        sc._holdout_guard(tmp_path, out, ["Y"])
    out.write_text("{}")
    with pytest.raises(SystemExit):
        sc._holdout_guard(tmp_path, out, ["X"])


# --------------------------------------------------------------------------- data
def _kline_file(path, start_s, count, price=1.0, skip=()):
    ts = np.array([start_s + 60 * i for i in range(count) if i not in skip], dtype=np.int64)
    values = np.full(ts.size, price)
    np.savez(path, ts_s=ts, open=values, high=values + 0.1, low=values - 0.1, close=values)


def test_assemble_candles_starts_at_midnight_fills_gaps_and_cuts(tmp_path):
    start_s = START // 1000 - 600  # ten minutes before midnight
    _kline_file(tmp_path / "A-1m-2023-01.npz", start_s, 3 * 1440, skip={700})
    series, receipt = dt.assemble_candles([tmp_path / "A-1m-2023-01.npz"], "AUSDT", START + DAY)
    assert series.start_ms == START and series.count == 1440
    assert receipt["missing_minutes_filled"] == 1 and receipt["dropped_before_first_midnight"] == 10


def test_load_market_truncates_at_the_cut(tmp_path):
    root = tmp_path / "root"
    (root / "candles").mkdir(parents=True)
    (root / "features").mkdir()
    count = 2 * DAY // MINUTE
    series = ex.CandleSeries("AUSDT", START, *(array("d", [1.0] * count) for _ in range(4)))
    series.write(root / "candles" / "AUSDT.candles.bin", {})
    frame = sw.build_frame("AUSDT", sw.aggregate(series, en.FOUR_HOURS))
    (root / "features" / "AUSDT.frame.json").write_text(json.dumps(frame.to_json()))
    (root / "funding.json").write_text(json.dumps({"AUSDT": [[START + 8 * 3_600_000, 0.0001, None], [START + DAY + 1, 0.0001, None]]}))
    (root / "instrument-assumptions.json").write_text(json.dumps({"symbols": [{**INSTRUMENT, "symbol": "AUSDT"}]}))
    (root / "cost-assumptions.json").write_text(json.dumps({"profiles": PROFILES}))
    market, _ = dt.load_market(root, ("AUSDT",), START + DAY)
    assert market.candles["AUSDT"].count == 1440 and market.frames["AUSDT"].count == 6
    assert list(market.funding["AUSDT"].timestamps) == [START + 8 * 3_600_000]


def test_funding_converter_sorts_and_dedupes():
    pytest.importorskip("pandas")
    from scripts.research_exec.acquire import convert_fundingrate

    raw = b"calc_time,funding_interval_hours,last_funding_rate\n1700028800000,8,0.0002\n1700000000000,8,0.0001\n1700028800000,8,0.0003\n"
    arrays, diag = convert_fundingrate(raw)
    assert arrays["ts_ms"].tolist() == [1700000000000, 1700028800000]
    assert arrays["rate"].tolist() == [0.0001, 0.0003] and diag["duplicates"] == 1


def test_phase_windows_never_cross_the_cuts():
    training, validation, holdout = (sc.PHASES[p] for p in ("training", "validation", "holdout"))
    assert training[0][1] <= training[1] and validation[0][1] == dt.VALIDATION_CUT_MS
    assert holdout[0] == (dt.VALIDATION_CUT_MS, dt.HOLDOUT_END_MS)
    assert math.isclose(dt.HOLDOUT_END_MS, 1_791_525_600_000)


def test_posthoc_claim_needs_a_frozen_preregistration(tmp_path):
    prereg = tmp_path / "PREREGISTRATION-x.md"
    prereg.write_text("body")
    with pytest.raises(SystemExit):
        sc.claim(tmp_path, posthoc=["X"], prereg=prereg)  # not frozen: no .sha256 next to it
    prereg.with_suffix(".sha256").write_text("abc123 2026-10-14T00:00:00Z\n")
    payload = sc.claim(tmp_path, posthoc=["X"], prereg=prereg)
    assert payload["status"] == "posthoc_indicative_not_validated" and payload["prereg_sha256_file"] == "abc123"
    sc._holdout_guard(tmp_path, tmp_path / "screen-holdout.json", ["X"])
    with pytest.raises(FileExistsError):
        sc.claim(tmp_path, posthoc=["X"], prereg=prereg)


def test_summary_splits_by_exit_month():
    trade = en.Trade("AAAUSDT", "long", START, START + 60_000, START + 40 * DAY, 100.0, 98.0, 106.0, "target", False,
                     1.0, 0.5, {"baseline": 6.0}, {"baseline": 2.0}, {"baseline": 0.0})
    summary = st.summarize([trade], "baseline")
    assert summary["by_month"] == {"2023-02": {"trades": 1, "net": 6.0, "net_r": 3.0}}


def test_an_entry_resting_past_the_end_of_the_data_is_scanned_up_to_the_last_candle():
    first = first_minute(DECISION)
    frame = frame_for("AAAUSDT", close=100.0, high=100.5, atr=2.0)
    count = first + 120  # the data end two hours into the four-hour stop-limit window
    candles = ex.CandleSeries("AAAUSDT", START, *(array("d", [100.0] * count) for _ in range(4)))
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles})
    end = START + count * MINUTE
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "C", 2.0, "T3", 14), end)
    assert not path.filled and path.deadline_ms == end


def test_monthly_universe_gates_only_new_entries_and_the_control_draws():
    symbols = ["AAAUSDT", "BBBUSDT"]
    market = _breakout_market(symbols)
    month = en.month_of_ms(market.frames["AAAUSDT"].close_ms(DECISION))
    market.eligible = {"AAAUSDT": frozenset({month})}
    config = en.Config(30, 0.0, en.Geometry("long", "L", 2.0, "T3", 7))
    assert [row[2] for row in en.signals(market, config, WINDOW)] == ["AAAUSDT"]
    regime = en.regime_bars(market, "long", WINDOW)
    assert regime["BBBUSDT"] == [] and all(en.month_of_ms(market.frames["AAAUSDT"].close_ms(i)) == month for i in regime["AAAUSDT"])


def test_a_position_on_a_delisted_instrument_settles_at_the_last_hour_mean():
    first = first_minute(DECISION)
    frame = frame_for("AAAUSDT", close=100.0)
    count = first + 3 * BAR  # the data stop twelve hours after the decision
    closes = [100.0] * (count - 60) + [101.0] * 30 + [102.0] * 30
    candles = ex.CandleSeries("AAAUSDT", START, array("d", closes), array("d", closes), array("d", closes), array("d", closes))
    market = market_for({"AAAUSDT": frame}, {"AAAUSDT": candles})
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "T3", 14), WINDOW[1])
    assert path.filled and path.reason == "delisting" and path.event == "delisting" and path.exit_ms == START + count * MINUTE
    assert path.exit_price == pytest.approx(101.5)


def test_cadence_known_at_entry_and_missing_settlements_are_imputed_against_the_position():
    market = _breakout_market(["AAAUSDT"])
    hour = 3_600_000
    times = [START + k * 8 * hour for k in range(10)] + [START + 80 * hour + k * 4 * hour for k in range(5)]
    times.remove(START + 88 * hour)  # one 4 h settlement missing
    market.funding = {"AAAUSDT": ex.FundingSeries.from_events([(t, -0.0001 if t < START + 80 * hour else 0.0002, None) for t in times])}
    assert market.cadence_hours("AAAUSDT", START + 50 * hour) == 8
    assert market.cadence_hours("AAAUSDT", START + 87 * hour) == 4
    missing = en.missing_settlements(market, "AAAUSDT", START + 84 * hour, START + 95 * hour, 4)
    assert [ts for ts, _ in missing] == [START + 88 * hour] and missing[0][1] == pytest.approx(0.0002)


def test_conservative_scenario_books_event_trades_at_no_better_than_minus_one_risk():
    trade = en.Trade("AAAUSDT", "long", START, START, START + DAY, 100.0, 98.0, 99.0, "delisting", False, 1.0, 0.5,
                     {"baseline": 3.0}, {"baseline": 2.0}, {"baseline": 0.0}, "delisting", {"baseline": 2.5}, 0)
    assert trade.net_conservative("baseline") == -2.5 and trade.r_conservative("baseline") == -1.0
    plain = en.Trade("AAAUSDT", "long", START, START, START + DAY, 100.0, 98.0, 106.0, "target", False, 1.0, 0.5,
                     {"baseline": 6.0}, {"baseline": 2.0}, {"baseline": 0.0}, None, {"baseline": 2.5}, 0)
    assert plain.net_conservative("baseline") == 6.0


def test_okx_decisions_stop_fourteen_days_before_the_end_of_the_data():
    (start, last_decision), end, _ = sc.PHASES["okx"]
    assert end - last_decision == 14 * DAY and last_decision == 1_790_611_200_000


def test_okx_phase_requires_200_trades_positive_net_and_p_in_the_verdict():
    assert "insufficient_trades" in st.gate_reasons(_summary(trades=150), "okx", "baseline", ("2023",))
    assert "nonpositive_net_pnl" in st.gate_reasons({**_summary(trades=250), "net_quote": -1.0}, "okx", "baseline", ())
    summary = {"gate_reasons": [], "mean_net_r": 0.1, "trades": 250}
    row = {"id": "A", "passes_gates": True, "baseline": summary, "adverse": summary,
           "control": {"baseline": {"surplus": 0.1, "p_empirical": 0.2}, "adverse": {"surplus": 0.1, "p_empirical": 0.05}}}
    out = sc.verdict({"phase": "okx", "configurations": [row]}, ["A"])
    assert out["status"] == "no_eligible_candidate" and out["rows"][0]["checks"]["p_baseline"] is False
    assert out["rows"][0]["checks"]["p_adverse_conservative"] is False  # the conservative scenarios are required too


def test_a_data_gap_blocks_entries_during_the_warm_up_and_an_open_position_is_carried_across_it():
    symbols = ["AAAUSDT"]
    market = _breakout_market(symbols)
    decision_ms = market.frames["AAAUSDT"].close_ms(DECISION)
    market.gaps = {"AAAUSDT": [(decision_ms - 10 * DAY, decision_ms - 9 * DAY)]}
    config = en.Config(30, 0.0, en.Geometry("long", "L", 2.0, "T3", 14))
    assert en.signals(market, config, WINDOW) == []
    market.gaps = {"AAAUSDT": [(decision_ms + 2 * DAY, decision_ms + 3 * DAY)]}
    assert [row[2] for row in en.signals(market, config, WINDOW)] == ["AAAUSDT"]
    path = en.simulate_path(market, "AAAUSDT", DECISION, config.geometry, WINDOW[1])
    assert path.reason == "time" and path.event == "interruption" and path.exit_ms == decision_ms + 14 * DAY


def test_a_deadline_inside_an_interruption_moves_to_the_first_bar_after_it():
    market = _breakout_market(["AAAUSDT"])
    decision_ms = market.frames["AAAUSDT"].close_ms(DECISION)
    market.gaps = {"AAAUSDT": [(decision_ms + 13 * DAY, decision_ms + 15 * DAY)]}
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "T3", 14), WINDOW[1])
    assert path.exit_ms == decision_ms + 15 * DAY + MINUTE and path.event == "interruption"
    market.paths.clear()
    end = decision_ms + 14 * DAY + 60 * MINUTE  # the phase ends inside the interruption: censored
    path = en.simulate_path(market, "AAAUSDT", DECISION, en.Geometry("long", "L", 2.0, "T3", 14), end)
    assert path.reason == "censored" and path.event == "censored" and path.exit_ms == end


def test_assemble_candles_records_gaps_longer_than_an_hour(tmp_path):
    _kline_file(tmp_path / "A-1m.npz", START // 1000, 1440, skip=set(range(100, 200)) | {500})
    _, receipt = dt.assemble_candles([tmp_path / "A-1m.npz"], "AUSDT", START + DAY)
    assert receipt["gaps"] == [[START + 100 * MINUTE, START + 200 * MINUTE]] and receipt["missing_minutes_filled"] == 101


def _okx_universe_inputs(extra_future_volume: float = 0.0):
    from scripts.research_swing2 import okx_audit as oa

    month_start = oa._month_start_ms("2024-03") // 86_400_000
    audits, volumes = {}, {}
    for k, name in enumerate(["AAA-USDT-SWAP", "BBB-USDT-SWAP", "CCC-USDT-SWAP"]):
        audits[name] = {"segments": [{"series": name, "start_s": (month_start - 200) * 86_400, "end_s": (month_start + 60) * 86_400}]}
        days = {str(d): [1_000.0 * (k + 1), "rest_quote", 1440] for d in range(month_start - 40, month_start)}
        for d in range(month_start, month_start + 40):  # March and later: must not change the March ranking
            days[str(d)] = [1_000.0 * (3 - k) + extra_future_volume * (k == 0), "rest_quote", 1440]
        volumes[name] = days
    return oa, audits, volumes


def test_okx_monthly_universe_uses_no_data_from_the_month_or_later():
    oa, audits, volumes = _okx_universe_inputs()
    march = oa._universe(audits, volumes, True, None)["2024-03"]
    assert march["universe"][:3] == ["CCC-USDT-SWAP", "BBB-USDT-SWAP", "AAA-USDT-SWAP"]
    oa, audits, volumes = _okx_universe_inputs(extra_future_volume=1e9)
    assert oa._universe(audits, volumes, True, None)["2024-03"]["universe"] == march["universe"]
    assert oa._universe(audits, volumes, True, None)["2024-04"]["universe"][0] == "AAA-USDT-SWAP"


def test_okx_a_segment_starting_less_than_42_days_before_the_month_is_not_eligible():
    oa, audits, volumes = _okx_universe_inputs()
    month_start = oa._month_start_ms("2024-03") // 86_400_000
    audits["CCC-USDT-SWAP"]["segments"][0]["start_s"] = (month_start - 30) * 86_400
    assert "CCC-USDT-SWAP" not in oa._universe(audits, volumes, True, None)["2024-03"]["universe"]


def test_okx_contract_change_splits_a_segment():
    from scripts.research_swing2 import okx_audit as oa

    days = np.repeat(np.arange(10, 20), 3)
    data = {"base_volume": np.where(days < 15, 10.0, 100.0), "contracts": np.ones(days.size)}
    assert oa._split_on_contract_changes(data, [(0, days.size)], days, None) == [(0, 15), (15, 30)]
