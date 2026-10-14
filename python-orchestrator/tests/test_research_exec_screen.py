"""Tests of the tick-data scalping screen: bars, features, rules, entry and exit policies."""

from __future__ import annotations

import numpy as np
import pytest

from scripts.research_exec import tick
from scripts.research_exec.capture import QUOTE_DTYPE, TRADE_DTYPE, Instrument
from scripts.research_exec.scalp_engine import EXIT_REASONS, Policy, SeriesData, run_policy
from scripts.research_exec.tick import build_bars, compute_features, rule_catalogue, signal_mask

SECOND = 1_000_000
T0 = 1_790_000_000
INSTRUMENT = Instrument("BTCUSDT", "BTC-USDT-SWAP", 0.01, 0.01, 0.01, fixed_tick=0.1)


def quotes_from(rows):
    return np.array([(ts, ts, bid, ask, bs, asz) for ts, bid, ask, bs, asz in rows], dtype=QUOTE_DTYPE)


def trades_from(rows):
    return np.array([(ts, ts, price, size, taker_buy, 1) for ts, price, size, taker_buy in rows], dtype=TRADE_DTYPE)


def test_bars_forward_fill_quotes_and_aggregate_prints():
    quotes = quotes_from([(T0 * SECOND - 500_000, 99.0, 99.1, 1.0, 2.0), ((T0 + 1) * SECOND + 200_000, 100.0, 100.1, 3.0, 1.0), ((T0 + 1) * SECOND + 900_000, 100.2, 100.3, 1.0, 1.0)])
    trades = trades_from([((T0 + 1) * SECOND + 300_000, 100.0, 2.0, 1), ((T0 + 1) * SECOND + 400_000, 99.9, 1.0, 0), ((T0 + 3) * SECOND, 101.0, 1.0, 1)])
    bars = build_bars(quotes, trades, T0, T0 + 4)
    assert bars["bid"].tolist()[:2] == [99.0, 100.2] and bars["ask"][2] == pytest.approx(100.3)  # second 0 seeded by the earlier quote, forward-filled after
    assert bars["quote_age"][0] == pytest.approx(1.5) and bars["quote_age"][3] == pytest.approx(2.1)
    assert bars["buy_vol"][1] == pytest.approx(2.0) and bars["sell_vol"][1] == pytest.approx(1.0) and bars["n"][1] == 2
    assert bars["high"][1] == pytest.approx(100.0) and bars["low"][1] == pytest.approx(99.9) and np.isnan(bars["high"][2])
    assert bars["last"][1] == pytest.approx(99.9) and bars["last"][3] == pytest.approx(101.0)


def _synthetic_series(seconds: int = 8000, seed: int = 3):
    rng = np.random.default_rng(seed)
    steps = rng.normal(0, 2e-5, size=seconds).cumsum()
    mid = 100.0 * np.exp(steps)
    q_rows, t_rows = [], []
    for s in range(seconds):
        bid = np.floor(mid[s] / 0.1) * 0.1
        q_rows.append(((T0 + s) * SECOND + 100_000, bid, bid + 0.1, 1.0 + rng.random(), 1.0 + rng.random()))
        for k in range(3):
            buy = int(rng.random() < 0.5)
            t_rows.append(((T0 + s) * SECOND + 200_000 + k * 100_000, bid + 0.1 if buy else bid, 0.5, buy))
    return quotes_from(q_rows), trades_from(t_rows)


def test_features_and_rules_have_the_bar_shape_and_fire_sometimes():
    quotes, trades = _synthetic_series()
    bars = build_bars(quotes, trades, T0, T0 + 8000)
    f = compute_features(bars, INSTRUMENT)
    assert f["r_10"].shape == (8000,) and np.isnan(f["r_10"][:9]).all() and np.isfinite(f["r_10"][10:]).all()
    assert (f["sigma_60"][60:] >= 0.1 / bars["mid"][60:] - 1e-12).all()
    assert np.isfinite(f["fi_60"][60:]).all() and (np.abs(f["fi_60"][60:]) <= 1).all()
    assert np.isnan(f["a_60"][:3599]).all() and np.isfinite(f["a_60"][3600:]).all()
    assert np.isfinite(f["dev"][5:]).all() and np.isfinite(f["band_dev"][5:]).all() and np.isfinite(f["qi_5"][5:]).all()
    fired = {rule: int(signal_mask(rule, f, 1).sum() + signal_mask(rule, f, -1).sum()) for rule in rule_catalogue(book=True)}
    assert len(fired) == 23 and len(rule_catalogue(book=False)) == 19
    assert all(count >= 0 for count in fired.values()) and sum(fired.values()) > 0
    with pytest.raises(ValueError):
        signal_mask("S9-W10", f, 1)


