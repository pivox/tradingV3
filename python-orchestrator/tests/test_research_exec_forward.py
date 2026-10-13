"""Tests of the forward OKX capture (`forward_okx.py`): reductions, layout, and the fwd-okx-r1 protocol."""

from __future__ import annotations

import json
from pathlib import Path

import numpy as np
import pytest

from scripts.research_exec import forward_okx as fwd
from scripts.research_exec.screen import BINANCE_WINDOWS, PROTOCOLS, build_grid, load_binance_series
from scripts.research_exec.tick import depth_features, parse_rule

T0_MS = 1_792_000_000_000  # 2026-10-14T20:26:40Z


def test_symbol_mapping_round_trips_and_rejects_other_markets():
    assert fwd.inst_id("BTCUSDT") == "BTC-USDT-SWAP" and fwd.symbol_of("SEI-USDT-SWAP") == "SEIUSDT"
    with pytest.raises(ValueError):
        fwd.inst_id("BTCUSD")
    with pytest.raises(ValueError):
        fwd.symbol_of("BTC-USD-SWAP")
    assert fwd.date_of_day(fwd.day_of_ms(T0_MS)) == "2026-10-14"
    assert len(fwd.DEFAULT_SYMBOLS) == 20 and len(set(fwd.DEFAULT_SYMBOLS)) == 20


