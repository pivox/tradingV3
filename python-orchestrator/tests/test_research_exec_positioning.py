"""r13 (PREREGISTRATION-r13.md): the metrics archive converter, the open-interest features, the O rules,
the price-matched control and the pair-level series check, on synthetic data."""

from __future__ import annotations

import numpy as np
import pytest

from scripts.research_exec import positioning as pos
from scripts.research_exec import screen as sc
from scripts.research_exec.acquire import convert_metrics
from scripts.research_exec.scalp_engine import TRADE_DTYPE
from scripts.research_exec.tick import parse_rule, signal_mask

DAY = 1_790_000_000 - 1_790_000_000 % 86400  # a UTC midnight
HEADER = "create_time,symbol,sum_open_interest,sum_open_interest_value,count_toptrader_long_short_ratio,sum_toptrader_long_short_ratio,count_long_short_ratio,sum_taker_long_short_vol_ratio"


def _row(stamp: str, oi: float) -> str:
    return f"{stamp},ARBUSDT,{oi},{oi * 1.1},2.3,1.7,2.6,1.0"


def test_metrics_converter_sorts_floors_to_the_grid_and_keeps_the_last_duplicate():
    pytest.importorskip("pandas")  # the converters parse with pandas; the research runtime has it, CI may not
    rows = [_row("2024-04-15 00:10:00", 103), _row("2024-04-15 00:00:00", 100), _row("2024-04-15 00:05:01", 101),
            _row("2024-04-15 00:05:00", 102), _row("2024-04-15 00:20:00", 104)]
    arrays, diag = convert_metrics(("\n".join([HEADER] + rows) + "\n").encode())
    midnight = 1713139200  # 2024-04-15 00:00:00 UTC
    assert arrays["ts_s"].tolist() == [midnight, midnight + 300, midnight + 600, midnight + 1200]
    assert arrays["oi"].tolist() == [100.0, 102.0, 103.0, 104.0]  # the off-grid 00:05:01 record is replaced by the later 00:05:00 one
    assert arrays["oi_value"][0] == pytest.approx(110.0) and set(arrays) == {"ts_s", "oi", "oi_value", "top_count_ratio", "top_sum_ratio", "ls_count_ratio", "taker_ratio"}
    assert diag == {"input_already_sorted": False, "off_grid": 1, "duplicates": 1, "missing_steps": 1, "oi_nan": 0}


def test_oi_features_need_the_snapshot_fifteen_minutes_earlier_and_apply_the_lag():
    stamps = DAY + np.array([0, 300, 600, 900, 1200, 1500, 2100])
    oi = np.array([100.0, 100.0, 100.0, 99.0, 97.0, 96.0, 95.0])
    f = pos.oi_features({"ts_s": stamps, "oi": oi}, DAY, DAY + 3000)
    assert np.flatnonzero(f["metrics_tick"]).tolist() == [960, 1260, 1560, 2160]  # T + 60 s for T = 900, 1200, 1500, 2100
    assert f["oi_chg_15"][960] == pytest.approx(-0.01) and f["oi_chg_15"][1260] == pytest.approx(-0.03) and f["oi_chg_15"][2160] == pytest.approx(95 / 97 - 1)
    assert np.isnan(f["oi_chg_15"][~f["metrics_tick"]]).all()
    clipped = pos.oi_features({"ts_s": stamps, "oi": oi}, DAY, DAY + 2100)
    assert np.flatnonzero(clipped["metrics_tick"]).tolist() == [960, 1260, 1560]  # the decision second must lie inside the series
    bad = oi.copy()
    bad[1] = np.nan
    assert np.flatnonzero(pos.oi_features({"ts_s": stamps, "oi": bad}, DAY, DAY + 3000)["metrics_tick"]).tolist() == [960, 1560, 2160]
    assert pos.oi_rule_catalogue() == ["O1-T1-K1.5", "O1-T2-K1.5", "O2-T1-K1.5", "O2-T2-K1.5"]


def _o_features() -> dict[str, np.ndarray]:
    n = 2000
    mid = np.where(np.arange(n) < 1500, 100.0, 98.0)
    tick = np.zeros(n, dtype=bool)
    change = np.full(n, np.nan)
    for second, value in ((500, -0.05), (1660, 0.025), (1960, -0.015)):
        tick[second] = True
        change[second] = value
    return {"mid": mid, "sigma_900": np.full(n, 0.005), "metrics_tick": tick, "oi_chg_15": change}


def test_o_rules_flush_reversal_and_build_up_continuation_on_both_streams():
    f = _o_features()
    assert parse_rule("O1-T1-K1.5") == ("O1", {"T": "1", "K": "1.5"})
    fired = lambda rule, side: np.flatnonzero(signal_mask(rule, f, side)).tolist()
    # Second 1960: T = 1900, the mid fell 2 % over [1000, 1900] (4 sigma) while the open interest fell 1.5 %.
    assert fired("O1-T1-K1.5", 1) == [1960] and fired("O1-T1-K1.5", -1) == [] and fired("O1-T2-K1.5", 1) == []
    # Second 1660: T = 1600, the same fall over [700, 1600] with the open interest up 2.5 % — a build-up: short with the move.
    assert fired("O2-T1-K1.5", -1) == [1660] and fired("O2-T2-K1.5", -1) == [1660] and fired("O2-T1-K1.5", 1) == []
    assert 500 not in fired("O1-T1-K1.5", 1)  # second 500: no price fifteen minutes before T → never a decision


