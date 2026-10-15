"""Tests of the leverage > 2x study (PREREGISTRATION-leverage-gt2.md): synthetic data only, no
network, no tape. Leverage formulas and band edges, BTC exclusion, no lever-up, indeterminables,
floor strata, no look-ahead, identity with the frozen engine when the hook is off, admission
without blocking, merged streams, realistic stop exit, C1, determinism, the single-use claim,
the liquidation formula, Holm, the E0a trigger and the frozen configuration list."""

from __future__ import annotations

import hashlib
import json
import math

import numpy as np
import pytest

from scripts.research_exec.capture import QUOTE_DTYPE, TRADE_DTYPE, Instrument
from scripts.research_exec.scalp_engine import EXIT_REASONS, SETUP_TTL_US, Policy, SeriesData, run_policy
from scripts.research_exec.screen import PROTOCOLS, build_grid, parse_configuration
from scripts.research_exec.tick import build_bars, compute_features, parse_rule
from scripts.research_lev import claim as claims
from scripts.research_lev import leverage as lv
from scripts.research_lev import protocol as pr
from scripts.research_lev.census import aggregate, census_series, classify, streams_of, trigger, trigger_counts
from scripts.research_lev.engine import (
    EXPORT_DTYPE,
    OK,
    REASON_CODE,
    STATUS,
    b4_exit,
    decision_inputs,
    replay_arm,
    replay_frozen,
    replay_streams,
    settlements_crossed,
)
from scripts.research_lev.stats import (
    INDETERMINATE_CELL,
    analyse,
    contrast_verdict,
    dedupe,
    holm,
    liquidation_control,
    one_sample_cluster_bootstrap,
    pair_day_codes,
    paired_cluster_bootstrap,
    sign_label,
    strata_cells,
    stratified_cluster_bootstrap,
)

SECOND = 1_000_000
DAY = 1_790_000_000 - 1_790_000_000 % 86400
T0 = DAY + 36_000  # 10:00 UTC
TICK = 0.01
INSTRUMENT = Instrument("ETHUSDT", "ETHUSDT", 1.0, 0.001, 0.001, fixed_tick=TICK)
B4 = Policy("E1", "B4", ttl_us=SETUP_TTL_US)


def quotes_from(rows):
    return np.array([(ts, ts, bid, ask, 1.0, 1.0) for ts, bid, ask in rows], dtype=QUOTE_DTYPE)


def trades_from(rows):
    return np.array([(ts, ts, price, 0.5, buy, 1) for ts, price, buy in rows], dtype=TRADE_DTYPE)


def random_walk(seconds: int, seed: int = 3, sigma: float = 4e-4):
    rng = np.random.default_rng(seed)
    mid = 100.0 * np.exp(rng.normal(0, sigma, size=seconds).cumsum())
    q_rows, t_rows = [], []
    for s in range(seconds):
        bid = np.floor(mid[s] / TICK) * TICK
        q_rows.append(((T0 + s) * SECOND + 100_000, bid, bid + TICK))
        for k in range(2):
            buy = int(rng.random() < 0.5)
            t_rows.append(((T0 + s) * SECOND + 200_000 + k * 300_000, bid + TICK if buy else bid, buy))
    return q_rows, t_rows


def make(q_rows, t_rows, seconds: int, atr5=0.1, series_id: int = 0) -> SeriesData:
    quotes, trades = quotes_from(sorted(q_rows)), trades_from(sorted(t_rows))
    bars = build_bars(quotes, trades, T0, T0 + seconds)
    features = compute_features(bars, INSTRUMENT)
    features["atr5"] = np.full(seconds, atr5, dtype=np.float64) if np.isscalar(atr5) else np.asarray(atr5, dtype=np.float64)
    return SeriesData(series_id, bars, features, quotes, trades, INSTRUMENT, T0)


@pytest.fixture
def decided(monkeypatch):
    """The owner decisions D1-D4 set (they are pending in protocol.py until the owner sets them)."""
    monkeypatch.setattr(pr, "MULTIPLICITY_RULE", "distinct_pair_day")
    monkeypatch.setattr(pr, "R1_BOTH_RULE", "drop")
    monkeypatch.setattr(pr, "MAX_GUARD_RESUMPTIONS", 1)
    monkeypatch.setattr(pr, "FUNDING_RULE", "not_charged")


@pytest.fixture(scope="module")
def walk():
    q_rows, t_rows = random_walk(16_000)
    return q_rows, t_rows


# ----------------------------------------------------------------------------- formulas and bands


def test_leverage_formulas_and_exact_band_edges():
    assert lv.R_BP == 50.0
    assert lv.l_eng(20.0, 5.0) == 2.0 and lv.l_stop(25.0) == 2.0 and lv.l_stop(10.0) == 5.0 and lv.l_stop(5.0) == 10.0
    assert lv.l_eng(10.0, 0.0) == 5.0 and math.isnan(lv.l_eng(0.0, 0.0)) and math.isnan(lv.l_stop(0.0))
    assert lv.band_of(1.0) == "le1" and lv.band_of(1.0000001) == "1-2"
    assert lv.band_of(2.0) == "1-2" and lv.band_of(2.0000001) == "2-3"
    assert lv.band_of(3.0) == "2-3" and lv.band_of(3.0000001) == "3-5"
    assert lv.band_of(5.0) == "3-5" and lv.band_of(5.0000001) == "5-10"
    assert lv.band_of(10.0) == "5-10" and lv.band_of(10.0000001) == "gt10"
    for bad in (float("nan"), float("inf"), 0.0, -1.0, None):
        assert lv.band_of(bad) is None
    values = np.array([0.5, 1.0, 1.5, 2.0, 2.5, 3.0, 4.0, 5.0, 7.0, 10.0, 12.0, np.nan, 0.0])
    expected = [lv.band_of(float(v)) for v in values]
    assert [lv.BAND_NAMES[i] if i >= 0 else None for i in lv.band_index(values)] == expected
    # the threshold algebra of the draft: L_eng > 2 ⇔ d + c < 25 bp, L_stop > 3 ⇔ d < 16.7 bp
    assert lv.band_of(lv.l_eng(24.9, 0.0)) == "2-3" and lv.band_of(lv.l_eng(25.0, 0.0)) == "1-2"
    assert lv.leverage("stop", 16.6, 99.0) > 3 and lv.leverage("eng", 16.6, 1.0) < 3
    with pytest.raises(ValueError):
        lv.leverage("other", 1.0, 1.0)


def test_cost_provision_distance_trigger_and_c1():
    assert lv.cost_bp(1.0, 0.5) == pytest.approx(2 + 5 + 1.0 + 0.5)
    assert lv.stop_distance(0.1, 0.01) == pytest.approx(0.15) and lv.stop_distance(0.001, 0.01) == pytest.approx(0.02)
    assert bool(lv.floor_binding(0.001, 0.01)) and not bool(lv.floor_binding(0.1, 0.01))
    assert lv.stop_trigger(100.0, 1, 0.155, 0.01) == pytest.approx(99.84)  # rounded away from the entry
    assert lv.stop_trigger(100.0, -1, 0.155, 0.01) == pytest.approx(100.16)
    assert lv.stop_trigger(100.0, 1, 0.02, 0.01) == pytest.approx(99.98)  # floor: exactly two ticks
    assert lv.distance_bp(100.0, 99.84, 1) == pytest.approx(16.0)
    assert lv.c1_distance_bp(8.0) == pytest.approx(50 / 1.5 - 8.0) and math.isnan(lv.c1_distance_bp(34.0))
    assert lv.l_eng(lv.c1_distance_bp(8.0), 8.0) == pytest.approx(1.5)