def _series_with_known_prints():
    # Flat book 100.0 / 100.1 every second; prints every second at 100.05 (no fill), plus a print at 99.9 at +31.5 s.
    q_rows = [((T0 + s) * SECOND + 50_000, 100.0, 100.1, 1.0, 1.0) for s in range(700)]
    t_rows = [((T0 + s) * SECOND + 500_000, 100.05, 0.1, 1) for s in range(700)]
    return q_rows, t_rows


def _make(q_rows, t_rows, seconds=700):
    quotes = quotes_from(sorted(q_rows))
    trades = trades_from(sorted(t_rows))
    bars = build_bars(quotes, trades, T0, T0 + seconds)
    features = compute_features(bars, INSTRUMENT)
    return SeriesData(0, bars, features, quotes, trades, INSTRUMENT, T0)


def test_entry_fill_blocks_and_x1_time_exit():
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 31) * SECOND + 500_000, 99.9, 1.0, 0))  # through the bid, but 30.5 s after the decision at the end of second 0: outside the 30 s TTL
    data = _make(q_rows, t_rows)
    policy = Policy("E1", "X1")
    trades, counters = run_policy(data, 1, np.array([0, 1, 2, 3, 4, 100]), policy)
    # Decision 0: order at 100.0, live at T0+1.25 s, TTL until T0+31 s → unfilled, blocks until second 32; 1..4 skipped; 100 unfilled.
    assert counters.decisions == 2 and counters.unfilled == 2 and trades.size == 0
    t_rows.append(((T0 + 5) * SECOND + 500_000, 99.9, 1.0, 0))  # inside the TTL of a decision at second 2
    data = _make(q_rows, t_rows)
    trades, counters = run_policy(data, 1, np.array([2]), policy)
    assert trades.size == 1 and trades["fill_ts"][0] == (T0 + 5) * SECOND + 500_000
    assert trades["exit_reason"][0] == EXIT_REASONS["time"] and trades["exit"][0] == pytest.approx(100.0)  # sell at the bid
    assert trades["gross_bp"][0] == pytest.approx(0.0) and trades["net_bp"][0] == pytest.approx(-7.0)
    assert trades["hold_s"][0] == pytest.approx(60.25)


def test_crossing_at_activation_is_cancelled_and_blocks_five_seconds():
    q_rows, t_rows = _series_with_known_prints()
    q_rows.append(((T0 + 1) * SECOND + 100_000, 99.8, 99.9, 1.0, 1.0))  # the book drops right after the decision at the end of second 0
    data = _make(q_rows, t_rows)
    trades, counters = run_policy(data, 1, np.array([0, 3, 7]), Policy("E1", "X1"))
    assert counters.crossed == 1 and counters.decisions == 2  # second 3 blocked, second 7 evaluated


def test_x3_stop_before_target_and_target_fill():
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))  # fills the bid at 100.0 (decision at second 0 → live at 1.25 s)
    t_rows.append(((T0 + 10) * SECOND + 100_000, 99.7, 1.0, 0))  # stop region (sigma floor two ticks → stop 99.8)
    t_rows.append(((T0 + 10) * SECOND + 200_000, 100.5, 1.0, 1))  # target region in the same second → the stop wins
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "X3"))
    assert trades.size == 1 and trades["exit_reason"][0] == EXIT_REASONS["stop"]
    assert trades["exit"][0] == pytest.approx(100.0)  # taker at the (flat) bid after the latency
    # Target only: a print above entry + 1.5 × 0.2 = 100.3 → the passive target at 100.3 fills.
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))
    t_rows.append(((T0 + 20) * SECOND + 100_000, 100.35, 1.0, 1))
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "X3"))
    assert trades["exit_reason"][0] == EXIT_REASONS["target"] and trades["exit"][0] == pytest.approx(100.3)
    assert trades["fees_bp"][0] == pytest.approx(4.0) and trades["net_bp"][0] == pytest.approx(30.0 - 4.0, abs=0.01)