def test_second_reducer_matches_the_bookticker_reduction():
    reducer = fwd.SecondReducer()
    reducer.push(T0_MS + 100, 100.0, 2.0, 100.1, 1.0)   # second 0: qi = 1/3
    reducer.push(T0_MS + 900, 100.0, 1.0, 100.1, 3.0)   # second 0: qi = -1/2, last of the second
    reducer.push(T0_MS + 2500, 100.2, 4.0, 100.3, 4.0)  # second 2 (second 1 has no update)
    partial = reducer.arrays(include_open=True)
    assert partial["ts_s"].tolist() == [T0_MS // 1000, T0_MS // 1000 + 2] and partial["updates"].tolist() == [2, 1]
    assert partial["bid"].tolist() == [100.0, 100.2] and partial["ask_qty"].tolist() == [3.0, 4.0]
    assert partial["qi_mean"][0] == pytest.approx((1 / 3 - 1 / 2) / 2) and partial["qi_mean"][1] == pytest.approx(0.0)
    closed = reducer.arrays(include_open=False)
    assert closed["ts_s"].tolist() == [T0_MS // 1000]  # the open second is not flushed until close()
    reducer.close()
    assert reducer.arrays(include_open=False)["ts_s"].tolist() == [T0_MS // 1000, T0_MS // 1000 + 2]


def test_trade_buffer_sorts_by_time_then_id_and_drops_duplicate_ids():
    buffer = fwd.TradeBuffer()
    buffer.push(T0_MS + 5, 10.0, 1.0, True, 7)
    buffer.push(T0_MS + 1, 10.1, 2.0, False, 5)
    buffer.push(T0_MS + 1, 10.2, 3.0, True, 4)
    buffer.push(T0_MS + 5, 10.0, 1.0, True, 7)  # duplicate (REST recovery overlap)
    arrays = buffer.arrays()
    assert arrays["agg_trade_id"].tolist() == [4, 5, 7] and arrays["ts_us"].tolist() == [(T0_MS + 1) * 1000, (T0_MS + 1) * 1000, (T0_MS + 5) * 1000]
    assert arrays["price"].tolist() == [10.2, 10.1, 10.0] and arrays["taker_buy"].tolist() == [1, 0, 1] and arrays["size"].tolist() == [3.0, 2.0, 1.0]
    assert arrays["taker_buy"].dtype == np.int8 and arrays["ts_us"].dtype == np.int64


def test_depth_bands_cumulate_notional_within_each_percentage_and_report_coverage():
    mid = 100.0
    bids = np.array([[99.95, 1.0], [99.5, 2.0], [98.5, 3.0], [97.0, 4.0], [96.0, 5.0], [94.0, 6.0]])
    asks = np.array([[100.05, 1.0], [100.5, 1.0], [101.5, 1.0], [103.0, 1.0], [104.0, 1.0]])
    bands = fwd.depth_bands(bids, asks)
    assert bands["mid"] == pytest.approx(mid)
    # within 1 %: 99.95×1 + 99.5×2; within 2 %: + 98.5×3; 3 %: + 97×4; 4 %: + 96×5; 5 %: the 94 level (6 % away) stays out.
    assert bands["bid"] == pytest.approx([99.95 + 199.0, 99.95 + 199.0 + 295.5, 99.95 + 199.0 + 295.5 + 388.0, 99.95 + 199.0 + 295.5 + 388.0 + 480.0, 99.95 + 199.0 + 295.5 + 388.0 + 480.0])
    # asks: 100.05 (0.05 %), 100.5 (0.5 %), 101.5 (1.5 %), 103 (3 %, inside the 3 % band), 104 (4 %).
    assert bands["ask"] == pytest.approx([200.55, 302.05, 405.05, 509.05, 509.05])
    assert bands["coverage_bid_pct"] == pytest.approx(6.0) and bands["coverage_ask_pct"] == pytest.approx(4.0)
    assert bands["levels_bid"] == 6 and bands["levels_ask"] == 5
    with pytest.raises(ValueError):
        fwd.depth_bands(np.zeros((0, 2)), asks)


def test_depth_buffer_feeds_the_r8_depth_features():
    buffer = fwd.DepthBuffer()
    for k in range(4):
        buffer.push(T0_MS // 1000 + 30 * k, {"bid": [2.0, 3.0, 4.0, 5.0, 6.0], "ask": [1.0, 1.0, 1.0, 1.0, 1.0], "coverage_bid_pct": 0.8, "coverage_ask_pct": 2.0})
    arrays = buffer.arrays()
    assert arrays["bid"].shape == (4, 5) and arrays["coverage_bid_pct"].tolist() == [0.8] * 4
    features = depth_features(T0_MS // 1000 + np.arange(0, 120), arrays)
    assert features["di_1"][0] == pytest.approx(1 / 3) and features["di_2"][45] == pytest.approx(0.5)
    assert np.isfinite(features["di_1"]).all()


def test_instrument_record_converts_contracts_to_base_units():
    raw = {"instId": "XRP-USDT-SWAP", "ctVal": "100", "lotSz": "0.01", "minSz": "0.01", "tickSz": "0.0001", "maxMktSz": "10000", "maxLmtSz": "20000", "state": "live"}
    record = fwd.instrument_record(raw, mid=2.5)
    assert record["symbol"] == "XRPUSDT" and record["contract_size"] == 1.0 and record["tick_size"] == 0.0001
    assert record["min_quantity"] == pytest.approx(1.0) and record["quantity_step"] == pytest.approx(1.0) and record["min_notional"] == pytest.approx(2.5)
    assert record["max_quantity"] == pytest.approx(1_000_000.0)


def test_tape_store_writes_the_three_layouts_and_the_screen_loads_them(tmp_path: Path):
    store = fwd.TapeStore(tmp_path, ("BTCUSDT",))
    day_ms = (fwd.day_of_ms(T0_MS)) * fwd.DAY_S * 1000  # midnight of the day of T0
    for s in range(0, 600):
        ts = day_ms + s * 1000
        bucket = store.bucket("BTCUSDT", ts)
        bucket.book.push(ts + 100, 100.0, 1.0, 100.1, 2.0)
        bucket.trades.push(ts + 200, 100.0 if s % 2 else 100.1, 0.5, bool(s % 2), s)
        if s % 30 == 0:
            bucket.depth.push(ts // 1000, {"bid": [2.0] * 5, "ask": [1.0] * 5, "coverage_bid_pct": 0.9, "coverage_ask_pct": 2.0})
    # A print of the next day opens a second bucket; finalizing "days before" closes only the first one.
    store.bucket("BTCUSDT", day_ms + fwd.DAY_S * 1000 + 5).trades.push(day_ms + fwd.DAY_S * 1000 + 5, 101.0, 1.0, True, 10_000)
    assert store.checkpoint() == 2
    date = fwd.date_of_day(fwd.day_of_ms(T0_MS))
    manifest = json.loads((tmp_path / "manifest-forward.json").read_text())
    assert manifest["files"][f"BTCUSDT:{date}"]["partial"] is True and manifest["files"][f"BTCUSDT:{date}"]["npz"]["npz_sha256"] is None
    done = store.finalize_days_before(fwd.day_of_ms(T0_MS) + 1)
    assert done == [f"BTCUSDT:{date}"] and list(store.days) == [("BTCUSDT", fwd.day_of_ms(T0_MS) + 1)]
    entry = store.manifest["files"][f"BTCUSDT:{date}"]
    assert entry["partial"] is False and entry["npz"]["rows"] == 600 and entry["book"]["rows"] == 600 and entry["depth"]["rows"] == 20
    assert entry["depth"]["coverage_bid_pct_min"] == pytest.approx(0.9) and entry["depth"]["gap_s_max"] == 30 and len(entry["npz"]["npz_sha256"]) == 64
    for folder in ("npz", "book", "depth"):
        assert (tmp_path / folder / "BTCUSDT" / f"BTCUSDT-{date}.npz").exists()
    with np.load(tmp_path / "book" / "BTCUSDT" / f"BTCUSDT-{date}.npz") as data:
        assert set(data.files) == {"ts_s", "bid", "ask", "bid_qty", "ask_qty", "updates", "qi_mean"}
    with np.load(tmp_path / "npz" / "BTCUSDT" / f"BTCUSDT-{date}.npz") as data:
        assert set(data.files) == {"ts_us", "price", "size", "taker_buy", "agg_trade_id"}
    # The screen's tape loader reads the day as a series with real quotes and depth.
    instruments = tmp_path / "instruments.json"
    instruments.write_text(json.dumps({"symbols": [fwd.instrument_record({"instId": "BTC-USDT-SWAP", "ctVal": "0.01", "lotSz": "0.01", "minSz": "0.01", "tickSz": "0.1", "state": "live"}, 100.0)]}))
    BINANCE_WINDOWS["_test_fwd"] = (date, date)
    try:
        series, missing = load_binance_series(tmp_path, instruments, "validation", ("BTCUSDT",), ("_test_fwd",), depth=True, book=True, venue="okx")
    finally:
        del BINANCE_WINDOWS["_test_fwd"]
    assert missing == [] and len(series) == 1 and series[0].name == f"okx:BTCUSDT:_test_fwd" and series[0].book is True
    quotes, trades, depth = series[0].loader()
    assert quotes.size == 600 and quotes["bid_size"][0] == pytest.approx(1.0) and trades.size == 600 and depth["bid"].shape == (20, 5)


def test_fwd_okx_protocol_is_one_frozen_long_configuration_on_twenty_pairs():
    spec = PROTOCOLS["fwd-okx-r1"]
    assert build_grid(True, "fwd-okx-r1") == ["Q3-W900-K1.5-T0.1|long|E1|Z3"]
    assert parse_rule(spec["rules"][0]) == ("Q3", {"W": "900", "K": "1.5", "T": "0.1"})
    assert spec["depth"] and spec["book"] and spec["venue"] == "okx" and len(spec["symbols"]) == 20
    assert set(spec["symbols"]) == set(fwd.DEFAULT_SYMBOLS)
    assert spec["training_windows"] == ("fwd-okx-w0",) and spec["phases"] == {"validation": ("fwd-okx-w1",), "holdout": ("fwd-okx-w2",)}
    assert BINANCE_WINDOWS["fwd-okx-w1"] == ("2026-10-19", "2026-10-23") and BINANCE_WINDOWS["fwd-okx-w2"] == ("2026-10-26", "2026-10-30")
    assert spec["validation"] == PROTOCOLS["r8"]["validation"] and spec["gates"] == PROTOCOLS["r8"]["gates"]