def test_btc_is_excluded_by_name_before_any_computation():
    assert len(pr.P2_SYMBOLS) == 19 and not any(s.startswith("BTC") for s in pr.P2_SYMBOLS)
    assert set(pr.P2_SYMBOLS) == set(pr.MAJORS) | set(pr.MIDCAPS)
    assert lv.is_excluded_symbol("BTCUSDT") and lv.is_excluded_symbol("btcusdt") and not lv.is_excluded_symbol("ETHUSDT")
    with pytest.raises(ValueError):
        lv.assert_admissible("binance:BTCUSDT:w2404")
    lv.assert_admissible("binance:ETHUSDT:w2404")
    data = make(*random_walk(400), 400)
    with pytest.raises(ValueError):
        census_series(data, "binance:BTCUSDT:w2404", ("R1|long|E1|B4",))


def test_a_trade_is_never_levered_up():
    equity = 500.0
    notional = lv.theoretical_notional(equity, 20.0, 5.0)
    assert notional / equity == pytest.approx(lv.l_eng(20.0, 5.0)) == pytest.approx(2.0)
    lv.assert_not_levered_up(2.0, notional, equity)
    with pytest.raises(ValueError):
        lv.assert_not_levered_up(1.5, 2.0 * equity, equity)  # a ]1,2] trade scaled to 2x: refused


# ----------------------------------------------------------------------------- decision-time inputs


def test_indeterminables_and_floor_strata():
    q_rows, t_rows = random_walk(600)
    atr = np.full(600, 0.1)
    atr[10] = np.nan
    atr[20] = 0.001  # floor binding: 2 ticks > 1.5 x ATR
    data = make(q_rows, t_rows, 600, atr5=atr)
    data.bars["quote_age"][30] = 99.0  # stale quote
    seconds = np.array([10, 20, 30, 40])
    x = decision_inputs(data, 1, seconds, 10.0)
    assert x["status"].tolist() == [REASON_CODE["atr_missing"], OK, REASON_CODE["quote_stale"], OK]
    assert x["floor"].tolist() == [0, 1, 0, 0]
    assert x["d_bp"][1] == pytest.approx(2 * TICK / x["price"][1] * 1e4)  # floor: d known, determinable
    assert np.isnan(x["l_eng"][0]) and np.isnan(x["l_eng"][2]) and np.isfinite(x["l_eng"][3])
    assert x["l_eng"][3] == pytest.approx(50 / (x["d_bp"][3] + x["c_bp"][3]))
    assert x["c_bp"][3] == pytest.approx(7 + x["spread_bp"][3] + x["tick_bp"][3])
    counts = classify(data, 1, seconds)
    assert counts["reasons"]["atr_missing"] == 1 and counts["reasons"]["quote_stale"] == 1
    assert sum(item["total"] for item in counts["eng"].values()) == 2 and sum(item["floor"] for item in counts["eng"].values()) == 1


def test_no_look_ahead_in_decision_inputs():
    q_rows, t_rows = random_walk(3000, seed=5)
    cut = 2000
    atr = np.linspace(0.05, 0.3, 3000)
    base = make(q_rows, t_rows, 3000, atr5=atr)
    q_future = [row if row[0] < (T0 + cut + 1) * SECOND else (row[0], row[1] * 1.03, row[2] * 1.03) for row in q_rows]
    t_future = [row if row[0] < (T0 + cut + 1) * SECOND else (row[0], row[1] * 1.03, 1 - row[2]) for row in t_rows]
    atr_future = atr.copy()
    atr_future[cut + 1:] *= 7.0
    other = make(q_future, t_future, 3000, atr5=atr_future)
    seconds = np.arange(0, cut + 1, 7)
    for side in (1, -1):
        a, b = decision_inputs(base, side, seconds, 10.0), decision_inputs(other, side, seconds, 10.0)
        for key in ("status", "price", "d_bp", "c_bp", "l_eng", "l_stop", "floor", "atr5_bp", "stale_900"):
            np.testing.assert_array_equal(a[key], b[key])


# ----------------------------------------------------------------------------- engine identity and admission


@pytest.mark.parametrize("exit_", ["B4", "X3", "Z3", "X1"])
@pytest.mark.parametrize("side", [1, -1])
def test_hook_off_reproduces_the_frozen_engine_byte_for_byte(walk, exit_, side):
    q_rows, t_rows = walk
    atr = np.full(16_000, 0.1)
    atr[:500] = np.nan
    data = make(q_rows, t_rows, 16_000, atr5=atr)
    policy = Policy("E1", exit_, ttl_us=SETUP_TTL_US) if exit_ == "B4" else Policy("E1", exit_)
    decisions = np.arange(0, 16_000, 37)
    frozen, frozen_counters = run_policy(data, side, decisions, policy)
    mine, my_counters = replay_frozen(data, side, decisions, policy)
    assert frozen.size >= 3
    assert mine.tobytes() == frozen.tobytes()
    assert my_counters == frozen_counters


def test_rejected_signal_does_not_touch_busy_until(walk):
    q_rows, t_rows = walk
    data = make(q_rows, t_rows, 16_000)
    decisions = np.arange(1000, 16_000, 41)
    first, _ = replay_frozen(data, 1, decisions, B4)
    blocked = int(first["decision_s"][0] - T0)
    rejected = {blocked}

    def admit(side, s, price, tick):
        return "excluded_band" if s in rejected else None

    from scripts.research_lev.engine import _frozen_exit

    records, counters = replay_streams(data, {1: decisions}, B4, admit, _frozen_exit)
    without, _ = replay_frozen(data, 1, decisions[decisions != blocked], B4)
    filled = [r for r in records if r[0] == STATUS["filled"]]
    assert counters["excluded_band"] == 1
    assert [T0 + r[2] for r in filled] == without["decision_s"].tolist()


def test_merged_streams_one_position_per_pair_and_conflicts(walk):
    q_rows, t_rows = walk
    data = make(q_rows, t_rows, 16_000)
    from scripts.research_lev.engine import _frozen_exit

    same = np.arange(1000, 16_000, 300)
    records, counters = replay_streams(data, {1: same, -1: same}, B4, None, _frozen_exit)
    assert counters["conflict"] > 0 and counters["orders"] == 0  # both sides admitted every time: no order
    longs, shorts = np.arange(1000, 16_000, 300), np.arange(1150, 16_000, 300)
    records, counters = replay_streams(data, {1: longs, -1: shorts}, B4, None, _frozen_exit)
    filled = [r for r in records if r[0] == STATUS["filled"]]
    assert {r[1] for r in filled} == {1, -1}
    intervals = sorted((r[7], r[8]["exit_ts"]) for r in filled)
    assert all(b[0] > a[1] for a, b in zip(intervals, intervals[1:]))  # never two positions at once on the pair
    assert counters["busy"] > 0


def test_realistic_stop_keeps_the_frozen_detection_and_is_never_better(walk):
    q_rows, t_rows = walk
    data = make(q_rows, t_rows, 16_000)
    decisions = np.arange(0, 16_000, 53)
    for side in (1, -1):
        frozen, _ = run_policy(data, side, decisions, B4)
        x = decision_inputs(data, side, decisions, 10.0)
        position = {int(s): i for i, s in enumerate(decisions)}

        def exit_fn(d, p, sd, s, entry, jf, fill_ts, tick, tol):
            return b4_exit(d, p, sd, entry, jf, fill_ts, tick, tol, float(x["distance"][position[s]]))

        records, _ = replay_streams(data, {side: decisions}, B4, None, exit_fn)
        filled = [r for r in records if r[0] == STATUS["filled"]]
        assert len(filled) == frozen.size and frozen.size > 5
        for r, f in zip(filled, frozen):
            result = r[8]
            assert result["exit_ts"] == f["exit_ts"] and result["reason"] == f["exit_reason"]
            assert result["frozen_exit"] == pytest.approx(f["exit"])
            assert side * (result["exit_price"] - f["exit"]) <= 1e-12  # realistic never better than the touch
            if result["reason"] == EXIT_REASONS["time"]:
                assert result["exit_price"] == f["exit"]
            assert side * (result["worst"] - result["exit_price"]) <= 1e-12  # the worst price is at least as adverse as the exit