def test_x4_passive_exit_then_fallback():
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))
    t_rows.append(((T0 + 70) * SECOND + 100_000, 100.15, 1.0, 1))  # above the ask 100.1 posted at fill + 60 s → passive exit
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "X4"))
    assert trades["exit_reason"][0] == EXIT_REASONS["passive"] and trades["exit"][0] == pytest.approx(100.1) and trades["fees_bp"][0] == pytest.approx(4.0)
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "X4"))
    assert trades["exit_reason"][0] == EXIT_REASONS["fallback"] and trades["exit"][0] == pytest.approx(100.0) and trades["fees_bp"][0] == pytest.approx(7.0)


def test_short_side_mirrors_the_long_side():
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 100.2, 1.0, 1))  # through the ask at 100.1
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, -1, np.array([0]), Policy("E1", "X1"))
    assert trades.size == 1 and trades["entry"][0] == pytest.approx(100.1) and trades["exit"][0] == pytest.approx(100.1)  # buy back at the ask
    assert trades["side"][0] == -1 and trades["net_bp"][0] == pytest.approx(-7.0)


def test_intraday_exits_y1_and_y3():
    q_rows = [((T0 + s) * SECOND + 50_000, 100.0, 100.1, 1.0, 1.0) for s in range(4000)]
    t_rows = [((T0 + s) * SECOND + 500_000, 100.05, 0.1, 1) for s in range(4000)]
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))  # fills the bid at 100.0
    data = _make(q_rows, t_rows, seconds=4000)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "Y1"))
    assert trades.size == 1 and trades["exit_reason"][0] == EXIT_REASONS["time"] and trades["hold_s"][0] == pytest.approx(900.25)
    t_rows.append(((T0 + 1000) * SECOND + 100_000, 100.65, 1.0, 1))  # above the passive target 100.6 (sigma floor: distance 1.5 × 0.2 = 0.3, target 2 × 0.3)
    data = _make(q_rows, t_rows, seconds=4000)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "Y3"))
    assert trades["exit_reason"][0] == EXIT_REASONS["target"] and trades["exit"][0] == pytest.approx(100.6) and trades["fees_bp"][0] == pytest.approx(4.0)


def test_r3_band_reversion_rule_and_z_exits():
    quotes, trades = _synthetic_series(seconds=3000)
    bars = build_bars(quotes, trades, T0, T0 + 3000)
    f = compute_features(bars, INSTRUMENT)
    assert np.isfinite(f["band_dev_900"][5:]).all() and np.isnan(f["sigma_900"][:898]).all() and np.isfinite(f["sigma_900"][900:]).all()
    assert f["band_dev_300"] is f["band_dev"]
    long_mask = signal_mask("S7-W300-K1.5", f, 1)
    short_mask = signal_mask("S7-W300-K1.5", f, -1)
    assert long_mask.shape == (3000,) and not (long_mask & short_mask).any()
    assert signal_mask("S7-W900-K2.0", f, 1).dtype == bool
    q_rows = [((T0 + s) * SECOND + 50_000, 100.0, 100.1, 1.0, 1.0) for s in range(8000)]
    t_rows = [((T0 + s) * SECOND + 500_000, 100.05, 0.1, 1) for s in range(8000)]
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))  # fills the bid at 100.0
    t_rows.append(((T0 + 1900) * SECOND + 100_000, 100.15, 1.0, 1))  # above the ask 100.1 posted at fill + 30 min (Z2)
    data = _make(q_rows, t_rows, seconds=8000)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "Z2"))
    assert trades["exit_reason"][0] == EXIT_REASONS["passive"] and trades["exit"][0] == pytest.approx(100.1) and trades["fees_bp"][0] == pytest.approx(4.0)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "Z1"))
    assert trades["exit_reason"][0] == EXIT_REASONS["time"] and trades["hold_s"][0] == pytest.approx(3600.25)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "Z4"))
    assert trades["exit_reason"][0] == EXIT_REASONS["time"] and trades["hold_s"][0] == pytest.approx(7200.25)  # no stop (floor 0.4 → 99.6) and no target (100.8) hit


