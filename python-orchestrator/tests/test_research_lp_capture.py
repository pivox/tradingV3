"""Tests of the `lp` collector (`scripts/research_lp/{tape,xperp_capture}.py`) on hand-written
WebSocket frames and a local fake server: parsing, counting mode (no value written), the
not-before guard and its quarantine floor, SIGTERM handling, the segment store's resume, the
lock, the manifest hashes. No network: an autouse guard refuses every connection and name resolution (CTO review-b m4); the two
fake-server tests opt in explicitly (`@pytest.mark.usefixtures("allow_loopback_only")`) and may then reach 127.0.0.1 / ::1 only."""

from __future__ import annotations

import asyncio
import json
import os
import signal
import socket
import time
import urllib.request
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import cli, tape
from scripts.research_lp import xperp_capture as xc

INST = "BTC-USD_UM_XPERP-310404"
OTHER = "ETH-USD_UM_XPERP-310404"
NB_US = tape.parse_iso_utc(xc.DEFAULT_NOT_BEFORE)  # 2026-11-02T00:00:00Z = the quarantine floor
DAY_MS = (NB_US // tape.SECOND_US + 3 * tape.DAY_S) * 1000  # 2026-11-05T00:00:00Z, in ms
T0_MS = DAY_MS + 10 * 3600 * 1000  # 10:00 UTC
PAST = "2026-01-01T00:00:00Z"  # a not-before already passed on the test's wall clock; below the floor → test hook required
FAR = "2099-01-01T00:00:00Z"  # above the floor, never reached by the wall clock


# ----------------------------------------------------------------------------- no network (CTO review-b m4)

LOOPBACK_HOSTS = ("127.0.0.1", "::1", "localhost")


@pytest.fixture
def allow_loopback_only():
    """Explicit opt-in, `@pytest.mark.usefixtures("allow_loopback_only")` (CTO m4): the test may reach 127.0.0.1 / ::1 only (a fake server
    of the test itself), never resolve or reach any other host."""
    return LOOPBACK_HOSTS


@pytest.fixture(autouse=True)
def _no_network(request, monkeypatch):
    """No connection and no name resolution: socket connect / connect_ex / sendto, create_connection, getaddrinfo and urlopen fail
    loudly — except loopback addresses for a test that opts in with `allow_loopback_only`."""
    loopback = "allow_loopback_only" in request.fixturenames
    real = {"connect": socket.socket.connect, "connect_ex": socket.socket.connect_ex, "sendto": socket.socket.sendto, "getaddrinfo": socket.getaddrinfo,
            "create_connection": socket.create_connection}

    def local(address) -> bool:
        return loopback and isinstance(address, tuple) and len(address) >= 2 and str(address[0]) in LOOPBACK_HOSTS

    def guarded(name):
        def method(self, *args):
            address = args[-1] if name == "sendto" else args[0]
            if not local(address):
                raise AssertionError(f"network access attempted: {name} {address!r}")
            return real[name](self, *args)
        return method

    def getaddrinfo(host, *args, **kwargs):
        if not (loopback and str(host) in LOOPBACK_HOSTS):
            raise AssertionError(f"name resolution attempted: {host!r}")
        return real["getaddrinfo"](host, *args, **kwargs)

    def create_connection(address, *args, **kwargs):
        if not local(address):
            raise AssertionError(f"network access attempted: create_connection {address!r}")
        return real["create_connection"](address, *args, **kwargs)

    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError("network access attempted: urlopen")

    for name in ("connect", "connect_ex", "sendto"):
        monkeypatch.setattr(socket.socket, name, guarded(name))
    monkeypatch.setattr(socket, "getaddrinfo", getaddrinfo)
    monkeypatch.setattr(socket, "create_connection", create_connection)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)


def frame_trades(inst, ts_ms, px, sz, side, trade_id, count=1):
    return json.dumps({"arg": {"channel": "trades", "instId": inst}, "data": [{"instId": inst, "tradeId": str(trade_id), "px": str(px), "sz": str(sz), "side": side, "ts": str(ts_ms), "count": str(count)}]})


def frame_books5(inst, ts_ms, bids, asks, seq):
    return json.dumps({"arg": {"channel": "books5", "instId": inst}, "data": [{"asks": [[str(p), str(s), "0", "1"] for p, s in asks], "bids": [[str(p), str(s), "0", "1"] for p, s in bids], "instId": inst, "ts": str(ts_ms), "seqId": seq}]})


def frame_liq(inst, ts_ms, side, pos_side, sz, bk_px=""):
    return json.dumps({"arg": {"channel": "liquidation-orders", "instType": "FUTURES"}, "data": [{"details": [{"bkLoss": "0", "bkPx": bk_px, "ccy": "", "posSide": pos_side, "side": side, "sz": str(sz), "ts": str(ts_ms)}],
                                                                                                "instFamily": "BTC-USD_UM", "instId": inst, "instType": "FUTURES", "uly": "BTC-USD"}]})


def frame_mark(inst, ts_ms, px):
    return json.dumps({"arg": {"channel": "mark-price", "instId": inst}, "data": [{"instType": "FUTURES", "instId": inst, "markPx": str(px), "ts": str(ts_ms)}]})


def frame_funding(inst, ts_ms, rate, funding_time_ms, sett_state="processing", sett_rate=""):
    return json.dumps({"arg": {"channel": "funding-rate", "instId": inst}, "data": [{"fundingRate": str(rate), "fundingTime": str(funding_time_ms), "instId": inst, "instType": "FUTURES", "method": "next_period",
                                                                                     "maxFundingRate": "0.00375", "minFundingRate": "-0.00375", "nextFundingRate": "", "nextFundingTime": str(funding_time_ms + 8 * 3600 * 1000),
                                                                                     "premium": "0.0000123", "settFundingRate": sett_rate, "settState": sett_state, "ts": str(ts_ms)}]})


SUBSCRIBED = json.dumps({"event": "subscribe", "arg": {"channel": "trades", "instId": INST}, "connId": "a4d3ae55"})
ERROR = json.dumps({"event": "error", "code": "60012", "msg": "Invalid request: {\"op\": \"subscribe\"}", "connId": "a4d3ae55"})

PRICE_MARKER = "67123.5"  # the value that must never be written in counting mode
SIZE_MARKER = "0.37"