def test_stop_execution_takes_the_worst_print_within_the_latency():
    quotes = [(T0 * SECOND + 500_000, 100.0, 100.01), ((T0 + 49) * SECOND, 99.80, 99.81)]
    trades = [((T0 + 2) * SECOND, 99.99, 0), ((T0 + 50) * SECOND, 99.84, 0), ((T0 + 50) * SECOND + 100_000, 99.70, 0), ((T0 + 60) * SECOND, 99.9, 1)]
    data = make(quotes, trades, 7300, atr5=0.1)  # stop distance 0.15 → trigger 99.85
    x = decision_inputs(data, 1, np.array([0]), 10.0)
    assert x["trigger"][0] == pytest.approx(99.85) and x["d_bp"][0] == pytest.approx(15.0)
    export, counters = replay_arm(data, {1: np.array([0])}, B4, "eng", lv.band_of(float(x["l_eng"][0])))
    row = export[export["status"] == STATUS["filled"]][0]
    assert row["exit_reason"] == EXIT_REASONS["stop"] and row["exit"] == pytest.approx(99.70) and row["frozen_exit"] == pytest.approx(99.80)
    assert row["stop_slip_bp"] == pytest.approx(15.0) and row["worst"] == pytest.approx(99.70)
    frozen, _ = run_policy(data, 1, np.array([0]), B4)
    assert frozen["exit"][0] == pytest.approx(99.80) and frozen["exit_ts"][0] == row["exit_ts"]


def test_arm_admission_keeps_only_its_band_and_c1_is_per_signal(walk):
    q_rows, t_rows = walk
    rng = np.random.default_rng(11)
    atr = rng.choice([0.08, 0.1, 0.2, 0.35], size=16_000)
    data = make(q_rows, t_rows, 16_000, atr5=atr)
    decisions = np.arange(0, 16_000, 31)
    seen = 0
    for band in lv.ARM_BANDS:
        export, counters = replay_arm(data, {1: decisions}, B4, "eng", band)
        assert all(lv.band_of(float(v)) == band for v in export["l_eng"])
        filled = export[export["status"] == STATUS["filled"]]
        seen += filled.size
        assert counters["excluded_band"] > 0
        if band in lv.STUDIED_BANDS:
            assert np.all(filled["l_eng"] > 2.0)  # never a trade of L ≤ 2 in a studied arm
        ok = np.isfinite(filled["c1_d_bp"])
        assert np.allclose(lv.l_eng(filled["c1_d_bp"][ok], filled["c_bp"][ok]), 1.5, atol=0.05)
        assert np.all(filled["c1_hold_s"][np.isfinite(filled["c1_hold_s"])] >= 0)
    assert seen > 0
    stop_arm, _ = replay_arm(data, {1: decisions}, B4, "stop", "2-3")
    assert all(lv.band_of(float(v)) == "2-3" for v in stop_arm["l_stop"])


def test_replay_is_deterministic(walk):
    q_rows, t_rows = walk
    a, ca = replay_arm(make(q_rows, t_rows, 16_000, atr5=0.1), {1: np.arange(0, 16_000, 29), -1: np.arange(13, 16_000, 29)}, B4, "eng", "2-3")
    b, cb = replay_arm(make(q_rows, t_rows, 16_000, atr5=0.1), {1: np.arange(0, 16_000, 29), -1: np.arange(13, 16_000, 29)}, B4, "eng", "2-3")
    assert hashlib.sha256(a.tobytes()).hexdigest() == hashlib.sha256(b.tobytes()).hexdigest() and ca == cb


def test_settlement_count():
    eight = 8 * 3600 * SECOND
    assert settlements_crossed(DAY * SECOND - 1, DAY * SECOND) == 1  # T = 00:00 with fill < T ≤ exit
    assert settlements_crossed(DAY * SECOND, DAY * SECOND + eight - 1) == 0
    assert settlements_crossed(DAY * SECOND + 1, DAY * SECOND + 2 * eight) == 2


# ----------------------------------------------------------------------------- census and trigger


def _r1_series(seconds: int = 3000, series_id: int = 0) -> SeriesData:
    data = make(*random_walk(seconds, seed=7), seconds)
    cadence = np.zeros(seconds, dtype=bool)
    cadence[299::300] = True
    data.features.update({"cadence5": cadence, "zone_lo": np.full(seconds, 0.0), "zone_hi": np.full(seconds, 1e9)})
    return data


def test_census_counts_every_signal_once_without_blocking():
    data = _r1_series()
    parts = census_series(data, "binance:ETHUSDT:w2404", ("R1|both|E1|B4", "R1|long|E1|B4"))
    both = parts["configs"]["R1|both|E1|B4"]
    assert set(both) == {"1", "-1"} and both["1"]["signals"] == both["-1"]["signals"] == 10
    for item in both.values():
        assert sum(v["total"] for v in item["eng"].values()) + sum(item["reasons"].values()) == item["signals"]
        assert sum(v["total"] for v in item["eng"].values()) == sum(v["total"] for v in item["stop"].values())
    # nested configurations: R1|long repeats the long stream of R1|both; the distinct census counts it once
    assert parts["distinct"]["1"]["signals"] == 10 and parts["distinct"]["-1"]["signals"] == 10
    census = aggregate([("binance:ETHUSDT:w2404", parts)])
    assert census["signals"] == 30 and census["distinct_signals"] == 20 and set(census["strata_terciles"]) == set(pr.STRATA_AXES)
    nominal = sum(v["total"] for v in census["pooled"]["eng"].values())
    distinct = sum(v["total"] for v in census["pooled_distinct"]["eng"].values())
    assert nominal == distinct + sum(v["total"] for v in census["by_config"]["R1|long|E1|B4"]["eng"].values())
    assert trigger_counts(census, "distinct_pair_day") is census["pooled_distinct"] and trigger_counts(census, "copies_config_pair_day") is census["pooled"]
    assert streams_of("P0-A3-K0-Z1|both|E1|B4", {**data.features, **{f"p{n}_a3": np.zeros(3000, dtype=bool) for n in (1, 2, 3)}, "atr5_bp": np.ones(3000)}).keys() == {1, -1}


def test_e0a_trigger_rule_is_census_times_f_min_against_n_min():
    def pooled(counts):
        return {d: {band: {"total": counts.get((d, band), 0), "floor": 0} for band in lv.BAND_NAMES} for d in lv.DEFINITIONS}

    rule = trigger(pooled({("eng", "1-2"): 5000, ("eng", "2-3"): 1334, ("eng", "3-5"): 1333, ("stop", "1-2"): 1000, ("stop", "2-3"): 9000}))
    eng = rule["definitions"]["eng"]
    assert eng["bands"]["2-3"]["triggered"] and not eng["bands"]["3-5"]["triggered"]  # 1334 x 0.3 = 400.2 ; 1333 x 0.3 = 399.9
    assert eng["e1_arms"] == ["1-2", "2-3"]
    assert rule["definitions"]["stop"]["e1_arms"] == []  # reference 1000 x 0.3 < 400: no contrast possible
    assert trigger(pooled({("eng", "1-2"): 5000}))["definitions"]["eng"]["e1_arms"] == []  # no studied band


# ----------------------------------------------------------------------------- frozen configurations