def test_both_sides_configuration_id_round_trip():
    from scripts.research_exec.screen import build_grid, configuration_id, parse_configuration
    assert parse_configuration("S7-W900-K1.5|both|E1|Z3") == ("S7-W900-K1.5", 0, "E1", "Z3")
    assert configuration_id("S7-W900-K1.5", 0, "E1", "Z3") == "S7-W900-K1.5|both|E1|Z3"
    grid = build_grid(False, "r4")
    assert len(grid) == 4 and all("|both|" in item for item in grid)


def test_memory_light_series_keeps_only_requested_features():
    from scripts.research_exec.screen import R5_FEATURES, Series, build_grid
    quotes, trades = _synthetic_series(seconds=2000)
    series = Series("t", quotes, trades, INSTRUMENT, T0, T0 + 2000, False, 10.0, True)
    data = series.data(0, R5_FEATURES)
    assert set(data.features) == set(R5_FEATURES) and set(data.bars) == {"ts", "mid", "bid", "ask", "quote_age", "bid_size"}
    assert series.quotes is None and data.tts.flags["C_CONTIGUOUS"]
    assert build_grid(False, "r5") == ["S7-W900-K2.0|both|E1|Z3", "S7-W900-K2.0|both|E1|Z1"]


def test_by_series_mode_matches_pooled_mode(tmp_path):
    from scripts.research_exec.screen import Series, run_screen
    rows_by_mode = {}
    for mode in ("pooled", "by_series"):
        series = []
        for k, seed in enumerate((3, 4)):
            quotes, trades = _synthetic_series(seconds=5000, seed=seed)
            series.append(Series(f"s{k}", quotes, trades, INSTRUMENT, T0, T0 + 5000, True, 10.0, True))
        extra = {"by_series": True} if mode == "by_series" else {}
        document = run_screen(series, "binance", "training", tmp_path / mode, ["S1-W10-T0.5|both|E1|X1", "S4-W60-K2|long|E1|X2"], 2, 1, extra)
        rows_by_mode[mode] = {row["id"]: (row["trades"], row["mean_net_bp"], row["control"]["median"]) for row in document["rows"]}
    assert rows_by_mode["pooled"] == rows_by_mode["by_series"]


def test_r6_grid_has_two_hundred_configurations_with_both_sided_band_rules():
    from scripts.research_exec.screen import PROTOCOLS, build_grid
    grid = build_grid(False, "r6")
    assert len(grid) == 200 and sum(1 for g in grid if "|both|" in g) == 16 and "S7-W900-K2.0|both|E1|Z3" in grid
    assert all(g.split("|")[3] in ("X2", "Y1", "Y3", "Z3") for g in grid) and len(PROTOCOLS["r6"]["symbols"]) == 10


def test_rule_parser_keeps_s_rule_semantics_and_reads_depth_rules():
    from scripts.research_exec.tick import parse_rule
    assert parse_rule("S5-B") == ("S5", {"band": True})
    assert parse_rule("S3-Q0.3-F") == ("S3", {"Q": "0.3", "F": True})
    assert parse_rule("Q3-W900-K1.5-T0.25") == ("Q3", {"W": "900", "K": "1.5", "T": "0.25"})
    assert parse_rule("Q1-B2-T0.4-F") == ("Q1", {"B": "2", "T": "0.4", "F": True})
    for bad in ("S9-W10", "Q1-X1", "Q1-T"):
        with pytest.raises(ValueError):
            parse_rule(bad)