def recorded_frames():
    return [
        SUBSCRIBED,
        frame_books5(INST, T0_MS, [(67123.4, 12), (67123.3, 5)], [(67123.5, 8), (67123.6, 3)], 1001),
        frame_trades(INST, T0_MS + 120, PRICE_MARKER, SIZE_MARKER, "buy", 900001, 2),
        frame_trades(INST, T0_MS + 350, "67123.4", "1.5", "sell", 900002),
        frame_liq(INST, T0_MS + 500, "sell", "long", "4", "66900.1"),
        frame_liq(OTHER, T0_MS + 600, "buy", "short", "20"),
        frame_mark(INST, T0_MS + 700, "67123.45"),
        frame_funding(INST, T0_MS + 800, "0.0001", DAY_MS + 16 * 3600 * 1000),
        "pong",
        ERROR,
        "not json at all",
    ]


# ----------------------------------------------------------------------------- parsing


def test_parse_frames_and_rows_for_every_channel():
    recv = 123
    parsed = xc.parse_frame(frame_trades(INST, T0_MS, "100.5", "3", "sell", 7, 2))
    assert parsed.kind == "data" and parsed.channel == "trades" and parsed.inst_id == INST
    rows = xc.rows_from_data("trades", parsed.inst_id, parsed.data, recv)
    assert rows == [(INST, T0_MS * 1000, (T0_MS * 1000, recv, 100.5, 3.0, 0, 7, 2))]
    parsed = xc.parse_frame(frame_books5(INST, T0_MS, [(100.0, 1), (99.9, 2)], [(100.1, 3)], 55))
    (inst, ts, row), = xc.rows_from_data("books5", parsed.inst_id, parsed.data, recv)
    assert inst == INST and ts == T0_MS * 1000 and row[2] == 55
    assert row[3][:2] == [100.0, 99.9] and np.isnan(row[3][2]) and row[4] == [1.0, 2.0, 0.0, 0.0, 0.0] and row[5][0] == 100.1 and row[6] == [3.0, 0.0, 0.0, 0.0, 0.0]
    parsed = xc.parse_frame(frame_liq(OTHER, T0_MS, "buy", "short", "20"))
    assert parsed.inst_id == "" and parsed.inst_type == "FUTURES"
    (inst, ts, row), = xc.rows_from_data("liquidation-orders", parsed.inst_id, parsed.data, recv)
    assert inst == OTHER and row[2] == 1 and row[3] == -1 and row[4] == 20.0 and np.isnan(row[5]) and row[6] == 0.0
    parsed = xc.parse_frame(frame_mark(INST, T0_MS, "101.25"))
    assert xc.rows_from_data("mark-price", parsed.inst_id, parsed.data, recv)[0][2] == (T0_MS * 1000, recv, 101.25)
    parsed = xc.parse_frame(frame_funding(INST, T0_MS, "0.0002", T0_MS + 1000, "settled", "0.00015"))
    (inst, ts, row), = xc.rows_from_data("funding-rate", parsed.inst_id, parsed.data, recv)
    assert row[2] == (T0_MS + 1000) * 1000 and row[3] == 0.0002 and row[7] == 0.00015 and row[8] == 1 and row[9] == 1 and row[10] == 0.00375
    assert xc.parse_frame("pong").kind == "pong" and xc.parse_frame(b"pong").kind == "pong"
    assert xc.parse_frame(SUBSCRIBED).kind == "event" and xc.parse_frame(ERROR).event["code"] == "60012"
    assert xc.parse_frame("not json at all").kind == "unknown" and xc.parse_frame("[1,2]").kind == "unknown"
    # The counting path reads ids and clocks only.
    parsed = xc.parse_frame(frame_liq(OTHER, T0_MS, "buy", "short", "20"))
    assert xc.timestamps_from_data("liquidation-orders", "", parsed.data) == [(OTHER, T0_MS * 1000)]
    row = {"ts_us": 0}
    assert tape.arrays_to_rows("trades", {"ts_us": np.array([1]), "recv_us": np.array([2]), "price": np.array([1.0]), "size": np.array([1.0]), "taker_buy": np.array([1]), "trade_id": np.array([3])})["count"].tolist() == [1]


def test_instrument_families_and_subscriptions():
    rows = [{"instId": INST, "state": "live", "ruleType": "xperp"}, {"instId": "SOL-USD_UM_XPERP-310404", "state": "suspend", "ruleType": "xperp"},
            {"instId": "BTC-USD_UM-261225", "state": "live", "ruleType": "normal"}, {"instId": "BTC-USDT-SWAP", "state": "live"}, {"instId": "BTC-USD-SWAP", "state": "live"}]
    assert [r["instId"] for r in xc.select_instruments(rows, "xperp")] == [INST]
    assert [r["instId"] for r in xc.select_instruments(rows, "xperp", only_live=False)] == [INST, "SOL-USD_UM_XPERP-310404"]
    assert [r["instId"] for r in xc.select_instruments(rows, "dated")] == ["BTC-USD_UM-261225"]
    assert [r["instId"] for r in xc.select_instruments(rows, "all")] == ["BTC-USD_UM-261225", INST]  # never a SWAP
    assert xc.family_of("BTC-USDT-SWAP") == "other" and xc.family_of(INST) == "xperp"
    with pytest.raises(ValueError):
        xc.select_instruments(rows, "swap")
    args = xc.subscription_args([INST, OTHER], xc.CHANNELS)
    # Adapted 2026-10-21 (D10): `index-tickers` adds one argument per index id (BTC-USD, ETH-USD) to the 2 × 4 + 1 of before.
    assert len(args) == 2 * 4 + 1 + 2 and {"channel": "liquidation-orders", "instType": "FUTURES"} in args and {"channel": "books5", "instId": OTHER} in args
    assert xc.resolve_host("eea") == xc.WS_PUBLIC_EEA and xc.resolve_host("global") == xc.WS_PUBLIC_GLOBAL and xc.resolve_host("ws://127.0.0.1:1") == "ws://127.0.0.1:1"
    assert xc.WS_PUBLIC_EEA == "wss://wseea.okx.com/ws/v5/public"  # the documented EEA form carries no port (LP-DATA-DESK §0.1)
    # Bytes on the wire: a text frame counts UTF-8 bytes, not characters.
    assert xc.frame_nbytes("é") == 2 and xc.frame_nbytes(b"ab") == 2 and xc.frame_nbytes("pong") == 4
    # Rows without a seqId are counted (presence only, no value read).
    parsed = xc.parse_frame(frame_books5(INST, T0_MS, [(1.0, 1)], [(1.1, 1)], 5))
    assert xc.missing_seq_ids("books5", parsed.data) == 0 and xc.missing_seq_ids("trades", parsed.data) == 0
    no_seq = json.loads(frame_books5(INST, T0_MS, [(1.0, 1)], [(1.1, 1)], 5))
    del no_seq["data"][0]["seqId"]
    assert xc.missing_seq_ids("books5", xc.parse_frame(json.dumps(no_seq)).data) == 1
    (_, _, row), = xc.rows_from_data("books5", INST, no_seq["data"], 1)
    assert row[2] == -1