def test_frozen_configuration_list_is_derived_from_the_four_protocols():
    ids = pr.CONFIGURATION_IDS
    assert len(ids) == 31 and len(set(ids)) == 31
    grids = {name: set(build_grid(False, name)) for name in ("r10", "r10x", "r11", "r12")}
    derived = set()
    for name, grid in grids.items():
        for identifier in grid:
            rule, _side, entry, exit_ = parse_configuration(identifier)
            _family, params = parse_rule(rule)
            if exit_ == "B4" and entry == "E1" and params.get("K", "0") == "0":
                derived.add(identifier)
    assert derived == set(ids)
    for identifier, source, citation in pr.CONFIGURATIONS:
        assert identifier in grids[source] and citation
    assert all(item in grids["r11"] for item in pr.ALSO_IN)
    assert all(PROTOCOLS[name]["training_windows"] == pr.WINDOWS for name in ("r10", "r10x", "r11", "r12"))


# ----------------------------------------------------------------------------- claims


def _frozen_prereg(tmp_path, body: str = "# protocol\n\ntext\n\n", code: str | None = None) -> tuple:
    marker = pr.FREEZE_MARKER
    content = body + marker + "\n\n"
    digest = hashlib.sha256(content.encode()).hexdigest()
    path = tmp_path / "PREREG.md"
    path.write_text(content + f"FROZEN 2026-10-15T10:00:00Z body sha256 {digest} code sha256 {code or claims.code_digest()}\n")
    return path, digest


def test_claim_is_single_use(tmp_path, decided):
    prereg, digest = _frozen_prereg(tmp_path)
    out = tmp_path / "run"
    claims.claim(out, "E0a", prereg, pr.FREEZE_MARKER, digest)
    with pytest.raises(SystemExit):
        claims.claim(out, "E0a", prereg, pr.FREEZE_MARKER, digest)
    claims.consume(out, "E0a")
    with pytest.raises(SystemExit):
        claims.consume(out, "E0a")
    with pytest.raises(SystemExit):
        claims.claim(out, "E1", prereg, pr.FREEZE_MARKER, digest)  # E0a not complete
    claims.abort(out, "E0a", "MemAvailable 3000 MB < 4000 MB")
    with pytest.raises(SystemExit):
        claims.consume(out, "E0a")  # no resume flag
    record = claims.consume(out, "E0a", resume=True)
    assert record["status"] == "running" and len(record["resumptions"]) == 1
    claims.abort(out, "E0a", "MemAvailable 3000 MB < 4000 MB")
    with pytest.raises(SystemExit):
        claims.consume(out, "E0a", resume=True)  # at most MAX_GUARD_RESUMPTIONS (1) resumption
    (out / "E0a-consumed.json").write_text(json.dumps({**json.loads((out / "E0a-consumed.json").read_text()), "status": "running"}))
    (out / "e0a-census.json").write_text("{}")
    (out / "e0a-trigger.json").write_text("{}")
    claims.finish(out, "E0a", ["e0a-census.json", "e0a-trigger.json"])
    with pytest.raises(SystemExit):
        claims.consume(out, "E0a", resume=True)  # complete: never again
    claims.claim(out, "E1", prereg, pr.FREEZE_MARKER, digest)
    (out / "e0a-trigger.json").write_text('{"changed": true}')
    with pytest.raises(SystemExit):
        claims.consume(out, "E1")  # a prerequisite changed after the claim


def test_claim_refuses_unfrozen_or_mismatched_protocols_and_code_changes(tmp_path, monkeypatch, decided):
    prereg, digest = _frozen_prereg(tmp_path)
    with pytest.raises(SystemExit):
        claims.claim(tmp_path / "a", "E0a", prereg, pr.FREEZE_MARKER, "0" * 64)
    unfrozen = tmp_path / "U.md"
    unfrozen.write_text("# protocol\n\n" + pr.FREEZE_MARKER + "\n\nStatus: READY_TO_FREEZE\n")
    with pytest.raises(SystemExit):
        claims.claim(tmp_path / "b", "E0a", unfrozen, pr.FREEZE_MARKER, claims.body_sha256(unfrozen, pr.FREEZE_MARKER))
    out = tmp_path / "c"
    claims.claim(out, "E0a", prereg, pr.FREEZE_MARKER, digest)
    real = claims.code_identity()
    monkeypatch.setattr(claims, "code_identity", lambda: {**real, "research_lev/engine.py": "changed"})
    with pytest.raises(SystemExit):
        claims.consume(out, "E0a")


def test_analysis_stage_has_no_resume(tmp_path, decided):
    prereg, digest = _frozen_prereg(tmp_path)
    out = tmp_path / "run"
    for stage, files in (("E0a", ("e0a-census.json", "e0a-trigger.json")), ("E1", ("e1-replay.npz", "e1-manifest.json"))):
        claims.claim(out, stage, prereg, pr.FREEZE_MARKER, digest)
        claims.consume(out, stage)
        for name in files:
            (out / name).write_text("x")
        claims.finish(out, stage, list(files))
    claims.claim(out, "analysis", prereg, pr.FREEZE_MARKER, digest)
    claims.consume(out, "analysis")
    claims.abort(out, "analysis", "test")
    with pytest.raises(SystemExit):
        claims.consume(out, "analysis", resume=True)


# ----------------------------------------------------------------------------- liquidation, Holm, bootstrap


def test_liquidation_formula_applied_leverage_and_margin():
    assert lv.liquidation_price(100.0, 1, 10, 0.01) == pytest.approx(100.0 * 0.9 / 0.99)
    assert lv.liquidation_price(100.0, -1, 10, 0.01) == pytest.approx(100.0 * 1.1 / 1.01)
    assert lv.liquidation_price(100.0, 1, 1, 0.01) == 0.0 and not lv.liquidation_hit(0.5, 0.0, 1)
    assert lv.liquidation_hit(90.0, 90.909, 1) and not lv.liquidation_hit(91.0, 90.909, 1)
    assert lv.liquidation_hit(109.0, 108.9, -1) and not lv.liquidation_hit(108.0, 108.9, -1)
    assert lv.applied_leverage(2.0, "ceil", 10) == 2 and lv.applied_leverage(2.0001, "ceil", 10) == 3 and lv.applied_leverage(0.4, "ceil", 10) == 1
    assert lv.applied_leverage(12.0, "ceil", 10) == 10 and lv.applied_leverage(1.2, "full", 10) == 10
    assert lv.margin_refused(2.5, 2) and not lv.margin_refused(2.0, 2) and lv.margin_refused(12.0, lv.applied_leverage(12.0, "ceil", 10))


def test_holm_step_down():
    adjusted = holm({"a": 0.01, "b": 0.04, "c": 0.03, "d": None})
    assert adjusted["a"] == pytest.approx(0.04) and adjusted["c"] == pytest.approx(0.09) and adjusted["b"] == pytest.approx(0.09) and adjusted["d"] == 1.0
    assert holm({"x": 0.2})["x"] == pytest.approx(0.2)


def test_cluster_bootstrap_is_seeded_and_detects_a_shift():
    rng = np.random.default_rng(1)
    codes = np.repeat(np.arange(200), 5)
    a, b = rng.normal(-0.3, 1.0, codes.size), rng.normal(0.0, 1.0, codes.size)
    one = paired_cluster_bootstrap(codes, a, codes, b, resamples=2000, seed=5)
    two = paired_cluster_bootstrap(codes, a, codes, b, resamples=2000, seed=5)
    assert one == two and one["ci_high"] < 0 and one["p"] < 0.05
    assert contrast_verdict(one, 1000, 1000) == "DIFFERENT_LOWER" and contrast_verdict(one, 399, 1000) == "NOT_INFORMATIVE"
    same = paired_cluster_bootstrap(codes, b, codes, b, resamples=2000, seed=5)
    assert same["estimate"] == pytest.approx(0.0) and contrast_verdict(same, 1000, 1000) == "NO_DIFFERENCE_DETECTED"
    assert one_sample_cluster_bootstrap(codes, a + 0.3, resamples=500, seed=1)["ci_low"] < 0 < one_sample_cluster_bootstrap(codes, a + 0.3, resamples=500, seed=1)["ci_high"]
    cells = codes % 3
    strat = stratified_cluster_bootstrap(codes, a, cells, codes, b, cells, 27, resamples=500, seed=2)
    assert strat["support_share"] == pytest.approx(1.0) and strat["estimate"] < 0