def test_depth_features_forward_fill_staleness_and_reference_change():
    from scripts.research_exec.tick import depth_features
    ts = T0 + np.arange(1000)
    snaps = np.array([T0 + 10, T0 + 40, T0 + 400, T0 + 700])
    bid = np.array([[300.0, 600, 900, 1200, 1500], [100, 200, 300, 400, 500], [100, 300, 600, 900, 1200], [50, 100, 150, 200, 250]])
    ask = np.array([[100.0, 200, 300, 400, 500], [300, 600, 900, 1200, 1500], [100, 100, 100, 100, 100], [50, 100, 150, 200, 250]])
    f = depth_features(ts, {"ts_s": snaps, "bid": bid, "ask": ask})
    assert np.isnan(f["di_1"][9]) and f["di_1"][10] == pytest.approx(0.5)  # a snapshot stamped at second t is usable from the end of t
    assert f["di_1"][39] == pytest.approx(0.5) and f["di_1"][40] == pytest.approx(-0.5) and f["di_2"][40] == pytest.approx(-0.5)
    assert f["di_1"][160] == pytest.approx(-0.5) and np.isnan(f["di_1"][161])  # stale after 120 s
    assert f["di_1"][400] == pytest.approx(0.0) and f["ddi_1"][400] == pytest.approx(0.5)  # reference = the snapshot at +40 (≥ 300 s earlier)
    assert np.isnan(f["ddi_1"][10]) and np.isnan(f["ddi_1"][40])  # no snapshot 300 s earlier
    assert f["di_1"][700] == pytest.approx(0.0) and f["ddi_1"][700] == pytest.approx(0.0)
    empty = depth_features(ts, {"ts_s": np.zeros(0, dtype=np.int64), "bid": np.zeros((0, 5)), "ask": np.zeros((0, 5))})
    assert np.isnan(empty["di_1"]).all() and np.isnan(empty["ddi_2"]).all()


def test_depth_rules_fire_on_the_imbalance_sign_and_combine_with_the_band():
    n = 5
    f = {"di_1": np.array([0.5, -0.5, 0.1, np.nan, 0.3]), "di_2": np.array([0.3, 0.3, -0.3, 0.3, 0.3]), "ddi_1": np.array([0.2, -0.2, 0.0, np.nan, 0.4]), "ddi_2": np.zeros(n),
         "fi_60": np.array([1.0, 1.0, -1.0, 1.0, -1.0]), "band_dev_900": np.full(n, -0.01), "sigma_900": np.full(n, 0.002)}
    assert signal_mask("Q1-B1-T0.25", f, 1).tolist() == [True, False, False, False, True]
    assert signal_mask("Q1-B1-T0.25", f, -1).tolist() == [False, True, False, False, False]
    assert signal_mask("Q1-B1-T0.25-F", f, 1).tolist() == [True, False, False, False, False]  # flow against the signal at index 4
    assert signal_mask("Q4-B1-T0.25", f, 1).tolist() == [False, True, False, False, False]  # contrarian: long against the heavy ask side
    assert signal_mask("Q4-B2-T0.25-F", f, -1).tolist() == [False, False, False, False, True]
    assert signal_mask("Q2-B1-D0.15", f, 1).tolist() == [True, False, False, False, True]
    assert signal_mask("Q2-B1-D0.15", f, -1).tolist() == [False, True, False, False, False]
    assert signal_mask("Q3-W900-K2.0-T0.25", f, 1).tolist() == [True, False, False, False, False]
    assert signal_mask("Q3-W900-K2.0-T0.25", f, -1).tolist() == [False] * n