def test_instruments_file_accepts_ids_rows_and_saved_responses(tmp_path: Path):
    (tmp_path / "ids.json").write_text(json.dumps([INST, OTHER]))
    (tmp_path / "resp.json").write_text(json.dumps({"code": "0", "data": [{"instId": INST, "state": "live", "tickSz": "0.1", "ctVal": "0.0001"}]}))
    assert [r["instId"] for r in xc.load_instruments_file(tmp_path / "ids.json")] == [INST, OTHER]
    assert xc.load_instruments_file(tmp_path / "resp.json")[0]["tickSz"] == "0.1"
    spec = xc.instrument_spec_row({"instId": INST, "ctVal": "0.0001", "tickSz": "0.1", "state": "live", "listTime": "1"})
    assert spec["family"] == "xperp" and "px" not in spec and "last" not in spec


# ----------------------------------------------------------------------------- counting mode


def test_counting_mode_persists_no_value(tmp_path: Path, monkeypatch):
    def boom(*args, **kwargs):  # the value path must never run in counting mode
        raise AssertionError("rows_from_data called in counting mode")

    monkeypatch.setattr(xc, "rows_from_data", boom)
    cfg = xc.CaptureConfig(out_dir=tmp_path, inst_ids=(INST, OTHER), counting_only=True, not_before="2099-01-01T00:00:00Z", echo=False)
    capture = xc.Capture(cfg)  # the not-before guard is exempt in counting mode (no value stored)
    for raw in recorded_frames() * 3:
        capture.handle_raw(raw, recv_us=NB_US)
    capture.checkpoint()
    capture.close()
    counting = json.loads((tmp_path / "counting.json").read_text())
    assert counting["contains_values"] is False
    assert counting["by_channel"]["trades"] == {"instruments": 1, "messages": 6, "rows": 6, "bytes": counting["by_channel"]["trades"]["bytes"]}
    assert counting["by_channel"]["liquidation-orders"]["instruments"] == 2 and counting["unknown_frames"] == 3 and counting["pongs"] == 3
    cell = next(c for c in counting["cells"] if c["channel"] == "trades")
    assert cell["rows_per_hour"][10] == 6 and cell["max_gap_us"] == 230_000 and cell["first_ts"].startswith("2026-11-05T10:00:00.120")
    assert cell["bytes"] == 3 * sum(len(f.encode()) for f in recorded_frames() if '"channel": "trades"' in f and '"event"' not in f)  # UTF-8 bytes of the two data frames
    assert next(c for c in counting["cells"] if c["channel"] == "books5")["missing_seq_id"] == 0
    assert counting["events"] == {"subscribe": 3, "error": 3}
    # No layout directory, no segment, no value anywhere under the output directory.
    assert not any((tmp_path / layout).exists() for layout in tape.LAYOUTS)
    for path in tmp_path.rglob("*"):
        if path.is_file():
            text = path.read_text(errors="replace")
            assert PRICE_MARKER not in text and SIZE_MARKER not in text and "66900.1" not in text and "67123.45" not in text, path
    manifest = json.loads((tmp_path / tape.MANIFEST_NAME).read_text())
    assert manifest["mode"] == "counting" and len(manifest["errors"]) == 3 and manifest["sessions"][0]["inst_count"] == 2
    health = (tmp_path / "health.log").read_text()
    assert "event=started mode=counting" in health and "event=ws_error" in health and "event=checkpoint" in health and "event=stopped" in health


# ----------------------------------------------------------------------------- not-before guard


def test_not_before_guard_refuses_a_value_capture_before_the_date(tmp_path: Path):
    guard = xc.NotBeforeGuard(NB_US, counting_only=False)
    with pytest.raises(xc.NotBeforeViolation):
        guard.assert_may_start(NB_US - 1)
    guard.assert_may_start(NB_US)  # exactly at the instant: allowed
    assert xc.NotBeforeGuard(NB_US, counting_only=True).assert_may_start(NB_US - 10 ** 12) is None
    # The default is the quarantine floor; a not-before still in the future refuses to build, exit code 2, nothing written.
    assert xc.CaptureConfig(out_dir=tmp_path, inst_ids=(INST,)).not_before == xc.QUARANTINE_FLOOR == "2026-11-02T00:00:00Z"
    cfg = xc.CaptureConfig(out_dir=tmp_path / "v", inst_ids=(INST,), counting_only=False, not_before=FAR, echo=False)
    with pytest.raises(xc.NotBeforeViolation):
        xc.Capture(cfg)
    assert xc.run_capture(cfg) == 2 and not (tmp_path / "v").exists()


def test_quarantine_floor_refuses_any_not_before_below_it_in_every_mode(tmp_path: Path):
    """M1: the floor is a constant, not a parameter — only the explicit test hook goes below it, never the CLI."""
    for counting in (False, True):
        cfg = xc.CaptureConfig(out_dir=tmp_path / f"m{int(counting)}", inst_ids=(INST,), counting_only=counting, not_before="2026-10-15T00:00:00Z", echo=False)
        with pytest.raises(xc.NotBeforeBelowFloor):
            xc.Capture(cfg)
        assert xc.run_capture(cfg) == 2 and not (tmp_path / f"m{int(counting)}").exists()  # nothing written, not even the lock
    # One microsecond below the floor is refused; the floor itself passes the floor check (the value guard then applies).
    with pytest.raises(xc.NotBeforeBelowFloor):
        xc.NotBeforeGuard(NB_US - 1, counting_only=True).assert_above_floor()
    xc.NotBeforeGuard(NB_US, counting_only=False).assert_above_floor()
    # The command line has no way around it: `capture` and `count` exit 2 before any REST call or write.
    for command in ("capture", "count"):
        out = tmp_path / command
        assert cli.main([command, "--out", str(out), "--inst-ids", INST, "--not-before", "2026-10-31T23:59:59Z"]) == 2
        assert not out.exists()
    # The test hook lets a synthetic fixture use a past date; the session discloses it in the manifest.
    hooked = xc.Capture(xc.CaptureConfig(out_dir=tmp_path / "hook", inst_ids=(INST,), counting_only=True, not_before=PAST, echo=False, floor_override_for_tests=True))
    hooked.close()
    session = json.loads((tmp_path / "hook" / tape.MANIFEST_NAME).read_text())["sessions"][0]
    assert session["floor_override_for_tests"] is True and session["quarantine_floor"] == xc.QUARANTINE_FLOOR and session["checkpoint_every_s"] == 600.0