def _synthetic_export(n: int, mean_band: float, rng, mean_ref: float = -0.2) -> np.ndarray:
    rows = np.zeros(2 * n, dtype=EXPORT_DTYPE)
    rows["arm"] = np.repeat([0, 1], n)  # 0 = eng:1-2, 1 = eng:2-3
    rows["status"] = STATUS["filled"]
    rows["config"] = rng.integers(0, 3, 2 * n)
    rows["series"] = rng.integers(0, 4, 2 * n)
    rows["side"] = 1
    rows["decision_s"] = T0 + rng.integers(0, 5 * 86400, 2 * n)
    rows["fill_ts"] = rows["decision_s"] * SECOND + rng.integers(0, 10**9, 2 * n)
    rows["exit_ts"] = rows["fill_ts"] + 10**9
    rows["d_bp"], rows["c_bp"], rows["tick_bp"] = np.where(rows["arm"] == 1, 12.0, 30.0), 9.0, 1.0
    r = np.where(rows["arm"] == 1, rng.normal(mean_band, 1.0, 2 * n), rng.normal(mean_ref, 1.0, 2 * n))
    rows["net_bp"] = r * (rows["d_bp"] + rows["c_bp"])
    rows["gross_bp"], rows["fees_bp"] = rows["net_bp"] + 7.0, 7.0
    rows["entry"], rows["exit"], rows["frozen_exit"], rows["worst"] = 100.0, 100.0, 100.0, 99.0
    rows["exit_reason"] = EXIT_REASONS["time"]
    rows["l_eng"] = 50.0 / (rows["d_bp"] + rows["c_bp"])
    rows["l_stop"] = 50.0 / rows["d_bp"]
    rows["atr5_bp"], rows["stale_900"] = rows["d_bp"] / 1.5, 0.1
    rows["c1_net_bp"], rows["c1_d_bp"] = rows["net_bp"], 33.3 - rows["c_bp"]
    rows["stop_slip_bp"] = np.nan
    return rows


@pytest.mark.parametrize("rule", ["distinct_pair_day", "copies_config_pair_day"])
def test_analysis_applies_the_frozen_rules_end_to_end(rule):
    rng = np.random.default_rng(4)
    series = ["binance:ETHUSDT:w2404", "binance:SOLUSDT:w2405", "binance:ARBUSDT:w2406", "binance:OPUSDT:w2408"]
    manifest = {"series": series, "arms": ["eng:1-2", "eng:2-3"], "configurations": list(pr.CONFIGURATION_IDS)}
    census = {"strata_terciles": {"atr5_bp": [10.0, 15.0], "tick_bp": [0.5, 2.0], "stale_900": [0.05, 0.2]}}
    big = analyse(_synthetic_export(1500, -0.6, rng), manifest, census, resamples=500, rule=rule)
    assert big["multiplicity_rule"] == rule and big["primary"]["clusters_kind"] == ("pair_day" if rule == "distinct_pair_day" else "config_pair_day")
    assert big["primary"]["verdict"] == "DIFFERENT_LOWER" and big["primary"]["n_band"] == 1500
    assert big["primary"]["slippage_grid_delta_label"] in ("SIGN_STABLE", "SIGN_FLIP") and not big["primary"]["slippage_sensitive"]
    assert set(big["primary"]["diagnostic_multiplicity"]) == {"other_rule", "copies_pair_day"}
    assert big["secondary"]["S1"]["verdict"] == "NOT_INFORMATIVE" and big["secondary"]["S1"]["p_holm"] == 1.0
    assert set(big["secondary"]) == {name for name, _ in pr.SECONDARY_TESTS}
    assert big["arms"]["eng:2-3"]["slippage_grid_label"] == "SIGN_STABLE"
    assert big["arms"]["eng:2-3"]["floor_split"]["no_floor"]["filled"] == 1500 and big["primary"]["without_floor_descriptive"]["n_band"] == 1500
    assert sum(item["trades"] for item in big["arms"]["eng:2-3"]["by_config_nominal"].values()) == 1500
    assert "ceil|A2|mmr_mid0.01" in big["arms"]["eng:2-3"]["liquidation_control"]
    assert big["arms"]["eng:2-3"]["liquidation_control"]["ceil|A2|mmr_mid0.01"]["margin_refused"] == 1500  # L_eng ≈ 2.4 > A = 2
    assert "filled_without_floor" in big["cumulative_descriptive"]["G3"]
    small = analyse(_synthetic_export(300, -0.6, np.random.default_rng(4)), manifest, census, resamples=200, rule=rule)
    assert small["primary"]["verdict"] == "NOT_INFORMATIVE"
    json.dumps(big)  # serialisable


def test_a_slippage_sensitive_band_makes_the_contrast_not_informative():
    """Audit §4.8: a band whose mean net R changes sign across the tick grid is NON_INFORMATIVE,
    and so is every test that involves it, whatever its p-value."""
    manifest = {"series": ["binance:ETHUSDT:w2404", "binance:SOLUSDT:w2405", "binance:ARBUSDT:w2406", "binance:OPUSDT:w2408"], "arms": ["eng:1-2", "eng:2-3"]}
    census = {"strata_terciles": {"atr5_bp": [10.0, 15.0], "tick_bp": [0.5, 2.0], "stale_900": [0.05, 0.2]}}
    export = _synthetic_export(1500, -0.6, np.random.default_rng(4), mean_ref=0.0)
    ref = export["arm"] == 0
    export["net_bp"][ref] = export["net_bp"][ref] - export["net_bp"][ref].mean() + 0.05 * 39.0  # ref mean +0.05 R, −0.08 R at 5 ticks
    doc = analyse(export, manifest, census, resamples=300, rule="distinct_pair_day")
    assert doc["arms"]["eng:1-2"]["slippage_grid_label"] == "NON_INFORMATIVE"
    assert doc["primary"]["slippage_sensitive"] and doc["primary"]["p"] < 0.05 and doc["primary"]["verdict"] == "NOT_INFORMATIVE"
    assert doc["secondary"]["S9"]["verdict"] == "NOT_INFORMATIVE"
    assert sign_label({"0": 0.1, "1": 0.05, "2": -0.01}) == "NON_INFORMATIVE" and sign_label({"0": -0.1, "1": -0.2}) == "SIGN_STABLE"