def test_r8_grid_has_192_configurations_and_depth_rules_need_depth_features():
    from scripts.research_exec.screen import PROTOCOLS, Series, build_grid
    grid = build_grid(False, "r8")
    assert len(grid) == 192 and sum("|both|" in g for g in grid) == 24 and all(g.split("|")[3] in ("Y1", "Y3", "Z3") for g in grid)
    assert sum(g.startswith("Q3") for g in grid) == 8 * 2 * 3 + 24 and len(PROTOCOLS["r8"]["symbols"]) == 20 and len(set(PROTOCOLS["r8"]["symbols"])) == 20
    quotes, trades = _synthetic_series(seconds=2000)
    depth = {"ts_s": T0 + np.arange(0, 2000, 30), "bid": np.full((67, 5), 2.0), "ask": np.full((67, 5), 1.0)}
    series = Series("t", None, None, INSTRUMENT, T0, T0 + 2000, False, 10.0, True, loader=lambda: (quotes, trades, depth))
    data = series.data(0)
    assert data.features["di_1"][-1] == pytest.approx(1.0 / 3.0) and bool(signal_mask("Q1-B1-T0.25", data.features, 1)[-1])
    plain = Series("p", quotes, trades, INSTRUMENT, T0, T0 + 2000, False, 10.0, True).data(1)
    assert "di_1" not in plain.features


def test_acquire_converters_on_synthetic_archives():
    pytest.importorskip("pandas")  # the converters parse with pandas; the research runtime has it, CI may not
    from scripts.research_exec.acquire import convert_aggtrades, convert_bookdepth, parse_dates
    assert parse_dates("2025-01-13..2025-01-15,2024-12-09") == ["2025-01-13", "2025-01-14", "2025-01-15", "2024-12-09"]
    csv = b"agg_trade_id,price,quantity,first_trade_id,last_trade_id,transact_time,is_buyer_maker\n2,100.5,1.5,1,1,1700000000001,false\n1,100.4,2.0,0,0,1700000000000,true\n"
    arrays, diag = convert_aggtrades(csv)
    assert arrays["ts_us"].tolist() == [1700000000000000, 1700000000001000] and arrays["taker_buy"].tolist() == [0, 1]
    assert arrays["agg_trade_id"].tolist() == [1, 2] and diag["input_already_sorted"] is False and str(arrays["taker_buy"].dtype) == "int8"
    headerless = b"1,100.4,2.0,0,0,1700000000000,True\n2,100.5,1.5,1,1,1700000000001,False\n"
    arrays2, _ = convert_aggtrades(headerless)
    assert arrays2["taker_buy"].tolist() == [0, 1] and arrays2["price"].tolist() == [100.4, 100.5]
    levels = (-5, -4, -3, -2, -1, 1, 2, 3, 4, 5)
    depth_csv = b"timestamp,percentage,depth,notional\n" + b"".join(f"2025-03-10 00:00:08,{p},{abs(p) * 10.0},{abs(p) * 100.0}\n".encode() for p in levels) + b"2025-03-10 00:00:53,-1,1,1\n"
    arrays3, diag3 = convert_bookdepth(depth_csv)
    assert arrays3["ts_s"].tolist() == [1741564808] and arrays3["bid"][0].tolist() == [100.0, 200.0, 300.0, 400.0, 500.0]
    assert arrays3["ask"][0].tolist() == [100.0, 200.0, 300.0, 400.0, 500.0] and diag3["snapshots_incomplete_dropped"] == 1


def test_microprice_features_from_sized_quotes():
    q_rows = [((T0 + s) * SECOND + 50_000, 100.0, 100.1, 3.0, 1.0) for s in range(40)]
    t_rows = [((T0 + s) * SECOND + 500_000, 100.05, 0.1, 1) for s in range(40)]
    f = _make(q_rows, t_rows, seconds=40).features
    assert f["qi"][0] == pytest.approx(0.5) and f["mpo"][0] == pytest.approx(0.25)  # mp = (100.1 · 3 + 100.0 · 1) / 4 = 100.075, mid 100.05, spread 0.1
    assert np.isnan(f["dmp_5"][:5]).all() and f["dmp_5"][5:].tolist() == pytest.approx([0.0] * 35)
    assert np.isnan(f["dmp_30"][29]) and f["dmp_30"][30] == pytest.approx(0.0)