def test_rows_before_not_before_are_dropped_even_when_the_capture_may_run(tmp_path: Path):
    nb = "2026-01-02T00:00:00Z"
    nb_ms = tape.parse_iso_utc(nb) // 1000
    cfg = xc.CaptureConfig(out_dir=tmp_path, inst_ids=(INST,), counting_only=False, not_before=nb, echo=False, floor_override_for_tests=True)
    assert cfg.checkpoint_s == 90.0  # value-mode default (M4): the RAM window a SIGKILL could lose
    capture = xc.Capture(cfg)
    capture.handle_raw(frame_trades(INST, nb_ms - 1000, "1.0", "1", "buy", 1), recv_us=1)  # 2026-01-01T23:59:59Z: dropped
    capture.handle_raw(frame_trades(INST, nb_ms, "1.0", "1", "buy", 2), recv_us=1)  # at the instant: kept
    capture.handle_raw(frame_books5(INST, nb_ms - 5, [(1.0, 1)], [(1.1, 1)], 3), recv_us=1)  # dropped
    assert capture.guard.dropped == 2 and capture.rows_written == 1
    capture.checkpoint()
    capture.close()
    rows, receipt = tape.load_day(tmp_path, "trades", INST, "2026-01-02")
    assert rows["trade_id"].tolist() == [2] and receipt["source"] == "segments"
    assert tape.load_day(tmp_path, "trades", INST, "2026-01-01")[0].size == 0 and not (tmp_path / "books5").exists()
    assert "dropped_not_before=2" in (tmp_path / "health.log").read_text()


def test_liq_selected_only_restricts_the_insttype_channel_to_the_selected_instruments(tmp_path: Path):
    """m8: `liquidation-orders` is subscribed per instType and pushes every FUTURES instrument. By default each row is
    kept under its own instId (the G4 universe); `--liq-selected-only` drops the unselected ones, counted, never silently."""
    for restrict in (False, True):
        out = tmp_path / ("restrict" if restrict else "all")
        cfg = xc.CaptureConfig(out_dir=out, inst_ids=(INST,), counting_only=False, not_before=PAST, echo=False, floor_override_for_tests=True, liq_selected_only=restrict)
        capture = xc.Capture(cfg)
        capture.handle_raw(frame_liq(INST, T0_MS, "sell", "long", "4", "66900.1"), recv_us=1)
        capture.handle_raw(frame_liq(OTHER, T0_MS + 1, "buy", "short", "20"), recv_us=1)  # pushed by the venue although OTHER was not selected
        capture.handle_raw(frame_trades(OTHER, T0_MS + 2, "1.0", "1", "buy", 1), recv_us=1)  # per-instrument channels are never restricted (only subscriptions push)
        capture.close()
        assert capture.rows_written == (2 if restrict else 3) and capture.dropped_unselected == (1 if restrict else 0)
        assert tape.load_day(out, "liquidations", INST, "2026-11-05")[0].size == 1
        assert tape.load_day(out, "liquidations", OTHER, "2026-11-05")[0].size == (0 if restrict else 1)
        assert tape.load_day(out, "trades", OTHER, "2026-11-05")[0].size == 1
        assert f"event=stopped reason=signal messages=3 rows_written={2 if restrict else 3} dropped_not_before=0 dropped_unselected={1 if restrict else 0}" in (out / "health.log").read_text()
        assert json.loads((out / tape.MANIFEST_NAME).read_text())["sessions"][0]["liq_selected_only"] is restrict
    # The flag reaches the configuration from both command lines and is off by default.
    for command in ("capture", "count"):
        assert cli.build_parser().parse_args([command, "--out", str(tmp_path / "x"), "--inst-ids", INST, "--liq-selected-only"]).liq_selected_only is True
    assert cli.build_parser().parse_args(["capture", "--out", str(tmp_path / "x"), "--inst-ids", INST]).liq_selected_only is False


# ----------------------------------------------------------------------------- index-tickers (owner decision D10, 2026-10-21)

IDX_MARKER = "67111.75"  # an index value that must never be written in counting mode


def frame_index(index_id, ts_ms, idx_px):
    """A push of the public `index-tickers` channel in the shape of docs-v5 « Index tickers channel » (Push Data Example), recorded by hand."""
    return json.dumps({"arg": {"channel": "index-tickers", "instId": index_id},
                       "data": [{"instId": index_id, "idxPx": str(idx_px), "high24h": "70000.1", "low24h": "65000.2", "open24h": "66000.3", "sodUtc0": "66100.4",
                                 "sodUtc8": "66200.5", "ts": str(ts_ms)}]})


def test_index_tickers_are_subscribed_per_index_id_and_parsed_to_ts_and_idx_px():
    """D10 (a): one subscription per index id (`uly` of the captured instruments, `<BASE>-USD` from the id otherwise), on the same public
    endpoint `/ws/v5/public` (EEA host unchanged); only `ts` and `idxPx` are kept."""
    assert "index-tickers" in xc.CHANNELS and xc.CHANNEL_LAYOUT["index-tickers"] == "index_tick" and "index_tick" in tape.LAYOUTS
    assert tape.LAYOUTS["index_tick"].names == ("ts_us", "recv_us", "idx_px") and tape.DEDUPE_KEYS["index_tick"] == ("ts_us", "idx_px")
    assert xc.index_ids_for([OTHER, INST, "BTC-USD_UM_XPERP-310502"]) == ("BTC-USD", "ETH-USD")
    assert xc.index_ids_for([INST], [{"instId": INST, "uly": "BTC-USDC"}]) == ("BTC-USDC",)  # the metadata row's uly wins
    assert xc.index_id_of_row({"instId": "BTC-USD_UM-261225"}) == "BTC-USD"
    with pytest.raises(ValueError):
        xc.index_ids_for(["BTC-USDT-SWAP"])
    args = xc.subscription_args([INST, OTHER], ("index-tickers",))
    assert args == [{"channel": "index-tickers", "instId": "BTC-USD"}, {"channel": "index-tickers", "instId": "ETH-USD"}]
    assert xc.subscription_args([INST], ("trades", "index-tickers"), ("BTC-USD",)) == [{"channel": "trades", "instId": INST}, {"channel": "index-tickers", "instId": "BTC-USD"}]
    assert xc.WS_PUBLIC_EEA.endswith("/ws/v5/public") and xc.WS_PUBLIC_GLOBAL.endswith("/ws/v5/public")
    parsed = xc.parse_frame(frame_index("BTC-USD", T0_MS, "67100.2"))
    assert parsed.kind == "data" and parsed.channel == "index-tickers" and parsed.inst_id == "BTC-USD"
    assert xc.rows_from_data("index-tickers", parsed.inst_id, parsed.data, 5) == [("BTC-USD", T0_MS * 1000, (T0_MS * 1000, 5, 67100.2))]
    assert xc.timestamps_from_data("index-tickers", parsed.inst_id, parsed.data) == [("BTC-USD", T0_MS * 1000)]
    # The command line subscribes it by default.
    assert "index-tickers" in cli.build_parser().parse_args(["capture", "--out", "x", "--inst-ids", INST]).channels.split(",")