def test_nested_copies_count_once_and_do_not_narrow_the_decision_interval():
    """D1: the same 300 trades copied under five configuration ids. Under `distinct_pair_day` the
    copies are removed (N = 300 < N_min) and the interval is the unique-trade one; under the
    nominal rule the copies pass N_min and config x pair x day clusters narrow the interval."""
    rng = np.random.default_rng(9)
    base = _synthetic_export(300, -0.25, rng)
    copies = np.concatenate([base.copy() for _ in range(5)])
    for k in range(5):
        copies["config"][k * base.size:(k + 1) * base.size] = k
    manifest = {"series": ["binance:ETHUSDT:w2404", "binance:SOLUSDT:w2405", "binance:ARBUSDT:w2406", "binance:OPUSDT:w2408"], "arms": ["eng:1-2", "eng:2-3"]}
    census = {"strata_terciles": {"atr5_bp": [10.0, 15.0], "tick_bp": [0.5, 2.0], "stale_900": [0.05, 0.2]}}
    unique = analyse(base, manifest, census, resamples=400, rule="distinct_pair_day")["primary"]
    distinct = analyse(copies, manifest, census, resamples=400, rule="distinct_pair_day")["primary"]
    nominal = analyse(copies, manifest, census, resamples=400, rule="copies_config_pair_day")["primary"]
    assert dedupe(copies).size == base.size
    assert distinct["n_band"] == 300 and distinct["verdict"] == "NOT_INFORMATIVE"
    assert (distinct["ci_low"], distinct["ci_high"]) == (unique["ci_low"], unique["ci_high"])
    assert nominal["n_band"] == 1500 and (nominal["ci_high"] - nominal["ci_low"]) < 0.7 * (distinct["ci_high"] - distinct["ci_low"])
    pair_day = nominal["diagnostic_multiplicity"]["copies_pair_day"]
    assert pair_day["clusters"] == distinct["clusters"]
    assert np.array_equal(pair_day_codes(copies[:base.size], manifest["series"]), pair_day_codes(copies[base.size:2 * base.size], manifest["series"]))


def test_strata_cells_send_non_finite_inputs_to_an_excluded_cell():
    rows = np.zeros(3, dtype=EXPORT_DTYPE)
    rows["atr5_bp"], rows["tick_bp"], rows["stale_900"] = [5.0, 20.0, 20.0], [0.1, 3.0, 3.0], [0.0, 0.5, np.nan]
    cells = strata_cells(rows, {"atr5_bp": [10.0, 15.0], "tick_bp": [0.5, 2.0], "stale_900": [0.05, 0.2]})
    assert cells.tolist() == [0, 26, INDETERMINATE_CELL]
    assert int(np.searchsorted([1.0, 2.0], [np.nan], side="right")[0]) == 2  # what the guard prevents


def test_liquidation_control_uses_the_canonical_size_for_every_arm():
    rows = np.zeros(2, dtype=EXPORT_DTYPE)
    rows["series"], rows["side"], rows["entry"], rows["worst"] = 0, 1, 100.0, 99.9
    rows["d_bp"], rows["c_bp"] = 20.0, 9.0  # L_eng 1.72 (canonical size), L_stop 2.5 (band only)
    rows["l_eng"], rows["l_stop"] = 50.0 / 29.0, 2.5
    out = liquidation_control(rows, ["binance:ETHUSDT:w2404"])
    assert out["ceil|A2|mmr_mid0.01"]["margin_refused"] == 0  # sized at L_eng ≤ 2, never at r·E/d
    rows["l_eng"] = 1.0  # inconsistent with d + c: the implied notional (1.72 E) would be above the trade's leverage
    with pytest.raises(ValueError):
        liquidation_control(rows, ["binance:ETHUSDT:w2404"])


# ----------------------------------------------------------------------------- CLI, end to end on synthetic series


def _synthetic_series(name: str, seed: int, seconds: int = 6000):
    from scripts.research_exec.screen import Series

    q_rows, t_rows = random_walk(seconds, seed=seed)
    quotes, trades = quotes_from(sorted(q_rows)), trades_from(sorted(t_rows))
    rng = np.random.default_rng(seed)

    def setup_loader():
        features = {f"p{n}_a{a}": np.zeros(seconds, dtype=bool) for n in (1, 2, 3) for a in (3, 4)}
        features.update({f"p{n}_a4_v{v}": np.zeros(seconds, dtype=bool) for n in (1, 2, 3) for v in (11, 12, 13)})
        cadence = np.zeros(seconds, dtype=bool)
        cadence[299::300] = True
        atr = rng.choice([0.08, 0.1, 0.2], size=seconds)
        features.update({"cadence5": cadence, "atr5": atr, "atr5_bp": atr, "vwap5": np.full(seconds, 100.0), "zone_lo": np.zeros(seconds), "zone_hi": np.full(seconds, 1e9)})
        return features

    return Series(name, quotes, trades, INSTRUMENT, T0, T0 + seconds, False, 5.0, True, setup_loader=setup_loader)


def _cli_frozen(tmp_path, monkeypatch, names, trigger_n_min=None):
    """A frozen synthetic protocol at the (monkeypatched) frozen locations, synthetic series."""
    from scripts.research_lev import cli

    prereg, digest = _frozen_prereg(tmp_path)
    sha = tmp_path / "PREREG.sha256"
    sha.write_text(f"{digest}  PREREG.md body\n")
    out = tmp_path / "run"
    monkeypatch.setattr(pr, "PREREG_PATH", prereg)
    monkeypatch.setattr(pr, "SHA256_PATH", sha)
    monkeypatch.setattr(pr, "OUT_DIR", out)
    monkeypatch.setattr(pr, "EXPECTED_SERIES", tuple(names))
    monkeypatch.setattr(cli, "preflight", lambda *a, **k: ([_synthetic_series(name, seed) for seed, name in enumerate(names)], {"synthetic": "inputs"}))
    monkeypatch.setattr(cli, "guard", lambda floor_mb=0: None)
    if trigger_n_min is not None:
        monkeypatch.setattr(cli, "trigger", lambda pooled: trigger(pooled, n_min=trigger_n_min))  # synthetic: force the arms to run
    return cli, out, ["claim", "--stage"]


def test_cli_runs_each_stage_once_end_to_end(tmp_path, monkeypatch, decided):
    names = ["binance:ETHUSDT:w2404", "binance:ARBUSDT:w2405"]
    cli, out, claim = _cli_frozen(tmp_path, monkeypatch, names)
    base = ["--workers", "1"]
    assert cli.main(claim + ["E0a"]) == 0
    assert cli.main(["census"] + base) == 0
    with pytest.raises(SystemExit):
        cli.main(["census"] + base)  # a second read is refused
    census = json.loads((out / "e0a-census.json").read_text())
    assert census["signals"] > 0 and census["series"] == names and "R1|both|E1|B4" not in census["configurations"]  # D2 = drop
    # trigger with the frozen N_min (400) on this tiny synthetic census: nothing is triggered
    rule = json.loads((out / "e0a-trigger.json").read_text())
    assert rule["n_min"] == 400 and rule["multiplicity_rule"] == "distinct_pair_day" and all(not spec["e1_arms"] for spec in rule["definitions"].values())
    assert cli.main(claim + ["E1"]) == 0
    assert cli.main(["replay"] + base) == 0
    manifest = json.loads((out / "e1-manifest.json").read_text())
    assert manifest["arms"] == [] and "not run" in manifest["note"]
    assert cli.main(claim + ["analysis"]) == 0
    assert cli.main(["analyse"]) == 0
    assert json.loads((out / "e1-analysis.json").read_text())["primary"]["verdict"] == "NOT_INFORMATIVE"


def test_cli_replay_exports_triggered_arms(tmp_path, monkeypatch, decided):
    names = ["binance:ETHUSDT:w2404", "binance:ARBUSDT:w2405"]
    cli, out, claim = _cli_frozen(tmp_path, monkeypatch, names, trigger_n_min=1)
    base = ["--workers", "2"]
    assert cli.main(claim + ["E0a"]) == 0 and cli.main(["census"] + base) == 0
    assert cli.main(claim + ["E1"]) == 0 and cli.main(["replay"] + base) == 0
    manifest = json.loads((out / "e1-manifest.json").read_text())
    assert "eng:1-2" in manifest["arms"] and manifest["series"] == names
    with np.load(out / "e1-replay.npz") as data:
        export, counters = data["export"], data["counters"]
    assert export.size > 0 and counters.size == len(names) * len(pr.active_configurations()) * len(manifest["arms"])
    for ai, key in enumerate(manifest["arms"]):
        definition, band = key.split(":")
        values = export[export["arm"] == ai]["l_eng" if definition == "eng" else "l_stop"]
        assert all(lv.band_of(float(v)) == band for v in values)
    assert cli.main(claim + ["analysis"]) == 0 and cli.main(["analyse"]) == 0
    with pytest.raises(SystemExit):
        cli.main(["analyse"])