def test_m_rules_fire_on_microprice_and_queue_persistence():
    n = 12
    f = {"mpo": np.array([0.3, -0.3, 0.1, np.nan, 0.25] + [0.0] * 7), "fi_10": np.array([1.0, 1.0, 1.0, 1.0, -1.0] + [0.0] * 7),
         "dmp_5": np.full(n, 0.001), "dmp_30": np.full(n, -0.001), "sigma_60": np.full(n, 0.0005), "a_60": np.array([1.0] * 6 + [0.5] * 6),
         "qi": np.array([0.4, 0.4, 0.4, -0.1, 0.6, 0.6, 0.6, 0.6, 0.6, 0.6, 0.6, 0.6])}
    assert signal_mask("M1-T0.2", f, 1).tolist()[:5] == [True, False, False, False, True]
    assert signal_mask("M1-T0.2-F", f, 1).tolist()[:5] == [True, False, False, False, False]
    assert signal_mask("M1-T0.2", f, -1).tolist()[:5] == [False, True, False, False, False]
    assert signal_mask("M2-W5-K1.0", f, 1).tolist() == [True] * 6 + [False] * 6 and not signal_mask("M2-W5-K1.0", f, -1).any()  # 0.001 > 0.0005 · sqrt(5/60), activity gate
    assert signal_mask("M2-W30-K2.0", f, -1).tolist() == [True] * 6 + [False] * 6  # 0.001 > 2 · 0.0005 · sqrt(30/60)
    assert signal_mask("M3-Q0.3-N3", f, 1).tolist() == [False, False, True, False, False, False, True, True, True, True, True, True]
    assert signal_mask("M3-Q0.5-N3", f, 1).tolist() == [False] * 6 + [True] * 6 and not signal_mask("M3-Q0.3-N3", f, -1).any()


def test_queue_entry_fills_when_prints_at_the_price_exhaust_the_displayed_quantity():
    # Flat book 100.0 / 100.1 with 5.0 displayed at the bid. Decision at the end of second 2; taker sells of 2.0 at 100.0
    # every second from +3.6 s: the queue rule (E3) fills on the fourth print (8.0 ≥ 5.0 + 250 / 100), the through rule (E1) never.
    q_rows = [((T0 + s) * SECOND + 50_000, 100.0, 100.1, 5.0, 1.0) for s in range(700)]
    t_rows = [((T0 + s) * SECOND + 500_000, 100.05, 0.1, 1) for s in range(700)]
    sells = [((T0 + 3 + k) * SECOND + 600_000, 100.0, 2.0, 0) for k in range(10)]
    data = _make(q_rows, t_rows + sells)
    through, _ = run_policy(data, 1, np.array([2]), Policy("E1", "X1"))
    queued, counters = run_policy(data, 1, np.array([2]), Policy("E3", "X1"))
    assert through.size == 0 and queued.size == 1 and counters.filled == 1
    assert queued["fill_ts"][0] == (T0 + 6) * SECOND + 600_000 and queued["exit_reason"][0] == EXIT_REASONS["time"]
    buys = [((T0 + 3 + k) * SECOND + 600_000, 100.0, 2.0, 1) for k in range(10)]  # taker buys at 100.0 do not consume a resting bid's queue
    none, _ = run_policy(_make(q_rows, t_rows + buys), 1, np.array([2]), Policy("E3", "X1"))
    assert none.size == 0


def test_bookticker_converter_keeps_the_last_update_per_second_and_counts():
    pytest.importorskip("pandas")
    from scripts.research_exec.acquire import convert_bookticker
    csv = (b"update_id,best_bid_price,best_bid_qty,best_ask_price,best_ask_qty,transaction_time,event_time\n"
           b"1,100.0,3.0,100.1,1.0,1700000000100,1700000000101\n"
           b"2,100.0,1.0,100.1,3.0,1700000000900,1700000000901\n"
           b"3,100.1,2.0,100.2,2.0,1700000002500,1700000002501\n"
           b"4,100.0,1.0,100.1,1.0,1700000000500,1700000000501\n")  # out of order: belongs to the first second
    arrays, diag = convert_bookticker(csv)
    assert arrays["ts_s"].tolist() == [1700000000, 1700000002] and arrays["bid"].tolist() == [100.0, 100.1] and arrays["ask_qty"].tolist() == [3.0, 2.0]
    assert arrays["updates"].tolist() == [3, 1] and arrays["qi_mean"][0] == pytest.approx(0.0) and arrays["qi_mean"][1] == pytest.approx(0.0)
    assert diag["updates_total"] == 4 and diag["seconds_with_updates"] == 2 and diag["input_already_sorted"] is False