def test_index_ticks_resume_by_segments_dedupe_and_reach_the_manifest(tmp_path: Path):
    """D10 (a), values mode: rows before not-before dropped, rows per index id in `index_tick/<index_id>/`, a restarted process opens the
    next segment (nothing overwritten), the finalized day is de-duplicated and hashed in the manifest; the session lists the index ids."""
    nb = PAST  # a value capture may start only after its not-before: the test hook (below the floor) stands for a run after 2026-11-02
    rows = [{"instId": INST, "uly": "BTC-USD", "state": "live"}, {"instId": OTHER, "uly": "ETH-USD", "state": "live"}]
    first = xc.Capture(xc.CaptureConfig(out_dir=tmp_path, inst_ids=(INST, OTHER), counting_only=False, not_before=nb, echo=False, floor_override_for_tests=True,
                                        instruments_rows=rows))
    first.handle_raw(frame_index("BTC-USD", tape.parse_iso_utc(nb) // 1000 - 1000, "67000.0"), recv_us=1)  # one second before not-before: dropped
    first.handle_raw(frame_index("BTC-USD", T0_MS, "67100.2"), recv_us=2)
    first.handle_raw(frame_index("BTC-USD", T0_MS, "67100.2"), recv_us=3)  # the same push twice (a reconnect): de-duplicated at finalization
    first.handle_raw(frame_index("ETH-USD", T0_MS + 100, "2450.5"), recv_us=4)
    assert first.guard.dropped == 1 and first.rows_written == 3
    first.close()  # the process stops: one segment per index id
    seg = tmp_path / "index_tick" / "BTC-USD"
    assert sorted(p.name for p in seg.iterdir()) == ["BTC-USD-2026-11-05.seg0000.npz"]
    second = xc.Capture(xc.CaptureConfig(out_dir=tmp_path, inst_ids=(INST, OTHER), counting_only=False, not_before=nb, echo=False, floor_override_for_tests=True))
    second.handle_raw(frame_index("BTC-USD", T0_MS + 60_000, "67105.0"), recv_us=5)
    second.checkpoint()
    assert sorted(p.name for p in seg.iterdir()) == ["BTC-USD-2026-11-05.seg0000.npz", "BTC-USD-2026-11-05.seg0001.npz"]  # resumed, nothing overwritten
    assert second.store.finalize_days_before(tape.day_of_date("2026-11-05") + 1, force=True) == ["BTC-USD:2026-11-05:index_tick", "ETH-USD:2026-11-05:index_tick"]
    second.close()
    btc, receipt = tape.load_day(tmp_path, "index_tick", "BTC-USD", "2026-11-05")
    assert receipt["source"] == "final" and btc["idx_px"].tolist() == [67100.2, 67105.0] and btc["ts_us"].tolist() == [T0_MS * 1000, (T0_MS + 60_000) * 1000]
    manifest = json.loads((tmp_path / tape.MANIFEST_NAME).read_text())
    entry = manifest["files"]["BTC-USD:2026-11-05:index_tick"]
    assert entry["duplicates_dropped"] == 1 and entry["segments_merged"] == 2 and entry["npz_sha256"] == tape.sha256_of(tmp_path / "index_tick" / "BTC-USD" / "BTC-USD-2026-11-05.npz")
    assert manifest["sessions"][0]["index_ids"] == ["BTC-USD", "ETH-USD"] and "index-tickers" in manifest["sessions"][0]["channels"]
    assert json.loads((tmp_path / "instruments-futures.json").read_text())["index_ids"] == ["BTC-USD", "ETH-USD"]
    assert tape.verify(tmp_path)["ok"] and "indices=2" in (tmp_path / "health.log").read_text()


def test_index_tickers_in_counting_mode_store_no_value_and_obey_the_floor(tmp_path: Path, monkeypatch):
    """D10 (a): the counting mode counts the channel per index id and never decodes or writes `idxPx`; a not-before below the quarantine
    floor is refused for a capture of this channel too (nothing written)."""
    cfg = xc.CaptureConfig(out_dir=tmp_path / "low", inst_ids=(INST,), channels=("index-tickers",), counting_only=True, not_before="2026-10-20T00:00:00Z", echo=False)
    with pytest.raises(xc.NotBeforeBelowFloor):
        xc.Capture(cfg)
    assert xc.run_capture(cfg) == 2 and not (tmp_path / "low").exists()

    def boom(*args, **kwargs):
        raise AssertionError("rows_from_data called in counting mode")

    monkeypatch.setattr(xc, "rows_from_data", boom)
    capture = xc.Capture(xc.CaptureConfig(out_dir=tmp_path / "count", inst_ids=(INST, OTHER), counting_only=True, echo=False))
    for k in range(3):
        capture.handle_raw(frame_index("BTC-USD", T0_MS + k * 100, IDX_MARKER), recv_us=NB_US)
    capture.handle_raw(frame_index("ETH-USD", T0_MS, "2450.5"), recv_us=NB_US)
    capture.close()
    counting = json.loads((tmp_path / "count" / "counting.json").read_text())
    assert counting["by_channel"]["index-tickers"]["instruments"] == 2 and counting["by_channel"]["index-tickers"]["rows"] == 4
    cell = next(c for c in counting["cells"] if c["channel"] == "index-tickers" and c["inst_id"] == "BTC-USD")
    assert cell["rows"] == 3 and cell["max_gap_us"] == 100_000
    assert not (tmp_path / "count" / "index_tick").exists()
    for path in (tmp_path / "count").rglob("*"):
        if path.is_file():
            text = path.read_text(errors="replace")
            assert IDX_MARKER not in text and "2450.5" not in text and "70000.1" not in text, path


# ----------------------------------------------------------------------------- segment store


def _append_trades(store: tape.TapeStore, ids, day_ms=DAY_MS):
    for k in ids:
        ts_us = (day_ms + k * 1000) * 1000
        store.append("trades", INST, ts_us, (ts_us, ts_us + 50_000, 100.0 + k * 0.1, 1.0, k % 2, k, 1))


def test_segment_store_resumes_a_day_without_loss_and_finalizes_once(tmp_path: Path):
    date = "2026-11-05"
    first = tape.TapeStore(tmp_path, "values", source={"ws": "test"}, not_before=PAST)
    _append_trades(first, range(0, 300))
    assert first.checkpoint() == [f"{INST}:{date}:trades"]
    _append_trades(first, range(300, 400))
    first.checkpoint()  # the process dies here
    seg_dir = tmp_path / "trades" / INST
    assert sorted(p.name for p in seg_dir.iterdir()) == [f"{INST}-{date}.seg0000.npz", f"{INST}-{date}.seg0001.npz"]
    pending = json.loads((tmp_path / tape.MANIFEST_NAME).read_text())["pending"][f"{INST}:{date}:trades"]
    assert pending["segments"] == 2 and pending["rows"] == 400
    # Before finalization a reader merges the segments (read-only).
    rows, receipt = tape.load_day(tmp_path, "trades", INST, date)
    assert rows.size == 400 and receipt["source"] == "segments" and receipt["segments"] == 2 and not any("seg" not in p.name for p in seg_dir.iterdir())

    second = tape.TapeStore(tmp_path, "values")  # the restart
    _append_trades(second, [399])  # the backfill repeats an already-recorded print
    _append_trades(second, range(400, 500))
    second.checkpoint()
    assert (seg_dir / f"{INST}-{date}.seg0002.npz").exists() and (seg_dir / f"{INST}-{date}.seg0000.npz").exists()  # nothing overwritten
    done = second.finalize_days_before(tape.day_of_date(date) + 1)
    assert done == [f"{INST}:{date}:trades"]
    entry = second.manifest["files"][f"{INST}:{date}:trades"]
    assert entry["rows"] == 500 and entry["duplicates_dropped"] == 1 and entry["segments_merged"] == 3 and entry["resumed_segments"] == 2 and entry["partial"] is False
    assert entry["refinalized"] is False and len(entry["npz_sha256"]) == 64 and f"{INST}:{date}:trades" not in second.manifest["pending"]
    assert sorted(p.name for p in seg_dir.iterdir()) == [f"{INST}-{date}.npz"]
    final, receipt = tape.load_day(tmp_path, "trades", INST, date)
    assert final["trade_id"].tolist() == list(range(500)) and np.all(np.diff(final["ts_us"]) > 0) and receipt["source"] == "final" and receipt["sha256"] == entry["npz_sha256"]
    with np.load(seg_dir / f"{INST}-{date}.npz") as data:
        assert set(data.files) == set(tape.TRADES_DTYPE.names) and data["taker_buy"].dtype == np.int8
    # A late print of a finalized day opens a new segment; the second finalization is throttled (at most once per
    # `refinalize_after_s`, 1 h by default) so a trickle of late rows cannot re-read and re-hash the final file at every arrival.
    _append_trades(second, [500])
    second.checkpoint()
    assert second.finalize_days_before(tape.day_of_date(date) + 1) == []
    assert (seg_dir / f"{INST}-{date}.seg0000.npz").exists() and second.manifest["files"][f"{INST}:{date}:trades"]["rows"] == 500
    assert tape.verify(tmp_path)["pending_segments"] == {f"{INST}:{date}:trades": 1}
    # Once the interval has elapsed (or forced), the final file and the late segment merge (disclosed).
    later = second.manifest["files"][f"{INST}:{date}:trades"]["finalized_at_us"] + 3600 * tape.SECOND_US
    assert second.finalize_days_before(tape.day_of_date(date) + 1, now_us=later) == [f"{INST}:{date}:trades"]
    entry = second.manifest["files"][f"{INST}:{date}:trades"]
    assert entry["rows"] == 501 and entry["refinalized"] is True and entry["refinalizations"] == 1
    _append_trades(second, [501])
    second.checkpoint()
    assert second.finalize_days_before(tape.day_of_date(date) + 1, force=True) == [f"{INST}:{date}:trades"]
    assert second.manifest["files"][f"{INST}:{date}:trades"]["rows"] == 502 and second.manifest["files"][f"{INST}:{date}:trades"]["refinalizations"] == 2
    assert tape.verify(tmp_path)["ok"] is True
    # A day never written stays empty; `--allow-partial` off ignores segments.
    assert tape.load_day(tmp_path, "trades", INST, "2026-11-06")[1]["source"] == "missing"


def test_every_layout_round_trips_through_segments_and_dedupes_on_its_key(tmp_path: Path):
    store = tape.TapeStore(tmp_path, "values")
    ts = DAY_MS * 1000
    store.append("books5", INST, ts, (ts, ts + 1, 7, [100.0, 99.9, np.nan, np.nan, np.nan], [1.0, 2.0, 0, 0, 0], [100.1, np.nan, np.nan, np.nan, np.nan], [3.0, 0, 0, 0, 0]))
    store.append("books5", INST, ts, (ts, ts + 2, 7, [100.0, 99.9, np.nan, np.nan, np.nan], [1.0, 2.0, 0, 0, 0], [100.1, np.nan, np.nan, np.nan, np.nan], [3.0, 0, 0, 0, 0]))  # duplicate (ts, seq)
    store.append("books5", INST, ts + 100_000, (ts + 100_000, ts + 3, 8, [100.0] + [np.nan] * 4, [1.0, 0, 0, 0, 0], [100.2] + [np.nan] * 4, [1.0, 0, 0, 0, 0]))
    # No seqId (−1) twice in the same millisecond: distinct tops of book are two snapshots, an identical one is a duplicate.
    store.append("books5", INST, ts + 200_000, (ts + 200_000, ts + 4, -1, [100.0] + [np.nan] * 4, [1.0, 0, 0, 0, 0], [100.2] + [np.nan] * 4, [1.0, 0, 0, 0, 0]))
    store.append("books5", INST, ts + 200_000, (ts + 200_000, ts + 5, -1, [100.1] + [np.nan] * 4, [1.0, 0, 0, 0, 0], [100.2] + [np.nan] * 4, [1.0, 0, 0, 0, 0]))
    store.append("books5", INST, ts + 200_000, (ts + 200_000, ts + 6, -1, [100.1] + [np.nan] * 4, [2.0, 0, 0, 0, 0], [100.2] + [np.nan] * 4, [1.0, 0, 0, 0, 0]))  # duplicate (same top)
    store.append("liquidations", OTHER, ts, (ts, ts + 1, -1, 1, 4.0, np.nan, 0.0))
    store.append("liquidations", OTHER, ts, (ts, ts + 9, -1, 1, 4.0, np.nan, 0.0))  # the channel's first push repeats recent orders: duplicate (NaN price twice)
    store.append("mark", INST, ts, (ts, ts + 1, 100.05))
    store.append("funding", INST, ts, (ts, ts + 1, ts + 3600 * tape.SECOND_US, 0.0001, ts + 2 * 3600 * tape.SECOND_US, np.nan, 0.0, np.nan, 0, 1, 0.00375, -0.00375, 0.0001))
    store.checkpoint()
    store.finalize_days_before(tape.day_of_us(ts) + 1)
    books, _ = tape.load_day(tmp_path, "books5", INST, "2026-11-05")
    assert books.size == 4 and books["bid_px"].shape == (4, 5) and books["seq_id"].tolist() == [7, 8, -1, -1] and np.isnan(books["bid_px"][0, 2])
    assert books["bid_px"][2:, 0].tolist() == [100.0, 100.1] and store.manifest["files"][f"{INST}:2026-11-05:books5"]["duplicates_dropped"] == 2
    liq, _ = tape.load_day(tmp_path, "liquidations", OTHER, "2026-11-05")
    assert liq.size == 1 and liq["pos_side"][0] == 1 and liq["side"][0] == -1
    assert tape.load_day(tmp_path, "mark", INST, "2026-11-05")[0]["mark_px"].tolist() == [100.05]
    funding, _ = tape.load_day(tmp_path, "funding", INST, "2026-11-05")
    assert funding["method"].tolist() == [1] and funding["funding_rate"].tolist() == [0.0001]
    assert set(store.manifest["files"]) == {f"{INST}:2026-11-05:books5", f"{OTHER}:2026-11-05:liquidations", f"{INST}:2026-11-05:mark", f"{INST}:2026-11-05:funding"}
    assert tape.instruments_in(tmp_path, "books5") == [INST]


def test_lock_mode_and_verify_guards(tmp_path: Path):
    lock = tape.DirectoryLock(tmp_path).acquire()
    with pytest.raises(tape.LockHeld):
        tape.DirectoryLock(tmp_path).acquire()
    lock.release()
    tape.DirectoryLock(tmp_path).acquire().release()
    tape.TapeStore(tmp_path / "c", "counting")
    with pytest.raises(ValueError):
        tape.TapeStore(tmp_path / "c", "values")
    with pytest.raises(ValueError):
        tape.check_inst_name("../evil")
    store = tape.TapeStore(tmp_path / "v", "values")
    _append_trades(store, range(10))
    store.checkpoint()
    store.finalize_days_before(tape.day_of_date("2026-11-05") + 1)
    report = tape.verify(tmp_path / "v")
    assert report["ok"] and report["files_checked"] == 1 and report["pending_segments"] == {}
    (tmp_path / "v" / "trades" / INST / f"{INST}-2026-11-05.npz").write_bytes(b"corrupt")
    report = tape.verify(tmp_path / "v")
    assert report["ok"] is False and report["sha256_mismatches"] == [f"{INST}:2026-11-05:trades"]
    assert tape.verify(tmp_path / "nothing")["ok"] is False
    # A second capture on the same directory is refused with exit code 3 while the first holds the lock.
    held = tape.DirectoryLock(tmp_path / "v").acquire()
    try:
        assert xc.run_capture(xc.CaptureConfig(out_dir=tmp_path / "v", inst_ids=(INST,), counting_only=True, echo=False)) == 3
    finally:
        held.release()


def test_dates_and_timestamps_helpers():
    assert tape.parse_dates("2026-11-03..2026-11-05,2026-11-09") == ["2026-11-03", "2026-11-04", "2026-11-05", "2026-11-09"]
    assert tape.date_of_day(tape.day_of_us(NB_US)) == "2026-11-02" and tape.iso_utc(NB_US) == "2026-11-02T00:00:00.000000Z"
    assert tape.parse_iso_utc("2026-11-02T01:00:00+01:00") == NB_US
    with pytest.raises(ValueError):
        tape.parse_iso_utc("2026-11-02T00:00:00")


def test_health_journal_quotes_values_with_spaces(tmp_path: Path):
    journal = xc.HealthJournal(tmp_path / "h.log", echo=False)
    line = journal.log("WARN", "disconnected", error="ConnectionClosed: going away", retry_s=1.0)
    assert line.endswith('level=WARN event=disconnected error="ConnectionClosed: going away" retry_s=1.0')
    assert (tmp_path / "h.log").read_text().count("\n") == 1


# ----------------------------------------------------------------------------- fake server (loopback, no venue)


@pytest.mark.usefixtures("allow_loopback_only")
def test_fake_websocket_server_end_to_end(tmp_path: Path):
    websockets = pytest.importorskip("websockets")
    frames = [f for f in recorded_frames() if f not in ("pong", "not json at all")]
    seen: dict = {"connections": 0, "subscribes": []}

    async def handler(ws):
        seen["connections"] += 1
        first = await ws.recv()
        seen["subscribes"].append(json.loads(first))
        if seen["connections"] == 1:
            for raw in frames:
                await ws.send(raw)
            await ws.close()  # the first connection drops: the collector must reconnect
            return
        await ws.send(frame_trades(INST, T0_MS + 5000, "67124.0", "0.2", "buy", 900003))
        try:
            while True:
                message = await ws.recv()
                if message == "ping":
                    await ws.send("pong")
        except Exception:  # noqa: BLE001 - client gone
            return

    async def scenario():
        async with websockets.serve(handler, "127.0.0.1", 0) as server:
            port = server.sockets[0].getsockname()[1]
            cfg = xc.CaptureConfig(out_dir=tmp_path, inst_ids=(INST, OTHER), host=f"ws://127.0.0.1:{port}", counting_only=False, not_before=PAST, checkpoint_every_s=0.4,
                                   duration_s=2.5, reconnect_first_s=0.1, ping_every_s=0.3, echo=False, instruments_rows=[{"instId": INST, "state": "live", "tickSz": "0.1", "ctVal": "0.0001"}],
                                   floor_override_for_tests=True)
            capture = xc.Capture(cfg)
            await asyncio.wait_for(capture.run(), timeout=15)
            return capture

    capture = asyncio.run(scenario())
    assert seen["connections"] >= 2 and seen["subscribes"][0]["op"] == "subscribe" and len(seen["subscribes"][0]["args"]) == 9 + 2  # adapted 2026-10-21 (D10): + index-tickers BTC-USD, ETH-USD
    assert capture.messages == 8 and capture.rows_written == 8 and capture.stop_reason == "duration"  # 7 data frames on the first connection, 1 on the second
    manifest = json.loads((tmp_path / tape.MANIFEST_NAME).read_text())
    assert len(manifest["disconnects"]) >= 1 and len(manifest["errors"]) == 1 and manifest["sessions"][0]["host"].startswith("ws://127.0.0.1")
    trades, receipt = tape.load_day(tmp_path, "trades", INST, "2026-11-05")
    assert trades["trade_id"].tolist() == [900001, 900002, 900003] and receipt["source"] == "segments"  # a future day is never finalized by the clock
    assert tape.load_day(tmp_path, "liquidations", OTHER, "2026-11-05")[0].size == 1 and tape.load_day(tmp_path, "funding", INST, "2026-11-05")[0].size == 1
    instruments = json.loads((tmp_path / "instruments-futures.json").read_text())
    assert instruments["instruments"][0]["instId"] == INST and instruments["selected"] == [INST, OTHER]
    health = (tmp_path / "health.log").read_text()
    assert "event=connected" in health and "event=disconnected" in health and "event=checkpoint" in health and "event=stopped reason=duration" in health
    assert health.count("event=stopped") == 1  # close() is idempotent: one `stopped` line even though run() and run_capture() both reach it
    assert not (tmp_path / tape.LOCK_NAME).exists() or tape.DirectoryLock(tmp_path).acquire().release() is None  # the lock was released
    # A later session on the same directory merges its selection and rows into instruments-futures.json (m9).
    again = xc.Capture(xc.CaptureConfig(out_dir=tmp_path, inst_ids=("SOL-USD_UM_XPERP-310404",), counting_only=False, not_before=PAST, echo=False, floor_override_for_tests=True,
                                        instruments_rows=[{"instId": "SOL-USD_UM_XPERP-310404", "state": "live", "tickSz": "0.01", "ctVal": "1"}]))
    again.close()
    merged = json.loads((tmp_path / "instruments-futures.json").read_text())
    assert merged["selected"] == [INST, OTHER, "SOL-USD_UM_XPERP-310404"] and [r["instId"] for r in merged["instruments"]] == [INST, "SOL-USD_UM_XPERP-310404"] and "updated_at" in merged


@pytest.mark.usefixtures("allow_loopback_only")
def test_sigterm_stops_the_capture_cleanly_and_flushes_the_ram_buffer(tmp_path: Path):
    """M4: a standard `systemctl stop` (SIGTERM) must end like a STOP file — last segment written, `stopped` journaled, manifest saved."""
    websockets = pytest.importorskip("websockets")

    async def handler(ws):
        await ws.recv()  # the subscribe
        await ws.send(frame_trades(INST, T0_MS + 9000, "67125.0", "0.3", "sell", 900004))
        try:
            while True:
                if await ws.recv() == "ping":
                    await ws.send("pong")
        except Exception:  # noqa: BLE001 - client gone
            return

    async def scenario():
        async with websockets.serve(handler, "127.0.0.1", 0) as server:
            port = server.sockets[0].getsockname()[1]
            # No checkpoint can happen on its own (3600 s) and no duration stops the run: only the signal does.
            cfg = xc.CaptureConfig(out_dir=tmp_path, inst_ids=(INST,), host=f"ws://127.0.0.1:{port}", counting_only=False, not_before=PAST, checkpoint_every_s=3600.0,
                                   ping_every_s=5.0, recv_timeout_s=30.0, echo=False, floor_override_for_tests=True)
            capture = xc.Capture(cfg)
            asyncio.get_running_loop().call_later(0.8, os.kill, os.getpid(), signal.SIGTERM)
            started = time.monotonic()
            await asyncio.wait_for(capture.run(), timeout=15)
            return capture, time.monotonic() - started

    capture, elapsed = asyncio.run(scenario())
    assert capture.stop_reason == "SIGTERM" and capture.closed and capture.rows_written == 1
    assert elapsed < 6.0  # the blocked WebSocket read is interrupted by the stop event, not by the 30 s receive timeout
    rows, receipt = tape.load_day(tmp_path, "trades", INST, "2026-11-05")
    assert rows["trade_id"].tolist() == [900004] and receipt["source"] == "segments"  # the RAM buffer reached the disk
    health = (tmp_path / "health.log").read_text()
    assert "event=signal reason=SIGTERM" in health and health.count("event=stopped") == 1 and "event=stopped reason=SIGTERM" in health
    manifest = json.loads((tmp_path / tape.MANIFEST_NAME).read_text())
    assert manifest["pending"][f"{INST}:2026-11-05:trades"]["rows"] == 1
    # `request_stop` is what the handler calls; it is idempotent and keeps the first reason.
    capture.request_stop("SIGINT")
    assert capture.stop_reason == "SIGTERM"


def test_no_test_can_open_a_socket_or_resolve_a_name():
    """CTO m4: the guard of this module (no loopback opt-in here)."""
    for attempt in (lambda: socket.create_connection(("127.0.0.1", 9)), lambda: socket.socket().connect(("127.0.0.1", 9)), lambda: socket.getaddrinfo("eea.okx.com", 443),
                    lambda: urllib.request.urlopen("https://eea.okx.com/api/v5/public/time")):
        with pytest.raises(AssertionError):
            attempt()


@pytest.mark.usefixtures("allow_loopback_only")
def test_the_loopback_opt_in_reaches_no_other_host():
    """CTO m4: a test that opts in may reach the loopback only; any other address or name is still refused before any packet."""
    for attempt in (lambda: socket.create_connection(("192.0.2.1", 9)), lambda: socket.socket().connect(("192.0.2.1", 9)), lambda: socket.getaddrinfo("eea.okx.com", 443),
                    lambda: socket.socket(socket.AF_INET, socket.SOCK_DGRAM).sendto(b"x", ("192.0.2.1", 53)), lambda: urllib.request.urlopen("https://eea.okx.com")):
        with pytest.raises(AssertionError):
            attempt()
    assert socket.getaddrinfo("127.0.0.1", 0)  # the loopback itself is allowed (no resolver involved for a numeric host)
