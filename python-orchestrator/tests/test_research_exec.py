"""Tests of the execution study: capture extraction, fill rules, mark-outs, statistics."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

import numpy as np
import pytest

from scripts.research_exec import capture, stats
from scripts.research_exec.capture import QUOTE_DTYPE, TRADE_DTYPE, Instrument
from scripts.research_exec.simulate import HORIZONS_S, STATUS, Parameters, decision_grid, simulate_symbol

SECOND = 1_000_000
T0 = 1_790_000_000 * SECOND  # an epoch-aligned instant


def quotes_from(rows: list[tuple]) -> np.ndarray:
    return np.array([(ts, ts, bid, ask, 1.0, 1.0) for ts, bid, ask in rows], dtype=QUOTE_DTYPE)


def trades_from(rows: list[tuple]) -> np.ndarray:
    return np.array([(ts, ts, price, 1.0, taker_buy, 1) for ts, price, taker_buy in rows], dtype=TRADE_DTYPE)


INSTRUMENT = Instrument("BTCUSDT", "BTC-USDT-SWAP", 0.01, 0.01, 0.01, fixed_tick=0.1)
PARAMS = Parameters(grid_s=5.0, latency_ms=250.0, ttl_s=45.0, stale_s=10.0, maker_bp=2.0, taker_bp=5.0)


def flat_book(seconds: int, bid: float = 100.0, ask: float = 100.1) -> np.ndarray:
    return quotes_from([(T0 + s * SECOND, bid, ask) for s in range(seconds)])


def test_parse_timestamp_us_handles_zulu_and_microseconds():
    assert capture.parse_timestamp_us("1970-01-01T00:00:01.000500Z") == 1_000_500
    assert capture.parse_timestamp_us("2026-10-02T13:33:15.458000Z") == 1_790_947_995_458_000
    assert capture.parse_timestamp_us("2026-10-02T13:33:15.458000Z") == capture.parse_timestamp_us("2026-10-02T13:33:15.458000+00:00")


def test_hyperliquid_tick_follows_five_significant_digits():
    instrument = Instrument("BTCUSDT", "BTC", 1.0, 0.0001, 0.0001, significant_digits=5, max_decimals=2)
    assert instrument.tick(86324.0) == pytest.approx(1.0)
    assert instrument.tick(2744.8) == pytest.approx(0.1)
    assert instrument.tick(0.5) == pytest.approx(0.01)  # capped by the maximum decimals
    assert INSTRUMENT.tick(123456.7) == pytest.approx(0.1)


def test_decision_grid_is_epoch_aligned_and_leaves_room_for_horizons():
    quotes = flat_book(400)
    trades = trades_from([(T0, 100.0, 1), (T0 + 399 * SECOND, 100.0, 1)])
    grid = decision_grid(quotes, trades, PARAMS)
    assert grid[0] == T0 and grid[0] % (5 * SECOND) == 0
    assert grid[-1] < T0 + 399 * SECOND - 45 * SECOND - 300 * SECOND


def test_through_rule_needs_a_trade_beyond_the_price_and_touch_rule_accepts_the_touch():
    quotes = flat_book(400)
    # One decision at T0: buy at 100.0 (touch). Trades: at 100.0 by a seller at +0.7 s, below at +1.0 s.
    trades = trades_from([(T0 - SECOND, 100.05, 1), (T0 + 700_000, 100.0, 0), (T0 + SECOND, 99.9, 0), (T0 + 399 * SECOND, 100.0, 1)])
    params = Parameters(**{**PARAMS.to_dict(), "grid_s": 1000.0})  # a single decision in the window
    rows = simulate_symbol(quotes, trades, INSTRUMENT, params)
    buy_touch = rows[(rows["side"] > 0) & (rows["placement"] == 0)]
    assert buy_touch.size == 1 and buy_touch["status"][0] == STATUS["filled"]
    assert buy_touch["fill_ts"][0] == T0 + SECOND  # through: the trade at 99.9
    assert buy_touch["price"][0] == pytest.approx(100.0)
    touch_rows = simulate_symbol(quotes, trades, INSTRUMENT, Parameters(**{**params.to_dict(), "rule": "touch"}))
    buy_touch = touch_rows[(touch_rows["side"] > 0) & (touch_rows["placement"] == 0)]
    assert buy_touch["fill_ts"][0] == T0 + 700_000  # touch: the seller's trade at 100.0
    # The sell at the touch (100.1) is never filled: no trade above 100.1 and no buyer at 100.1.
    sell_touch = rows[(rows["side"] < 0) & (rows["placement"] == 0)]
    assert sell_touch["status"][0] == STATUS["unfilled"]
    # The spread is one tick: no inside placement.
    assert set(rows[rows["placement"] == 1]["status"]) == {STATUS["no_inside"]}


def test_trades_before_activation_do_not_fill():
    quotes = flat_book(400)
    trades = trades_from([(T0 - SECOND, 100.05, 1), (T0 + 100_000, 99.0, 0), (T0 + 399 * SECOND, 100.0, 1)])  # 100 ms after the decision, before activation at 250 ms
    params = Parameters(**{**PARAMS.to_dict(), "grid_s": 1000.0})
    rows = simulate_symbol(quotes, trades, INSTRUMENT, params)
    buy_touch = rows[(rows["side"] > 0) & (rows["placement"] == 0)]
    assert buy_touch["status"][0] == STATUS["unfilled"]
    zero_latency = simulate_symbol(quotes, trades, INSTRUMENT, Parameters(**{**params.to_dict(), "latency_ms": 0.0}))
    assert zero_latency[(zero_latency["side"] > 0) & (zero_latency["placement"] == 0)]["status"][0] == STATUS["filled"]


def test_order_that_would_cross_at_activation_is_cancelled():
    rows_q = [(T0 + s * SECOND, 100.0, 100.1) for s in range(400)]
    rows_q.insert(1, (T0 + 100_000, 99.8, 99.9))  # the market drops 100 ms after the decision
    quotes = quotes_from(sorted(rows_q))
    trades = trades_from([(T0 - SECOND, 100.05, 1), (T0 + 2 * SECOND, 99.0, 0), (T0 + 399 * SECOND, 100.0, 1)])
    params = Parameters(**{**PARAMS.to_dict(), "grid_s": 1000.0})
    rows = simulate_symbol(quotes, trades, INSTRUMENT, params)
    buy_touch = rows[(rows["side"] > 0) & (rows["placement"] == 0)]
    assert buy_touch["status"][0] == STATUS["crossed"]  # a bid at 100.0 against an ask at 99.9


def test_ttl_expiry_and_stale_quotes():
    quotes = quotes_from([(T0, 100.0, 100.1), (T0 + 399 * SECOND, 100.0, 100.1)])  # nothing between: stale after 10 s
    trades = trades_from([(T0 - SECOND, 100.05, 1), (T0 + 50 * SECOND, 99.0, 0), (T0 + 399 * SECOND, 100.0, 1)])
    rows = simulate_symbol(quotes, trades, INSTRUMENT, PARAMS)
    first = rows[(rows["decision_ts"] == T0) & (rows["side"] > 0) & (rows["placement"] == 0)]
    assert first["status"][0] == STATUS["unfilled"]  # the trade at +50 s is after the 45 s TTL
    later = rows[(rows["decision_ts"] == T0 + 20 * SECOND) & (rows["placement"] == 0)]
    assert set(later["status"]) == {STATUS["stale"]}


def test_markouts_and_round_trip_use_the_quote_at_the_horizon():
    rows_q = [(T0 + s * SECOND, 100.0, 100.2) for s in range(400)]
    quotes = quotes_from(rows_q)
    quotes["bid"][quotes["ts"] >= T0 + 2 * SECOND] = 100.4  # from +2 s the book is 100.4 / 100.6
    quotes["ask"][quotes["ts"] >= T0 + 2 * SECOND] = 100.6
    trades = trades_from([(T0 - SECOND, 100.05, 1), (T0 + SECOND, 99.9, 0), (T0 + 399 * SECOND, 100.0, 1)])
    params = Parameters(**{**PARAMS.to_dict(), "grid_s": 1000.0})
    rows = simulate_symbol(quotes, trades, INSTRUMENT, params)
    buy = rows[(rows["side"] > 0) & (rows["placement"] == 0)][0]
    assert buy["status"] == STATUS["filled"] and buy["fill_ts"] == T0 + SECOND
    assert buy["m_0"] == pytest.approx((100.1 - 100.0) / 100.0 * 1e4)  # half-spread before the fill
    assert buy["m_1s"] == pytest.approx((100.5 - 100.0) / 100.0 * 1e4)  # fill at +1 s, horizon at +2 s: the book is 100.4/100.6
    assert buy["m_10s"] == pytest.approx((100.5 - 100.0) / 100.0 * 1e4)
    assert buy["rt_10s"] == pytest.approx((100.4 - 100.0) / 100.0 * 1e4 - 7.0)
    inside = rows[(rows["side"] > 0) & (rows["placement"] == 1)][0]
    assert inside["price"] == pytest.approx(100.1) and inside["status"] == STATUS["filled"]
    assert np.isfinite(buy["d_60s"]) and buy["d_60s"] == pytest.approx((100.5 - 100.1) / 100.1 * 1e4)


def test_block_bootstrap_lower_bound_is_below_the_mean_and_deterministic():
    rng = np.random.default_rng(1)
    values = rng.normal(1.0, 1.0, size=5000)
    moments = T0 + np.arange(5000) * 20 * SECOND
    lower = stats.block_bootstrap_lower_bound(values, moments)
    again = stats.block_bootstrap_lower_bound(values, moments)
    assert lower == again and lower < values.mean()
    assert stats.block_bootstrap_lower_bound(np.array([np.nan]), np.array([T0])) is None


def test_summary_and_verdict_shapes():
    quotes = flat_book(400)
    trades = trades_from([(T0 + s * SECOND + 500_000, 99.9 if s % 2 else 100.2, 0 if s % 2 else 1) for s in range(399)])
    rows = simulate_symbol(quotes, trades, INSTRUMENT, PARAMS)
    summary = stats.summarize_symbol(rows, 2.0)
    pooled = summary["touch"]["pooled"]
    assert pooled["filled"] > 0 and 0 < pooled["fill_rate"] <= 1
    assert set(pooled["horizons"]) == {str(h) for h in HORIZONS_S}
    result = stats.verdict({"BTCUSDT": summary}, "through")
    assert result["symbols"]["BTCUSDT"]["fill_rate_ok"] is True
    assert isinstance(result["maker_edge"], bool)


def _write_dataset(root: Path, events: list[dict]) -> None:
    lines = [json.dumps(event, separators=(",", ":")).encode() + b"\n" for event in events]
    (root / "events.ndjson").write_bytes(b"".join(lines))
    manifest = {
        "dataset_id": "synthetic", "source_venue": "okx", "source_network": "mainnet", "state": "complete", "sequence_gaps": [],
        "event_count": len(lines), "events_file_sha256": hashlib.sha256(b"".join(lines)).hexdigest(), "symbols": {"BTCUSDT": "BTC-USDT-SWAP"},
    }
    (root / "manifest.json").write_text(json.dumps(manifest))


def _event(channel: str, ts: str, payload: dict, symbol: str = "BTCUSDT") -> dict:
    return {"channel": channel, "symbol": symbol, "exchange_timestamp": ts, "received_timestamp": ts, "payload": payload}


def test_extract_reads_quotes_and_trades_sorted_and_verifies_the_hash(tmp_path: Path):
    events = [
        _event("instrument_metadata", "2026-10-02T13:33:08.000000Z", {"native_symbol": "BTC-USDT-SWAP", "price_tick": "0.1", "contract_value": "0.01", "minimum_quantity": "0.01", "quantity_step": "0.01"}),
        _event("top_of_book", "2026-10-02T13:33:10.000000Z", {"origin": "ws_books", "bid_price": "100.0", "ask_price": "100.1", "bid_size_contracts": "5", "ask_size_contracts": "7"}),
        _event("public_trade", "2026-10-02T13:33:09.500000Z", {"origin": "rest_recovery", "price": "100.0", "size_contracts": "2", "taker_side": "sell"}),
        _event("public_trade", "2026-10-02T13:33:11.000000Z", {"origin": "ws_aggregated", "price": "100.1", "size_contracts": "1", "taker_side": "buy"}),
        _event("top_of_book", "2026-10-02T13:33:12.000000Z", {"origin": "ws_books", "bid_price": "100.0", "ask_price": "100.1", "synthetic": True}),
        _event("top_of_book", "2026-10-02T13:33:13.000000Z", {"origin": "ws_books", "bid_price": "100.2", "ask_price": "100.1"}),
        _event("snapshot_boundary", "2026-10-02T13:33:08.100000Z", {"reason": "initial", "source_epoch": 1}),
        _event("candle_1m", "2026-10-02T13:33:00.000000Z", {"open": "1"}),
    ]
    _write_dataset(tmp_path, events)
    extracted = capture.extract(tmp_path)
    quotes, trades = extracted.quotes["BTCUSDT"], extracted.trades["BTCUSDT"]
    assert quotes.size == 1 and quotes["bid_size"][0] == pytest.approx(0.05)  # contracts × contract value
    assert trades.size == 2 and list(trades["taker_buy"]) == [0, 1] and trades["ts"][0] < trades["ts"][1]
    assert trades["live"].tolist() == [0, 1] and trades["size"][0] == pytest.approx(0.02)
    assert extracted.receipt["synthetic_quotes_skipped"] == 1 and extracted.receipt["crossed_quotes_skipped"] == 1
    assert extracted.receipt["snapshot_boundaries"][0]["reason"] == "initial"
    saved = tmp_path / "extract.npz"
    capture.save_extract(extracted, saved)
    loaded = capture.load_extract(saved)
    assert loaded.instruments["BTCUSDT"].tick(100.0) == pytest.approx(0.1) and loaded.trades["BTCUSDT"].size == 2
    # A modified events file fails closed.
    with (tmp_path / "events.ndjson").open("ab") as handle:
        handle.write(b"\n")
    with pytest.raises(ValueError):
        capture.extract(tmp_path)