def test_r9_grid_and_book_quotes_loader(tmp_path):
    from scripts.research_exec.screen import PROTOCOLS, book_quotes, build_grid
    grid = build_grid(True, "r9")
    assert len(grid) == 256 and sum("|E3|" in g for g in grid) == 128 and all(g.split("|")[3] in ("X1", "X3", "X4", "Y1") for g in grid)
    assert len({g.split("|")[0] for g in grid}) == 16 and len(PROTOCOLS["r9"]["symbols"]) == 18 and len(set(PROTOCOLS["r9"]["symbols"])) == 18
    path = tmp_path / "BTCUSDT-2024-01-15.npz"
    np.savez(path, ts_s=np.array([T0 + 1, T0]), bid=np.array([100.1, 100.0]), ask=np.array([100.2, 100.1]), bid_qty=np.array([2.0, 3.0]), ask_qty=np.array([1.0, 1.0]), updates=np.array([1, 1], dtype=np.int32), qi_mean=np.array([0.3, 0.5]))
    quotes = book_quotes((path,))
    assert quotes["ts"].tolist() == [(T0 + 1) * SECOND - 1, (T0 + 2) * SECOND - 1] and quotes["bid_size"].tolist() == [3.0, 2.0] and quotes["ask"].tolist() == [100.1, 100.2]
    assert book_quotes(()).size == 0


def test_q3x_ablation_masks_nest_and_q3_is_unchanged():
    """q3x: QB ⊇ S7 ⊇ Q3, QB ⊇ QD ⊇ Q3; Q3L equals Q3 when every second has a print."""
    np = pytest.importorskip("numpy")
    from scripts.research_exec import tick
    rng = np.random.default_rng(0)
    n = 5_000
    f = {"band_dev_900": rng.normal(0, 1e-3, n), "sigma_900": np.full(n, 5e-4), "sigma_900_live": np.full(n, 5e-4),
         "fi_60": rng.uniform(-1, 1, n), "di_1": rng.uniform(-1, 1, n)}
    qb = tick.signal_mask("QB-W900-K1.5", f, 1)
    s7 = tick.signal_mask("S7-W900-K1.5", f, 1)
    qd = tick.signal_mask("QD-W900-K1.5-T0.1", f, 1)
    q3 = tick.signal_mask("Q3-W900-K1.5-T0.1", f, 1)
    q3l = tick.signal_mask("Q3L-W900-K1.5-T0.1", f, 1)
    assert q3.sum() > 0 and (q3 <= s7).all() and (s7 <= qb).all() and (q3 <= qd).all() and (qd <= qb).all()
    np.testing.assert_array_equal(q3, q3l)
    expected = (f["band_dev_900"] < -1.5 * f["sigma_900"]) & (f["fi_60"] > 0) & (f["di_1"] > 0.1)
    np.testing.assert_array_equal(q3, expected)  # the frozen Q3 definition


def test_live_sigma_ignores_quiet_seconds():
    np = pytest.importorskip("numpy")
    from scripts.research_exec import tick
    from scripts.research_exec.capture import Instrument
    n = 2_000
    mid = 100 * np.exp(np.cumsum(np.random.default_rng(1).normal(0, 1e-4, n)))
    prints = np.ones(n)
    prints[1_000:1_450] = 0  # 450 quiet seconds: the proxied mid stays flat
    mid[1_000:1_450] = mid[999]
    bars = {"ts": np.arange(n), "mid": mid, "bid": mid - 0.01, "ask": mid + 0.01, "last": mid, "bid_size": np.ones(n),
            "ask_size": np.ones(n), "buy_vol": prints, "sell_vol": prints, "n": prints, "quote_age": np.zeros(n)}
    f = tick.compute_features(bars, Instrument("X", "X", 1.0, 0.001, 0.001, fixed_tick=0.0001))
    t = 1_600
    assert f["stale_900"][t] == pytest.approx(450 / 900)
    assert f["sigma_900_live"][t] > f["sigma_900"][t] * 1.3
