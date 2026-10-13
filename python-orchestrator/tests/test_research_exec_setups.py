"""Tests of the r10 platform-setup port: klines, indicators, conditions, stacks at the cadence,
the per-second features, the B exits and the r10 grid (PREREGISTRATION-r10.md)."""

from __future__ import annotations

import io
import json
import zipfile

import numpy as np
import pytest

from scripts.research_exec import platform_setups as ps
from scripts.research_exec.capture import QUOTE_DTYPE, TRADE_DTYPE, Instrument
from scripts.research_exec.scalp_engine import EXIT_REASONS, SETUP_TTL_US, Policy, SeriesData, run_policy
from scripts.research_exec.screen import PROTOCOLS, build_grid, main as screen_main, parse_configuration
from scripts.research_exec.tick import build_bars, compute_features, parse_rule, signal_mask

SECOND = 1_000_000
DAY = 1_790_000_000 - 1_790_000_000 % 86400  # a UTC midnight
T0 = DAY + 36_000  # 10:00 UTC
INSTRUMENT = Instrument("BTCUSDT", "BTC-USDT-SWAP", 0.01, 0.01, 0.01, fixed_tick=0.1)


def quotes_from(rows):
    return np.array([(ts, ts, bid, ask, 1.0, 1.0) for ts, bid, ask in rows], dtype=QUOTE_DTYPE)


def trades_from(rows):
    return np.array([(ts, ts, price, 1.0, 1, 1) for ts, price in rows], dtype=TRADE_DTYPE)


def _candles(minutes: int, start_s: int = DAY, seed: int = 1, drift: float = 0.0) -> dict[str, np.ndarray]:
    rng = np.random.default_rng(seed)
    close = 100.0 * np.exp(np.cumsum(rng.normal(drift, 4e-4, size=minutes)))
    open_ = np.concatenate([[100.0], close[:-1]])
    high = np.maximum(open_, close) * (1 + np.abs(rng.normal(0, 2e-4, size=minutes)))
    low = np.minimum(open_, close) * (1 - np.abs(rng.normal(0, 2e-4, size=minutes)))
    volume = rng.lognormal(3.0, 0.8, size=minutes)
    return {"ts_s": start_s + 60 * np.arange(minutes, dtype=np.int64), "open": open_, "high": high, "low": low, "close": close, "volume": volume}


def test_klines_converter_sorts_dedups_and_counts_missing_minutes():
    pytest.importorskip("pandas")
    from scripts.research_exec.acquire import convert_klines, parse_months

    header = "open_time,open,high,low,close,volume,close_time,quote_volume,count,taker_buy_volume,taker_buy_quote_volume,ignore"
    rows = [(120000, 2.0), (0, 1.0), (60000, 1.5), (60000, 1.6), (240000, 3.0)]  # unsorted, one duplicate minute, minute 3 missing
    text = header + "\n" + "".join(f"{ms},{px},{px + 0.1},{px - 0.1},{px},10,{ms + 59999},100,5,4,40,0\n" for ms, px in rows)
    arrays, diag = convert_klines(text.encode())
    assert arrays["ts_s"].tolist() == [0, 60, 120, 240] and arrays["close"].tolist() == [1.0, 1.6, 2.0, 3.0]
    assert diag["duplicates"] == 1 and diag["missing_minutes"] == 1 and diag["input_already_sorted"] is False
    assert parse_months("2024-03..2024-05,2024-08") == ["2024-03", "2024-04", "2024-05", "2024-08"]
    assert parse_months("2024-11..2025-01") == ["2024-11", "2024-12", "2025-01"]


def test_months_covering_adds_one_warmup_month():
    assert ps.months_covering(ps.datetime(2024, 4, 15, tzinfo=ps.timezone.utc).timestamp(), ps.datetime(2024, 4, 20, tzinfo=ps.timezone.utc).timestamp()) == ["2024-03", "2024-04"]
    assert ps.months_covering(ps.datetime(2024, 11, 18, tzinfo=ps.timezone.utc).timestamp(), ps.datetime(2024, 11, 23, tzinfo=ps.timezone.utc).timestamp()) == ["2024-10", "2024-11"]