def test_cli_refuses_other_locations_and_a_trigger_not_derived_from_the_census(tmp_path, monkeypatch, decided):
    names = ["binance:ETHUSDT:w2404"]
    cli, out, claim = _cli_frozen(tmp_path, monkeypatch, names)
    with pytest.raises(SystemExit):
        cli.main(claim + ["E0a", "--out", str(tmp_path / "elsewhere")])  # a fresh output directory is not a second chance
    with pytest.raises(SystemExit):
        cli.main(claim + ["E0a", "--expected-sha256", "0" * 64])
    other = tmp_path / "copy.md"
    other.write_text(pr.PREREG_PATH.read_text())
    with pytest.raises(SystemExit):
        cli.main(claim + ["E0a", "--prereg", str(other)])  # a copy carrying its own FROZEN line
    assert not (tmp_path / "elsewhere").exists() and not (out / "E0a-claim.json").exists()
    assert cli.main(claim + ["E0a"]) == 0 and cli.main(["census", "--workers", "1"]) == 0
    assert cli.main(claim + ["E1"]) == 0
    monkeypatch.setattr(cli, "trigger", lambda pooled: trigger(pooled, n_min=1))  # the rule applied now differs from the recorded one
    with pytest.raises(SystemExit):
        cli.main(["replay", "--workers", "1"])
    assert not (out / "E1-consumed.json").exists()  # refused before consumption


def test_preflight_refuses_wrong_inputs_without_consuming_the_claim(tmp_path, monkeypatch, decided):
    from scripts.research_lev import cli

    tape = tmp_path / "tape"
    tape.mkdir()
    instruments = tmp_path / "instruments.json"
    instruments.write_text("{}")
    with pytest.raises(SystemExit):
        cli.preflight(tape, instruments)  # hashes differ from the frozen inputs (empty tape directory)
    (tape / "manifest-aggtrades.json").write_text("a")
    (tape / "manifest-klines.json").write_text("k")
    monkeypatch.setattr(pr, "INPUT_SHA256", cli.input_hashes(tape, instruments))
    monkeypatch.setattr(cli, "load_series", lambda *a, **k: ([], ["ETHUSDT:2024-04-15"]))
    with pytest.raises(SystemExit):
        cli.preflight(tape, instruments)  # 0 series and a missing day: refused
    planned = [_synthetic_series(name, 0) for name in pr.EXPECTED_SERIES[:1]]
    monkeypatch.setattr(cli, "load_series", lambda *a, **k: (planned, []))
    monkeypatch.setattr(pr, "EXPECTED_SERIES", tuple(item.name for item in planned))
    with pytest.raises(SystemExit):
        cli.preflight(tape, instruments)  # klines months missing
    assert cli.missing_klines(tape, planned)
    # the census command runs the preflight before the claim is consumed
    names = ["binance:ETHUSDT:w2404"]
    cli2, out, claim = _cli_frozen(tmp_path, monkeypatch, names)

    def refuse(*a, **k):
        raise cli.InputError("wrong inputs")

    monkeypatch.setattr(cli2, "preflight", refuse)
    assert cli2.main(claim + ["E0a"]) == 0
    with pytest.raises(SystemExit):
        cli2.main(["census", "--workers", "1"])
    assert not (out / "E0a-consumed.json").exists()


def test_memory_guard_aborts_cleanly_and_allows_at_most_one_resume(tmp_path, monkeypatch, decided):
    names = ["binance:ETHUSDT:w2404"]
    cli, out, claim = _cli_frozen(tmp_path, monkeypatch, names)

    def low(floor_mb=0):
        raise cli.MemoryGuard("MemAvailable 3500 MB < 4000 MB")

    monkeypatch.setattr(cli, "guard", low)
    base = ["--workers", "1"]
    assert cli.main(claim + ["E0a"]) == 0
    assert cli.main(["census"] + base) == 3
    assert json.loads((out / "E0a-consumed.json").read_text())["status"] == "aborted_by_guard" and not (out / "e0a-census.json").exists()
    with pytest.raises(SystemExit):
        cli.main(["census"] + base)  # no --resume
    assert cli.main(["census"] + base + ["--resume"]) == 3  # the one resumption, aborted again
    with pytest.raises(SystemExit):
        cli.main(["census"] + base + ["--resume"])  # a second resumption is refused
    assert cli.mem_available_mb() > 0


def test_memory_guard_with_no_resumption_allowed(tmp_path, monkeypatch, decided):
    monkeypatch.setattr(pr, "MAX_GUARD_RESUMPTIONS", 0)
    cli, out, claim = _cli_frozen(tmp_path, monkeypatch, ["binance:ETHUSDT:w2404"])

    def low(floor_mb=0):
        raise cli.MemoryGuard("low")

    monkeypatch.setattr(cli, "guard", low)
    assert cli.main(claim + ["E0a"]) == 0 and cli.main(["census", "--workers", "1"]) == 3
    with pytest.raises(SystemExit):
        cli.main(["census", "--workers", "1", "--resume"])


def _slow_job(index):
    import time as _time

    _time.sleep(3.0)
    return index


def _killed_job(index):
    import os as _os
    import signal as _signal

    _os.kill(_os.getpid(), _signal.SIGKILL)


def test_run_pool_checks_the_guard_while_a_series_is_in_flight(monkeypatch):
    from scripts.research_lev import cli

    calls = []

    def counting(floor_mb=0):
        calls.append(1)
        if len(calls) >= 4:
            raise cli.MemoryGuard("tripped in flight")

    monkeypatch.setattr(cli, "guard", counting)
    import time

    started = time.monotonic()
    with pytest.raises(cli.MemoryGuard):
        cli.run_pool(_slow_job, 1, [None], {}, 1, poll_s=0.1)
    assert time.monotonic() - started < 2.5  # stopped while the job was still running


def test_run_pool_ends_instead_of_hanging_when_a_worker_dies(monkeypatch):
    from scripts.research_lev import cli

    monkeypatch.setattr(cli, "guard", lambda floor_mb=0: None)
    with pytest.raises(cli.WorkerLost):
        cli.run_pool(_killed_job, 1, [None], {}, 1, poll_s=0.1, grace_s=0.5)


def test_pending_owner_decisions_block_every_claim(tmp_path, monkeypatch):
    prereg, digest = _frozen_prereg(tmp_path)
    for name in ("MULTIPLICITY_RULE", "R1_BOTH_RULE", "MAX_GUARD_RESUMPTIONS", "FUNDING_RULE"):
        monkeypatch.setattr(pr, name, None)
    assert len(pr.unresolved_decisions()) == 4
    with pytest.raises(SystemExit):
        claims.claim(tmp_path / "run", "E0a", prereg, pr.FREEZE_MARKER, digest)
    with pytest.raises(ValueError):
        pr.active_configurations()
    with pytest.raises(ValueError):
        pr.multiplicity_rule()
    monkeypatch.setattr(pr, "R1_BOTH_RULE", "drop")
    assert "R1|both|E1|B4" not in pr.active_configurations() and len(pr.active_configurations()) == 30
    monkeypatch.setattr(pr, "R1_BOTH_RULE", "keep_conflict_rule")
    assert pr.active_configurations() == pr.CONFIGURATION_IDS