def test_price_matched_control_draws_among_the_same_price_moves_whatever_the_open_interest():
    f = _o_features()
    sc.use_protocol("r13")
    try:
        assert sc.PRICE_CONTROL["enabled"] and sc.SERIES_GROUP["mode"] == "symbol" and not sc.ZONE_CONTROL["enabled"]
        long_candidates = sc._control_candidates("O1-T1-K1.5", f, 1)
        assert np.flatnonzero(long_candidates).tolist() == [1660, 1960]  # both seconds follow a fall; the OI condition is dropped
        assert np.flatnonzero(sc._control_candidates("O1-T1-K1.5", f, -1)).tolist() == []
        assert np.flatnonzero(sc._control_candidates("O2-T2-K1.5", f, -1)).tolist() == [1660, 1960]
    finally:
        sc.use_protocol("r10")
    assert not sc.PRICE_CONTROL["enabled"] and sc.SERIES_GROUP["mode"] is None and sc._control_candidates("O1-T1-K1.5", f, 1) is None


def _result(net: list[float], series: list[int]) -> dict:
    trades = np.zeros(len(net), dtype=TRADE_DTYPE)
    trades["net_bp"] = net
    trades["gross_bp"] = np.asarray(net) + 7.0
    trades["fees_bp"] = 7.0
    trades["series"] = series
    trades["decision_s"] = DAY + 3600 * np.arange(len(net))
    trades["hold_s"] = 3600.0
    counters = {"decisions": len(net), "orders": len(net), "crossed": 0, "unfilled": 0, "filled": len(net)}
    return {"id": "O1-T1-K1.5|both|E1|Z3", "trades": trades, "counters": counters, "per_series": {}, "control_means": [0.0] * 10, "elapsed": 0.0}


def test_series_check_pools_each_pair_windows_under_the_symbol_grouping():
    result = _result([10.0, -4.0, -1.0], [0, 1, 2])
    names = {0: "binance:ARBUSDT:w2404", 1: "binance:ARBUSDT:w2405", 2: "binance:OPUSDT:w2404"}
    sc.SERIES_NAMES.clear()
    sc.SERIES_NAMES.update(names)
    try:
        sc.use_protocol("r13")
        row = sc._summary(result, "binance", True)
        assert row["series_positive_share"] == pytest.approx(0.5) and row["series_count"] == 2 and row["series_unit"] == "symbol"  # ARB +6, OP −1
        sc.use_protocol("r10")
        row = sc._summary(result, "binance", True)
        assert row["series_positive_share"] == pytest.approx(1 / 3) and row["series_count"] == 3 and row["series_unit"] == "series"
    finally:
        sc.SERIES_NAMES.clear()
        sc.use_protocol("r10")


def test_r13_grid_windows_and_validation_block():
    grid = sc.build_grid(False, "r13")
    assert len(grid) == 8 and len(set(grid)) == 8
    assert all(sc.parse_configuration(item)[1] == 0 and sc.parse_configuration(item)[3] in ("Z3", "Y3") for item in grid)
    spec = sc.PROTOCOLS["r13"]
    assert spec["metrics"] and spec["price_control"] and spec["series_group"] == "symbol" and not spec.get("zone_control")
    assert spec["phases"] == {"validation": ("m2410",), "holdout": ("m2411",)} and spec["phase_symbols"]["validation"] == sc.R10_MIDCAPS
    assert spec["gates"] == sc.PROTOCOLS["r10"]["gates"] and spec["validation"]["fee_x_net_positive"] and spec["training_windows"] == sc.PROTOCOLS["r10"]["training_windows"]
    assert sc.BINANCE_WINDOWS["m2410"] == ("2024-10-01", "2024-10-31") and sc.BINANCE_WINDOWS["m2411"] == ("2024-11-01", "2024-11-30")


def test_r14_declares_the_two_build_up_rows_for_october_and_november():
    grid = sc.build_grid(False, "r14")
    assert grid == ["O2-T1-K1.5|both|E1|Y3", "O2-T2-K1.5|both|E1|Y3"]
    assert set(grid) <= set(sc.build_grid(False, "r13"))  # the r13 rows that beat the price-matched control
    spec, origin = sc.PROTOCOLS["r14"], sc.PROTOCOLS["r13"]
    for key in ("phases", "phase_symbols", "metrics", "price_control", "series_group", "gates", "validation", "training_windows"):
        assert spec[key] == origin[key], key
    sc.use_protocol("r14")
    try:
        assert sc.PRICE_CONTROL["enabled"] and sc.SERIES_GROUP["mode"] == "symbol" and sc.VALIDATION["fee_x_net_positive"]
    finally:
        sc.use_protocol("r10")