def test_aggregate_builds_utc_buckets_from_minutes():
    c = _candles(15)
    five = ps.aggregate(c, 300)
    assert five["ts_s"].tolist() == [DAY, DAY + 300, DAY + 600]
    assert five["open"][1] == c["open"][5] and five["close"][1] == c["close"][9]
    assert five["high"][1] == c["high"][5:10].max() and five["low"][1] == c["low"][5:10].min()
    assert five["volume"][2] == pytest.approx(c["volume"][10:15].sum())


def test_indicator_conventions_match_the_platform():
    x = np.array([1.0, 2.0, 3.0, 4.0, 5.0])
    assert ps.ema(x, 3).tolist() == pytest.approx([1.0, 1.5, 2.25, 3.125, 4.0625])  # seeded with the first value
    sma_seeded = ps.ema_sma_seeded(x, 3)
    assert np.isnan(sma_seeded[:2]).all() and sma_seeded[2] == 2.0 and sma_seeded[3] == 3.0
    assert ps.wilder(np.array([2.0, 4.0, 6.0, 8.0]), 2).tolist() == pytest.approx([np.nan, 3.0, 4.5, 6.25], nan_ok=True)
    rising = np.linspace(100, 120, 40)
    assert ps.rsi(rising)[-1] == pytest.approx(100.0) and ps.rsi(rising[::-1])[-1] == pytest.approx(0.0)
    high, low, close = np.full(30, 101.0), np.full(30, 100.0), np.full(30, 100.5)
    assert ps.atr(high, low, close)[-1] == pytest.approx(1.0)
    # The VWAP is cumulated over the last 250 candles, all of them when fewer exist.
    c = _candles(300)
    vwap = ps.windowed_vwap(c["high"], c["low"], c["close"], c["volume"])
    typical = (c["high"] + c["low"] + c["close"]) / 3
    assert vwap[10] == pytest.approx((typical[:11] * c["volume"][:11]).sum() / c["volume"][:11].sum())
    assert vwap[299] == pytest.approx((typical[50:300] * c["volume"][50:300]).sum() / c["volume"][50:300].sum())
    ratio = ps.volume_ratio(np.array([1.0, 2.0, 3.0, 0.0, 6.0]))
    assert np.isnan(ratio[:2]).all() and ratio[2] == pytest.approx(2.0) and np.isnan(ratio[3]) and ratio[4] == pytest.approx(3.0)
    adx = ps.adx(c["high"], c["low"], c["close"])
    assert np.isnan(adx[:14]).all() and np.isfinite(adx[14:]).all() and (adx[14:] >= 0).all() and (adx[14:] <= 100).all()


def test_pullback_age_counts_candles_since_cross_or_vwap_touch():
    ema9 = np.array([1.0, 1.0, 2.0, 2.0, 2.0, 2.0])
    ema21 = np.array([1.5, 1.5, 1.5, 1.5, 1.5, 1.5])
    close = np.array([10.0, 10.0, 10.0, 10.0, 10.0, 10.0])
    vwap = np.array([9.0, 9.0, 9.0, 9.0, 10.001, 9.0])
    age = ps.pullback_age(ema9, ema21, close, vwap, window=100)
    assert np.isnan(age[0]) and np.isnan(age[1]) and age[2] == 0 and age[3] == 1 and age[4] == 0 and age[5] == 1
    assert np.isnan(ps.pullback_age(ema9, ema21, close, vwap, window=0)[3])