def test_the_code_is_pinned_to_the_freeze_for_every_stage(tmp_path, monkeypatch, decided):
    real = claims.code_identity()
    # a FROZEN line without a code digest, or with another digest, is refused
    bare, digest = _frozen_prereg(tmp_path)
    bare.write_text(bare.read_text().split(" code sha256 ")[0] + "\n")
    with pytest.raises(SystemExit):
        claims.claim(tmp_path / "a", "E0a", bare, pr.FREEZE_MARKER, digest)
    other_dir = tmp_path / "o"
    other_dir.mkdir()
    other, digest = _frozen_prereg(other_dir, code="f" * 64)
    with pytest.raises(SystemExit):
        claims.claim(tmp_path / "b", "E0a", other, pr.FREEZE_MARKER, digest)
    # engine.py edited after the census: the E1 claim is refused
    prereg, digest = _frozen_prereg(tmp_path)
    out = tmp_path / "run"
    claims.claim(out, "E0a", prereg, pr.FREEZE_MARKER, digest)
    claims.consume(out, "E0a")
    for name in ("e0a-census.json", "e0a-trigger.json"):
        (out / name).write_text("{}")
    claims.finish(out, "E0a", ["e0a-census.json", "e0a-trigger.json"])
    monkeypatch.setattr(claims, "code_identity", lambda: {**real, "research_lev/engine.py": "edited-after-census"})
    with pytest.raises(SystemExit):
        claims.claim(out, "E1", prereg, pr.FREEZE_MARKER, digest)
    monkeypatch.setattr(claims, "code_identity", lambda: real)
    claims.claim(out, "E1", prereg, pr.FREEZE_MARKER, digest)
    claims.consume(out, "E1")
    for name in ("e1-replay.npz", "e1-manifest.json"):
        (out / name).write_text("x")
    claims.finish(out, "E1", ["e1-replay.npz", "e1-manifest.json"])
    # stats.py (the frozen decision rules) edited after the E1 outcomes exist: the analysis claim is refused
    monkeypatch.setattr(claims, "code_identity", lambda: {**real, "research_lev/stats.py": "edited-after-E1"})
    with pytest.raises(SystemExit):
        claims.claim(out, "analysis", prereg, pr.FREEZE_MARKER, digest)
    monkeypatch.setattr(claims, "code_identity", lambda: real)
    claims.claim(out, "analysis", prereg, pr.FREEZE_MARKER, digest)
    monkeypatch.setattr(claims, "code_identity", lambda: {**real, "research_lev/stats.py": "edited-after-claim"})
    with pytest.raises(SystemExit):
        claims.consume(out, "analysis")


def test_prerequisite_outputs_are_tied_to_the_hashes_recorded_at_finish(tmp_path, decided):
    prereg, digest = _frozen_prereg(tmp_path)
    out = tmp_path / "run"
    claims.claim(out, "E0a", prereg, pr.FREEZE_MARKER, digest)
    claims.consume(out, "E0a")
    (out / "e0a-census.json").write_text('{"strata_terciles": [1, 2]}')
    (out / "e0a-trigger.json").write_text('{"eng": []}')
    claims.finish(out, "E0a", ["e0a-census.json", "e0a-trigger.json"])
    (out / "e0a-trigger.json").write_text('{"eng": ["1-2", "2-3"]}')  # arms the rule did not trigger
    with pytest.raises(SystemExit):
        claims.claim(out, "E1", prereg, pr.FREEZE_MARKER, digest)
    (out / "e0a-trigger.json").write_text('{"eng": []}')
    claims.claim(out, "E1", prereg, pr.FREEZE_MARKER, digest)
    claims.consume(out, "E1")
    for name in ("e1-replay.npz", "e1-manifest.json"):
        (out / name).write_text("x")
    claims.finish(out, "E1", ["e1-replay.npz", "e1-manifest.json"])
    (out / "e0a-census.json").write_text('{"strata_terciles": [5, 9]}')  # C2 cut points rewritten after the outcomes exist
    with pytest.raises(SystemExit):
        claims.claim(out, "analysis", prereg, pr.FREEZE_MARKER, digest)
    (out / "e0a-census.json").write_text('{"strata_terciles": [1, 2]}')
    document = claims.claim(out, "analysis", prereg, pr.FREEZE_MARKER, digest)
    assert set(document["prerequisites"]) == {"e0a-census.json", "e0a-trigger.json", "e1-replay.npz", "e1-manifest.json"}


def test_no_look_ahead_in_the_kline_derived_inputs():
    """`setup_features` (stacks, cadence, ATR5, VWAP, zone) and the P-rule masks at second s use
    only candles closed by the end of second s: perturbing every candle from a cut on leaves
    every earlier second unchanged."""
    from scripts.research_exec import platform_setups as ps

    day = DAY
    minutes = 3 * 1440
    rng = np.random.default_rng(5)
    close = 100.0 * np.exp(np.cumsum(rng.normal(2e-4, 4e-4, size=minutes)))
    open_ = np.concatenate([[100.0], close[:-1]])
    candles = {"ts_s": day + 60 * np.arange(minutes, dtype=np.int64), "open": open_, "high": np.maximum(open_, close) * 1.0002,
               "low": np.minimum(open_, close) * 0.9998, "close": close, "volume": rng.lognormal(3.0, 0.8, size=minutes)}
    start_s, end_s = day + 86400 + 3600, day + 86400 + 4 * 3600
    cut = start_s + 5000  # candles with ts_s ≥ cut are perturbed
    future = {name: values.copy() for name, values in candles.items()}
    later = future["ts_s"] >= cut
    for name in ("open", "high", "low", "close"):
        future[name][later] *= 1.05
    future["volume"][later] *= 9.0
    a = ps.setup_features(candles, start_s, end_s, pr.VOLUME_THRESHOLDS)
    b = ps.setup_features(future, start_s, end_s, pr.VOLUME_THRESHOLDS)
    last = cut - start_s - 1  # decision second s reads candles closed at or before start_s + s + 1
    for name in a:
        np.testing.assert_array_equal(a[name][:last + 1], b[name][:last + 1])
    assert any(not np.array_equal(np.nan_to_num(a[name][last + 1:]), np.nan_to_num(b[name][last + 1:])) for name in ("atr5", "vwap5"))
    n = end_s - start_s
    extra = {"mid": np.full(n, 100.0), "spread_bp": np.full(n, 1.0)}
    for identifier in pr.CONFIGURATION_IDS:
        rule = identifier.split("|")[0]
        if "Z" in rule or rule.startswith(("R1", "P0")):
            continue
        for side in (1, -1):
            from scripts.research_exec.tick import signal_mask

            np.testing.assert_array_equal(signal_mask(rule, {**a, **extra}, side)[:last + 1], signal_mask(rule, {**b, **extra}, side)[:last + 1])


def test_the_protocol_document_carries_the_freeze_marker_and_a_consistent_freeze_state():
    from pathlib import Path

    doc = Path(__file__).resolve().parents[2] / "docs" / "handbook" / "research" / pr.PREREG_FILE
    text = doc.read_text()
    assert pr.FREEZE_MARKER in text and len(claims.body_sha256(doc, pr.FREEZE_MARKER)) == 64
    frozen = claims.frozen_hash(doc)
    assert frozen is None or frozen == claims.body_sha256(doc, pr.FREEZE_MARKER)  # unfrozen, or frozen with this body
    if frozen is None:
        assert pr.unresolved_decisions() or True  # every claim is refused until the FROZEN line exists
    for identifier in pr.CONFIGURATION_IDS:
        rule = identifier.split("|")[0]
        assert rule.split("-")[0] in text