def test_histogram_cross_with_hysteresis_and_conditions_reject_nan():
    hist = np.array([-0.001, 0.001, 0.0005, 0.0002, 0.0001, 0.0001])
    up = ps._hist_cross(hist, 0.0006, 2, up=True)
    assert up.tolist() == [False, True, True, True, False, False]
    c = _candles(400)
    conditions = ps.conditions(ps.indicators(c))
    assert all(values.dtype == bool and values.shape == (400,) for values in conditions.values())
    assert not conditions["atr_rel_in_range"][:13].any()  # ATR undefined during the warm-up → False, never NaN


def test_stacks_and_per_second_features_follow_the_cadence():
    minutes = 3 * 1440
    c = _candles(minutes, seed=5, drift=2e-4)
    at = ps.stacks_at_cadence(c)
    assert at["close_s"].tolist()[:2] == [DAY + 300, DAY + 600]
    for n in (1, 2, 3):
        assert not (at[f"p{n}_a4"] & ~at[f"p{n}_a3"]).any()  # the full stack implies the core layers
    start_s, end_s = DAY + 86400 + 3600, DAY + 86400 + 7200 + 17  # one hour and 17 seconds inside day 2
    f = ps.setup_features(c, start_s, end_s)
    n = end_s - start_s
    assert all(f[name].shape == (n,) for name in ps.SETUP_FEATURES)
    fired = np.flatnonzero(np.logical_or.reduce([f[name] for name in ps.STACK_NAMES]))
    assert all((start_s + s + 1) % 300 == 0 for s in fired)  # decisions only at the last second of a 5-minute candle
    first_close = 299  # the first 5-minute close inside the window is at second 300 → decision second 299
    assert np.isnan(f["atr5"][:first_close]).all() and np.isfinite(f["atr5"][first_close:]).all()
    assert f["atr5_bp"][first_close] == pytest.approx(f["atr5"][first_close] / c["close"][(start_s + 300 - DAY) // 60 - 1] * 1e4)
    assert (f["zone_hi"][first_close:] > f["zone_lo"][first_close:]).all()
    # A stack that is true at a 5-minute close is a decision second for its side only, and the cost gate thins it.
    bars_mid = np.full(n, 100.0)
    features = dict(f, mid=bars_mid, spread_bp=np.full(n, 1.0))
    f["p2_a3"][:] = False
    f["p2_a3"][first_close] = True
    features["p2_a3"] = f["p2_a3"]
    features["atr5_bp"] = np.full(n, 20.0)
    assert signal_mask("P2-A3-K0", features, 1).sum() == 1 and signal_mask("P2-A3-K0", features, -1).sum() == 0
    assert signal_mask("P2-A3-K2", features, 1).sum() == 1 and signal_mask("P2-A3-K4", features, 1).sum() == 0  # 20 bp < 4 × 8 bp


def _series(quotes, trades, atr5: float, start_s: int = T0, seconds: int = 7400) -> SeriesData:
    bars = build_bars(quotes, trades, start_s, start_s + seconds)
    features = compute_features(bars, INSTRUMENT)
    features["atr5"] = np.full(seconds, atr5)
    return SeriesData(0, bars, features, quotes, trades, INSTRUMENT, start_s)


def _entry_rows(start_s: int):
    quotes = [(start_s * SECOND + 500_000, 100.0, 100.1)]
    trades = [((start_s + 2) * SECOND, 99.9)]  # through the resting bid at 100.0 → fill at 100.0
    return quotes, trades


def test_b_exits_maker_target_taker_target_stop_and_time():
    decision = np.array([0])
    quotes, trades = _entry_rows(T0)
    # Stop distance 1.5 × 0.4 = 0.6: stop 99.4, 1.8 R target 101.08 → 101.1 on the grid, 3 R target 101.8.
    quotes_up = quotes + [((T0 + 99) * SECOND, 101.1, 101.2), ((T0 + 7000) * SECOND, 100.5, 100.6)]
    trades_up = trades + [((T0 + 100) * SECOND, 101.2)]
    data = _series(quotes_from(quotes_up), trades_from(trades_up), 0.4)
    maker, _ = run_policy(data, 1, decision, Policy("E1", "B2", ttl_us=SETUP_TTL_US))
    assert maker.size == 1 and maker["exit_reason"][0] == EXIT_REASONS["target"] and maker["exit"][0] == pytest.approx(101.1)
    assert maker["fees_bp"][0] == pytest.approx(4.0) and maker["net_bp"][0] == pytest.approx(110.0 - 4.0)
    taker, _ = run_policy(data, 1, decision, Policy("E1", "B1", ttl_us=SETUP_TTL_US))
    assert taker["exit_reason"][0] == EXIT_REASONS["target"] and taker["exit"][0] == pytest.approx(101.1) and taker["fees_bp"][0] == pytest.approx(7.0)
    wide, _ = run_policy(data, 1, decision, Policy("E1", "B3", ttl_us=SETUP_TTL_US))
    assert wide["exit_reason"][0] == EXIT_REASONS["time"] and wide["exit"][0] == pytest.approx(100.5) and wide["hold_s"][0] == pytest.approx(7200.25)
    timed, _ = run_policy(data, 1, decision, Policy("E1", "B4", ttl_us=SETUP_TTL_US))
    assert timed["exit_reason"][0] == EXIT_REASONS["time"] and timed["exit"][0] == pytest.approx(100.5)
    quotes_down = quotes + [((T0 + 49) * SECOND, 99.3, 99.4), ((T0 + 7000) * SECOND, 100.5, 100.6)]
    trades_down = trades + [((T0 + 50) * SECOND, 99.3), ((T0 + 100) * SECOND, 101.2)]
    data = _series(quotes_from(quotes_down), trades_from(trades_down), 0.4)
    stopped, _ = run_policy(data, 1, decision, Policy("E1", "B2", ttl_us=SETUP_TTL_US))
    assert stopped["exit_reason"][0] == EXIT_REASONS["stop"] and stopped["exit"][0] == pytest.approx(99.3) and stopped["net_bp"][0] == pytest.approx(-70.0 - 7.0)


def test_b_exits_close_before_the_utc_day_boundary_and_skip_without_atr():
    start_s = DAY + 86400 - 200  # 23:56:40 UTC
    quotes, trades = _entry_rows(start_s)
    quotes += [((start_s + 130) * SECOND, 100.3, 100.4), ((start_s + 7300) * SECOND, 100.9, 101.0)]
    data = _series(quotes_from(quotes), trades_from(trades), 0.4, start_s=start_s)
    rows, _ = run_policy(data, 1, np.array([0]), Policy("E1", "B4", ttl_us=SETUP_TTL_US))
    assert rows.size == 1 and rows["exit_reason"][0] == EXIT_REASONS["time"] and rows["exit"][0] == pytest.approx(100.3)
    assert rows["exit_ts"][0] == (DAY + 86400 - 60) * SECOND + 250_000  # 23:59:00 plus the latency
    data.features["atr5"][:] = np.nan
    rows, counters = run_policy(data, 1, np.array([0]), Policy("E1", "B4", ttl_us=SETUP_TTL_US))
    assert rows.size == 0 and counters.decisions == 0


def test_r10_grid_has_96_configurations_with_implied_sides_and_p_rules_parse():
    grid = build_grid(False, "r10")
    assert len(grid) == 96 and len(set(grid)) == 96
    assert all(parse_configuration(item)[1] == (-1 if item.startswith("P3") else 1) for item in grid)
    assert all(parse_configuration(item)[2] == "E1" and parse_configuration(item)[3] in ("B1", "B2", "B3", "B4") for item in grid)
    assert parse_rule("P1-A4-K6") == ("P1", {"A": "4", "K": "6"})
    with pytest.raises(ValueError):
        parse_rule("P4-A4-K0")
    spec = PROTOCOLS["r10"]
    assert spec["phase_symbols"]["validation"] == spec["phase_symbols"]["holdout"] and len(spec["phase_symbols"]["holdout"]) == 10
    assert spec["training_windows"] == ("w2404", "w2405", "w2406", "w2408") and spec["phases"]["holdout"] == ("w2411a", "w2411b")


# r10x (EXPLORATORY-r10x.md): the volume-ratio threshold variants of the full stacks and the entry-zone tokens.


def test_exploration_volume_thresholds_add_nested_full_stacks():
    c = _candles(3 * 1440, seed=5, drift=2e-4)
    assert ps.volume_key(1.1) == "v11" and ps.volume_key(1.2) == "v12" and ps.volume_key(1.3) == "v13"
    ind = ps.indicators(ps.aggregate(c, 300))
    ind["volume_ratio"] = np.full(ind["close"].shape, 1.25)
    assert ps.conditions(ind, 1.2)["volume_ratio_ok"].all() and not ps.conditions(ind, 1.3)["volume_ratio_ok"].any() and not ps.conditions(ind)["volume_ratio_ok"].any()
    at = ps.stacks_at_cadence(c, volume_thresholds=ps.EXPLORATION_VOLUME_THRESHOLDS)
    plain = ps.stacks_at_cadence(c)
    for n in (1, 2, 3):
        assert (at[f"p{n}_a4"] == plain[f"p{n}_a4"]).all() and (at[f"p{n}_a3"] == plain[f"p{n}_a3"]).all()  # the r10 stacks are unchanged
        chain = [at[f"p{n}_a3"], at[f"p{n}_a4_v11"], at[f"p{n}_a4_v12"], at[f"p{n}_a4_v13"], at[f"p{n}_a4"]]
        for wider, tighter in zip(chain, chain[1:]):
            assert not (tighter & ~wider).any()  # a higher volume threshold decides a subset of the lower one's decisions
    start_s, end_s = DAY + 86400 + 3600, DAY + 86400 + 7200 + 17
    f = ps.setup_features(c, start_s, end_s, volume_thresholds=(1.1,))
    assert "p1_a4_v11" in f and "p1_a4_v12" not in f and f["p3_a4_v11"].shape == (end_s - start_s,) and set(ps.SETUP_FEATURES) <= set(f)
    fired = np.flatnonzero(np.logical_or.reduce([f[name] for name in f if name.startswith("p")]))
    assert all((start_s + s + 1) % 300 == 0 for s in fired)


def test_exploration_rule_tokens_select_the_threshold_stack_and_the_zone():
    assert parse_rule("P1-A4-K0-V1.2") == ("P1", {"A": "4", "K": "0", "V": "1.2"})
    assert parse_rule("P3-A3-K0-Z1") == ("P3", {"A": "3", "K": "0", "Z": "1"})
    n = 6
    features = {"mid": np.full(n, 100.0), "spread_bp": np.full(n, 1.0), "atr5": np.full(n, 0.5), "atr5_bp": np.full(n, 50.0),
                "zone_lo": np.array([99.9, 100.1, 99.9, 99.9, np.nan, 99.9]), "zone_hi": np.array([100.1, 100.2, 100.1, 99.95, np.nan, 100.1]),
                "p1_a4": np.array([True, True, True, True, True, False]), "p1_a4_v12": np.array([True, True, False, True, True, True]), "p1_a3": np.ones(n, dtype=bool)}
    features["atr5"][4] = np.nan  # no ATR at the decision second: never a decision, in or out of the zone
    assert signal_mask("P1-A4-K0-V1.2", features, 1).tolist() == [True, True, False, True, False, True]
    assert signal_mask("P1-A4-K0", features, 1).tolist() == [True, True, True, True, False, False]
    assert signal_mask("P1-A4-K0-V1.2", features, -1).sum() == 0
    inside, outside = signal_mask("P1-A3-K0-Z1", features, 1), signal_mask("P1-A3-K0-Z0", features, 1)
    assert inside.tolist() == [True, False, True, False, False, True] and outside.tolist() == [False, True, False, True, False, False]
    assert (inside | outside).tolist() == signal_mask("P1-A3-K0", features, 1).tolist()  # the two zone rows partition the unconstrained decisions
    assert signal_mask("P1-A3-K2-Z1", features, 1).sum() == 3 and signal_mask("P1-A3-K8-Z1", features, 1).sum() == 0  # 50 bp ≥ 2 × 8, < 8 × 8
    with pytest.raises(ValueError):
        signal_mask("P1-A3-K0-V1.2", features, 1)
    with pytest.raises(ValueError):
        signal_mask("P1-A3-K0-Z2", features, 1)


def test_r10x_exploration_grid_has_180_configurations_and_no_validation_phase(tmp_path):
    grid = build_grid(False, "r10x")
    assert len(grid) == 180 and len(set(grid)) == 180
    assert sum(1 for item in grid if "-V" in item) == 108 and sum(1 for item in grid if "-Z" in item) == 48
    baseline = [item for item in grid if "-V" not in item and "-Z" not in item]
    assert len(baseline) == 24 and set(baseline) <= set(build_grid(False, "r10"))
    assert all(parse_configuration(item)[1] == (-1 if item.startswith("P3") else 1) for item in grid)
    assert all(parse_rule(item.split("|")[0])[1]["A"] == "4" for item in grid if "-V" in item)
    spec = PROTOCOLS["r10x"]
    assert spec["exploratory"] and spec["phases"] == {} and spec["training_windows"] == PROTOCOLS["r10"]["training_windows"]
    assert spec["setup_volume_thresholds"] == (1.1, 1.2, 1.3) and spec["gates"] == PROTOCOLS["r10"]["gates"]
    with pytest.raises(SystemExit):  # the exploratory protocol never reads a validation or holdout window
        screen_main(["screen", "--source", "binance", "--phase", "validation", "--protocol", "r10x", "--out", str(tmp_path), "--tape-dir", str(tmp_path), "--instruments", str(tmp_path / "none.json")])


# r11 (PREREGISTRATION-r11.md): the pooled family P0, the zone-restricted random control, the selection policy, the grid.


def test_pool_family_p0_unions_the_long_setups_and_takes_the_short_one():
    assert parse_rule("P0-A3-K0-Z1") == ("P0", {"A": "3", "K": "0", "Z": "1"})
    n = 5
    features = {"mid": np.full(n, 100.0), "spread_bp": np.full(n, 1.0), "atr5": np.full(n, 0.5), "atr5_bp": np.full(n, 50.0),
                "zone_lo": np.full(n, 99.9), "zone_hi": np.full(n, 100.1),
                "p1_a3": np.array([True, False, False, False, True]), "p2_a3": np.array([False, True, False, False, True]), "p3_a3": np.array([False, False, True, False, True])}
    features["zone_lo"][3] = 100.2  # outside the zone
    assert signal_mask("P0-A3-K0-Z1", features, 1).tolist() == [True, True, False, False, True]
    assert signal_mask("P0-A3-K0-Z1", features, -1).tolist() == [False, False, True, False, True]
    assert signal_mask("P0-A3-K0", features, -1).tolist() == [False, False, True, False, True]
    with pytest.raises(ValueError):
        signal_mask("P0-A4-K0-V1.2", features, 1)


def test_zone_restricted_control_draws_only_seconds_with_the_rule_zone_status():
    from types import SimpleNamespace
    from scripts.research_exec import screen as sc
    from scripts.research_exec.scalp_engine import TRADE_DTYPE
    n = 7200
    ts = T0 + np.arange(n, dtype=np.int64)
    inside = np.zeros(n, dtype=bool)
    inside[1000:1500] = True
    inside[4000:4100] = True
    features = {"mid": np.full(n, 100.0), "atr5": np.full(n, 0.5), "zone_lo": np.where(inside, 99.9, 100.2), "zone_hi": np.where(inside, 100.1, 100.4)}
    features["atr5"][4050:4100] = np.nan  # no ATR: never a candidate, in or out of the zone
    data = SimpleNamespace(bars={"ts": ts}, series_id=3)
    trades = np.zeros(4, dtype=TRADE_DTYPE)
    trades["decision_s"] = [T0 + 10, T0 + 20, T0 + 4000, T0 + 7000]
    sc.use_protocol("r11")
    try:
        assert sc.ZONE_CONTROL["enabled"]
        candidates = sc._zone_candidates("P3-A3-K0-Z1", features)
        assert candidates is not None and candidates.sum() == 550 and candidates[1000] and not candidates[4050] and not candidates[999]
        outside = sc._zone_candidates("P3-A3-K0-Z0", features)
        assert outside is not None and outside.sum() == n - 550 - 50 and not outside[1000] and outside[999] and not outside[4050]
        assert sc._zone_candidates("P3-A3-K0", features) is None  # no zone token: unrestricted
        for seed in range(5):
            picks = sc._control_decisions(data, trades, seed, candidates)
            assert picks.size and candidates[picks].all()
            hours = (ts[picks] // 3600) % 24
            assert set(hours.tolist()) <= set(((trades["decision_s"] // 3600) % 24).tolist())
        free = sc._control_decisions(data, trades, 0)
        assert free.size == 4 and not candidates[free].all()
    finally:
        sc.use_protocol("r10")
    assert not sc.ZONE_CONTROL["enabled"] and sc._zone_candidates("P3-A3-K0-Z1", features) is None


def test_r11_grid_phases_and_control_only_selection(tmp_path):
    from scripts.research_exec.screen import BINANCE_WINDOWS, select
    grid = build_grid(False, "r11")
    assert len(grid) == 12 and len(set(grid)) == 12
    assert sorted({item.split("|")[0] for item in grid}) == ["P0-A3-K0-Z1", "P1-A3-K0-Z1", "P2-A3-K0-Z1", "P3-A3-K0-Z1"]
    assert all(parse_configuration(item)[1] == {"P0": 0, "P1": 1, "P2": 1, "P3": -1}[item[:2]] for item in grid)
    assert all(parse_configuration(item)[3] in ("B4", "B2", "B3") for item in grid)
    spec = PROTOCOLS["r11"]
    assert spec["zone_control"] and spec["selection"] == "control" and spec["gates"] == PROTOCOLS["r10"]["gates"] and spec["validation"] == PROTOCOLS["r10"]["validation"]
    assert spec["phases"] == {"validation": ("m2409",), "holdout": ("m2411",)} and spec["phase_symbols"]["validation"] == spec["phase_symbols"]["holdout"]
    assert BINANCE_WINDOWS["m2409"] == ("2024-09-01", "2024-09-30") and BINANCE_WINDOWS["m2411"] == ("2024-11-01", "2024-11-30")
    rows = [
        {"id": "P3-A3-K0-Z1|short|E1|B4", "family": "P3", "trades": 223, "passes": False, "lb90_bp": -2.1, "mean_net_bp": 9.4, "gates": {"G1_trades": False, "G4_control": True}},
        {"id": "P3-A3-K0-Z1|short|E1|B2", "family": "P3", "trades": 234, "passes": False, "lb90_bp": -8.6, "mean_net_bp": -0.7, "gates": {"G1_trades": False, "G4_control": False}},
        {"id": "P0-A3-K0-Z1|both|E1|B4", "family": "P0", "trades": 0, "passes": False, "lb90_bp": None, "mean_net_bp": None, "gates": {"G1_trades": False, "G4_control": False}},
    ]
    assert [item["id"] for item in select({"rows": rows}, policy="control")] == ["P3-A3-K0-Z1|short|E1|B4"]
    assert select({"rows": rows}) == []  # the default policy still needs every gate
    doc = tmp_path / "screen-training.json"
    doc.write_text(json.dumps({"rows": rows}))
    out = tmp_path / "validation-list.json"
    assert screen_main(["select", "--protocol", "r11", "--screen", str(doc), "--out", str(out)]) == 0
    assert out.with_suffix(".txt").read_text().split() == ["P3-A3-K0-Z1|short|E1|B4"]


# r12 (PREREGISTRATION-r12.md): the zone alone (family R1), the 5-minute cadence feature, the fee × 1.5 verdict check.


def test_zone_only_family_r1_fires_at_the_cadence_inside_the_zone_for_both_streams():
    assert parse_rule("R1") == ("R1", {})
    n = 6
    features = {"mid": np.full(n, 100.0), "atr5": np.full(n, 0.5), "cadence5": np.array([True, True, True, False, True, True]),
                "zone_lo": np.array([99.9, 100.1, 99.9, 99.9, 99.9, 99.9]), "zone_hi": np.array([100.1, 100.2, 100.1, 100.1, 100.1, 100.1])}
    features["atr5"][5] = np.nan
    expected = [True, False, True, False, True, False]  # in zone at the cadence with an ATR; index 1 outside, 3 off-cadence, 5 no ATR
    assert signal_mask("R1", features, 1).tolist() == expected and signal_mask("R1", features, -1).tolist() == expected
    c = _candles(3 * 1440, seed=5, drift=2e-4)
    start_s, end_s = DAY + 86400 + 3600, DAY + 86400 + 7200 + 17
    f = ps.setup_features(c, start_s, end_s)
    fired = np.flatnonzero(f["cadence5"])
    assert fired.size == 12 and all((start_s + s + 1) % 300 == 0 for s in fired)  # one hour and 17 s: twelve 5-minute closes
    assert "cadence5" in ps.SETUP_FEATURES and not (np.logical_or.reduce([f[name] for name in ps.STACK_NAMES]) & ~f["cadence5"]).any()


def test_r12_grid_sides_and_fee_robustness_verdict():
    from scripts.research_exec import screen as sc
    assert build_grid(False, "r12") == ["R1|long|E1|B4", "R1|short|E1|B4", "R1|both|E1|B4"]
    spec = sc.PROTOCOLS["r12"]
    assert spec["selection"] == "control" and not spec.get("zone_control") and spec["phases"] == {"validation": ("m2409",), "holdout": ("m2411",)}
    assert spec["validation"]["fee_x_net_positive"] and spec["gates"] == sc.PROTOCOLS["r10"]["gates"] and spec["phase_symbols"]["holdout"] == sc.R10_MIDCAPS
    row = {"id": "R1|both|E1|B4", "trades": 150, "mean_net_bp": 3.0, "profit_factor": 1.2, "control": {"median": 0.5}, "series_positive_share": 0.6, "fee_x_net_bp": -10.0, "lb90_bp": 0.1, "net_usdt": 1.0}
    sc.use_protocol("r12")
    try:
        out = sc.verdict({"passes": 0}, {"source": "binance", "rows": [row]})
        assert out["results"][row["id"]]["checks"]["fees_x1_5"] is False and out["candidates"] == []
        out = sc.verdict({"passes": 0}, {"source": "binance", "rows": [dict(row, fee_x_net_bp=5.0)]})
        assert out["results"][row["id"]]["passes"] and out["candidates"] == [row["id"]]
        sc.use_protocol("r11")
        out = sc.verdict({"passes": 0}, {"source": "binance", "rows": [row]})
        assert "fees_x1_5" not in out["results"][row["id"]]["checks"] and out["candidates"] == [row["id"]]  # r11 keeps its frozen r8 checks
    finally:
        sc.use_protocol("r10")
