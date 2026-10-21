"""Offline tests of the G5 event study (`scripts/research_lp/g5_eval.py`, strict spec format `g5_eval/2`) on synthetic
1-minute histories and a synthetic capture tape: the strict spec reader (unknown keys, `requires`, renamed keys, the
look-ahead lock), the loaders (reference price without fallback, unconfirmed candles, realized rates only, coverage), the
generators (DST, exclusion dates, 8 h interval filter), the controls (same days, minute of the hour, union exclusion), the
measures (roles, costs, stress, funding unknown, zero volume, mixed source, dead zone, premium average), the instant unit,
a planted effect recovered with C1–C6, the INCONCLUSIVE paths, the H0 calibration over 1 000 seeds, the economic reading,
Holm, the capture simulation (maker / taker entry, funding grace) and the command line.

No test opens a connection (socket-level autouse guard) and no market data exists anywhere here."""

from __future__ import annotations

import copy
import dataclasses
import json
import math
import socket
import urllib.request
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import cli, g5_eval, g5_fetch, lp_sim, tape

US = tape.SECOND_US
MINUTE_US = 60 * US
HOUR_US = 60 * MINUTE_US
DAY_US = tape.DAY_S * US
INST = "BTC-USD_UM_XPERP-310404"
INST2 = "ETH-USD_UM_XPERP-310404"
INDEX = "BTC-USD"
INDEX2 = "ETH-USD"
RATE_CYCLE = (3e-4, 1e-4, -2e-4)  # longs pay (outside the dead zone), exactly I = 0.01 % (dead zone), shorts pay


@pytest.fixture(autouse=True)
def _no_network(monkeypatch):
    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError("network access attempted")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket.socket, "connect_ex", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)
    monkeypatch.setattr(g5_fetch.UrllibClient, "get", refuse)


BASE_REQUIRES = ["price_source_last", "n_unit_instant", "execution_role_costs", "verdict_c2_c6", "days_per_year", "quality_rules"]
T01 = {"type": "T01_funding_8h", "primary": True, "execution_role": "maker_maker", "days_per_year": 365,
       "description": "photo de funding 8 h, taux réglé hors zone morte, fournisseur du côté payeur armé à h−5",
       "generator": {"kind": "funding", "fallback_hours": [], "interval_hours": 8, "interval_tolerance_min": 1},
       "sign": {"kind": "field_sign", "field": "funding_rate", "at_min": 0, "dead_zone": [0.0, 0.0001], "eps": 1e-9},
       "control_sign": {"kind": "premium_wavg", "base": "premium_mark_index", "at_min": -5, "minutes": 480, "min_coverage": 0.9, "upper": 0.0006, "lower": -0.0005,
                        "concordance_min": 0.80, "concordance_on": "class"},
       "window": {"from_min": -5, "to_min": 15}, "arm_min": -5, "rest_until_min": 1, "restrict_to_coverage": ["funding_hist", "mark_1m", "index_1m"],
       "declared_lookahead": {"field": "sign", "reason": "R_h is computed at h-1 (funding page EEE l.258-270); its class is known at h-5 up to the dead-zone frontier (LP-G5 §5.1)"}}
T05 = {"type": "T05_us_open", "primary": True, "execution_role": "taker_maker", "days_per_year": 252, "description": "ouverture cash US, continuation",
       "generator": {"kind": "us_open", "exclude_dates": ["2026-06-19"], "skip_dates": ["2026-06-10"]}, "sign": {"kind": "return_sign", "from_min": 0, "to_min": 5},
       "window": {"from_min": 5, "to_min": 60}, "arm_min": 5, "rest_until_min": 6, "control_minute_of_hour": 30}
MACRO = {"type": "T11_macro_filter", "evaluate": False, "generator": {"kind": "explicit", "instants": ["2026-06-10T12:30:00Z", "2026-06-17T18:00:00Z"]}, "description": "filtre"}
SPEC = {
    "spec_id": "g5-synthetic-test", "source": "history", "unit": "instant", "price_source": "last",
    "requires": BASE_REQUIRES + ["funding_rate_sign_dead_zone", "interval_8h_filter", "control_sign_premium_wavg", "premium_named_fields", "controls_same_days_minute_zero",
                                 "declared_lookahead", "us_open_exclude_dates", "controls_minute_of_hour", "coverage_restriction"],
    "window": {"start": "2026-06-01", "end_exclusive": "2026-06-25"},
    "alpha": 0.10, "threshold_bp": 10.0, "min_instants": 30, "min_days": 10, "primary_measure": "net_bp",
    "bootstrap": {"resamples": 200, "seed": 7, "block_days": 1},
    "execution": {"maker_bp": 2.0, "taker_bp": 5.0, "half_spread_bp": 0.0, "adverse_selection_bp_per_leg": 2.0, "slippage_bp": 2.0, "cost_stress_multiplier": 1.5,
                  "adverse_selection_sensitivity_bp_per_leg": 3.5},
    "controls": {"hours": [4, 12, 20], "random_per_day": 3, "exclusion_min": 60, "seed": 20261102, "minute_zero_only": True, "same_days_as_events": True},
    "quality": {"max_missing_window_share": 0.20, "max_proxy_missing_share": 0.50, "max_fetch_error_share": 0.10, "max_funding_unknown_share": 0.20, "exclude_zero_volume": False},
    "economic": {"s_eco_usd_per_year": 97.0, "max_fills_per_instant": 4, "order_notional_usd": 100.0},
    "events": [T01, T05, MACRO],
}


def doc(**changes) -> dict:
    document = copy.deepcopy(SPEC)
    document.update(copy.deepcopy(changes))
    return document


def spec_of(**changes) -> g5_eval.Spec:
    return g5_eval.parse_spec(doc(**changes))


def settlement_plan(spec: g5_eval.Spec) -> tuple[np.ndarray, np.ndarray]:
    """8 h settlements (00 / 08 / 16 UTC) from the padded start to a day after the end, rates cycling through RATE_CYCLE."""
    hs, rs = [], []
    for d in range(int(spec.pad_start_us // DAY_US), int(spec.end_us // DAY_US) + 2):
        for j, hour in enumerate((0, 8, 16)):
            hs.append(d * DAY_US + hour * HOUR_US)
            rs.append(RATE_CYCLE[(d + j) % 3])
    return np.array(hs, dtype=np.int64), np.array(rs)


def synthetic(spec: g5_eval.Spec, seed: int = 3, drift_bp: float = 0.0, sigma: float = 2e-4, premium_level: float = 8e-4, flip_proxy: bool = False,
              unknown_rate_at=(), funding_start_us: int | None = None, inst: str = INST) -> dict:
    """Arrays of a synthetic instrument on the spec's grid: a Gaussian walk of the last price (no premium inside it), a mark
    = last × (1 + premium) where the premium of the 8 h period ending at h has the class of R_h (± premium_level, 0 in the dead
    zone; inverted when `flip_proxy`), and, when `drift_bp`, a drift of s_h × drift_bp over (h − 5, h + 15] at every settlement
    outside the dead zone."""
    grid = np.arange(spec.pad_start_us, spec.end_us + MINUTE_US, MINUTE_US, dtype=np.int64)
    n = grid.size
    rng = np.random.default_rng(seed)
    ret = rng.normal(0.0, sigma, n)
    ret[0] = 0.0
    hs, rs = settlement_plan(spec)
    cls = np.where(rs > 1e-4 + 1e-9, 1, np.where(rs < -1e-9, -1, 0))
    premium = np.zeros(n)
    for h, c in zip(hs.tolist(), cls.tolist()):
        k = (h - int(grid[0])) // MINUTE_US
        lo, hi = max(0, k - 479), min(n - 1, k)
        if hi >= lo:
            premium[lo: hi + 1] = c * premium_level * (-1 if flip_proxy else 1)
        if c and drift_bp and 1 <= k - 4 and k + 15 < n:
            ret[k - 4: k + 16] += c * drift_bp / 1e4 / 20.0
    price = 100.0 * np.exp(np.cumsum(ret))
    return {"grid": grid, "price": price, "mark": price * (1.0 + premium), "premium": premium, "hs": hs, "rs": rs, "inst": inst,
            "unknown": np.array(sorted(unknown_rate_at), dtype=np.int64), "funding_start_us": funding_start_us}


def memory_series(spec: g5_eval.Spec, arrays: dict, volume: np.ndarray | None = None) -> g5_eval.Series:
    grid, hs, rs = arrays["grid"], arrays["hs"], arrays["rs"]
    known = ~np.isin(hs, arrays["unknown"])
    start = arrays["funding_start_us"]
    funding = lp_sim.FundingSchedule(hs[known], rs[known], source="synthetic realized")
    if start is not None:
        funding = lp_sim.FundingSchedule(hs[known & (hs >= start)], rs[known & (hs >= start)], source="synthetic realized")
    settlements = hs if start is None else hs[hs >= start]
    coverage = {name: int(grid[0]) for name in tape.HISTORY_LAYOUTS}
    if start is not None:
        coverage["funding_hist"] = int(start)
    return g5_eval.Series(arrays["inst"], grid, arrays["price"], np.ones(grid.size, dtype=np.int8), arrays["mark"], np.full(grid.size, 10.0) if volume is None else volume,
                          {"premium_mark_index": arrays["premium"]}, np.full(grid.size, 1.0), funding, settlements, arrays["unknown"], g5_eval._assumed_unknown(spec, coverage["funding_hist"]),
                          coverage, None, True, spec.start_us)


def write_tape(root: Path, spec: g5_eval.Spec, arrays_list: list[dict], unconfirmed_at=(), drop_last_at=(), manifest_streams: dict | None = None) -> None:
    """The synthetic arrays in the `g5_fetch` layouts (candles opening at g_{k-1}, closing at g_k), finalized, with an instruments file."""
    store = tape.TapeStore(root, "values", source={"rest": "synthetic"}, kind=g5_fetch.MANIFEST_KIND)
    rows = []
    for arrays in arrays_list:
        inst, index = arrays["inst"], f"{arrays['inst'].split('-')[0]}-USD"
        grid, price, mark = arrays["grid"], arrays["price"], arrays["mark"]
        for k in range(1, grid.size):
            ts = int(grid[k - 1])
            confirm = 0 if ts in unconfirmed_at else 1
            if ts not in drop_last_at:
                store.append("candles_1m", inst, ts, (ts, ts, price[k - 1], max(price[k - 1], price[k]), min(price[k - 1], price[k]), price[k], 10.0, 0.001, 100.0, confirm))
            store.append("mark_1m", inst, ts, (ts, ts, mark[k - 1], mark[k], mark[k], mark[k], 1))
            store.append("index_1m", index, ts, (ts, ts, price[k - 1], price[k], price[k], price[k], 1))
        for h, r in zip(arrays["hs"].tolist(), arrays["rs"].tolist()):
            if h < g5_fetch.END_EXCLUSIVE_US:
                store.append("funding_hist", inst, h, (h, h, r * 1.1, np.nan if h in arrays["unknown"] else r, 1, 1))
        rows.append({"instId": inst, "uly": index, "indexId": index, "tickSz": "0.1", "ctVal": "0.0001", "lotSz": "1", "minSz": "1", "listTime": str(int(spec.pad_start_us // 1000) - 86_400_000 * 60)})
    store.checkpoint()
    store.finalize_days_before(int(spec.end_us // DAY_US) + 3, force=True)
    if manifest_streams:
        store.manifest["g5_streams"] = manifest_streams
        store.save_manifest()
    (root / "instruments-futures.json").write_text(json.dumps({"kind": g5_fetch.MANIFEST_KIND, "snapshot": {"snapshot_date": "2026-06-30", "in_quarantine": False}, "instruments": rows}))


# ----------------------------------------------------------------------------- the strict spec


def test_spec_reader_is_strict_and_refuses_what_it_does_not_implement():
    spec = g5_eval.parse_spec(SPEC)
    assert [e.type for e in spec.events] == ["T01_funding_8h", "T05_us_open", "T11_macro_filter"] and not spec.events[2].evaluate
    assert spec.events[0].observed_min == {"sign": -1, "control_sign": -5} and spec.events[1].observed_min == {"sign": 5}
    assert set(spec.used) <= set(spec.requires) and spec.dates[0] == "2026-06-01" and len(spec.dates) == 24 and spec.pad_start_us == spec.start_us - DAY_US

    def broken(mutate, match: str | None = None):
        document = copy.deepcopy(SPEC)
        mutate(document)
        with pytest.raises(g5_eval.SpecError, match=match):
            g5_eval.parse_spec(document)

    # Unknown keys at every level are refused, never ignored (CTO B1): `requires`, `price_source: last`, `exclude_dates` used to pass silently.
    broken(lambda d: d.update({"verdict_rule": "lenient"}), "unknown key")
    broken(lambda d: d["execution"].update({"fee_stress_multiplier": 1.5}), "cost_stress_multiplier")
    broken(lambda d: d["controls"].update({"minute": 7}), "unknown key")
    broken(lambda d: d["events"][0]["generator"].update({"lookback": 3}), "unknown key")
    broken(lambda d: d["events"][1]["generator"].update({"weekends": True}), "unknown key")
    broken(lambda d: d["events"][0]["sign"].update({"smoothing": 2}), "unknown key")
    broken(lambda d: d["events"][0].update({"stop_bp": 50}), "unknown key")
    broken(lambda d: d["events"][2].update({"sign": {"kind": "constant", "value": 1}}), "unknown key")  # a filter has no sign: it would be ignored
    broken(lambda d: d["events"][1]["sign"].update({"kind": "field_sign", "field": "premium", "at_min": 5}), "premium_mark_index")
    broken(lambda d: d["events"][1].update({"condition": {"field": "mid_return", "op": ">", "value": 0, "from_min": 0, "to_min": 5}}), "price_return")
    # `requires`: an unknown capability, and a capability used without being declared (the version lock of (B) xii).
    broken(lambda d: d["requires"].append("funding_snipe"), "not implemented")
    broken(lambda d: d["requires"].remove("interval_8h_filter"), "without declaring")
    broken(lambda d: d["requires"].remove("n_unit_instant"), "without declaring")
    broken(lambda d: d["requires"].append("price_source_last"), "distinct")
    for key in ("price_source", "unit", "requires", "quality", "economic"):
        broken(lambda d, key=key: d.pop(key), "missing")
    broken(lambda d: d.update({"price_source": "mid"}), "not in")
    broken(lambda d: d.update({"unit": "instrument_instant"}), "not in")
    for key in ("execution_role", "days_per_year", "arm_min", "rest_until_min"):
        broken(lambda d, key=key: d["events"][0].pop(key), "missing")
    broken(lambda d: d["events"][1].update({"execution_role": "taker_taker"}), "not in")
    broken(lambda d: d["events"][1]["generator"].pop("exclude_dates"), "missing")  # (B) vii: the NYSE / CPI / NFP list is mandatory, even empty
    broken(lambda d: d["events"][1]["generator"].update({"exclude_dates": ["2026-13-01"]}), "YYYY-MM-DD")
    # M2: one premium per source; a capture-only field on the history is refused.
    broken(lambda d: d["events"][0]["control_sign"].update({"base": "premium_okx"}), "capture only")
    # The controls of a funding-signed type need a control sign; a control sign elsewhere would break "the same rule at the controls".
    broken(lambda d: d["events"][0].pop("control_sign"), "control_sign is required")
    broken(lambda d: d["events"][1].update({"control_sign": copy.deepcopy(T01["control_sign"])}), "control_sign replaces")

    def funding_sign_on_a_clock_type(d):
        d["events"][1].update({"generator": {"kind": "fixed_time", "times": ["13:30"]}, "sign": copy.deepcopy(T01["sign"]), "control_sign": copy.deepcopy(T01["control_sign"]),
                               "declared_lookahead": copy.deepcopy(T01["declared_lookahead"])})

    broken(funding_sign_on_a_clock_type, "settlement instants")
    # The pre-registered Annexe A carries both a funding condition and a funding sign: each is a look-ahead and must be declared.
    broken(lambda d: d["events"][0].update({"condition": {"field": "funding_rate", "op": "outside_dead_zone", "low": 0.0, "high": 0.0001, "eps": 1e-9, "require_realized": True}}),
           "condition is observed")
    both = {**T01, "condition": {"field": "funding_rate", "op": "outside_dead_zone", "low": 0.0, "high": 0.0001, "eps": 1e-9, "require_realized": True},
            "declared_lookahead": [T01["declared_lookahead"], {"field": "condition", "reason": "same rate as the sign"}]}
    assert g5_eval.parse_spec(doc(events=[both, T05])).events[0].condition["op"] == "outside_dead_zone"
    broken(lambda d: d["events"][0].update({**both, "condition": {**both["condition"], "require_realized": False}}), "only true")
    # M1: a history type is bounded to the D_cov of what it reads.
    broken(lambda d: d["events"][0].pop("restrict_to_coverage"), "restrict_to_coverage")
    broken(lambda d: d["events"][0].update({"restrict_to_coverage": ["funding_hist"]}), "index_1m")
    # Windows and arming.
    broken(lambda d: d["events"][0].update({"arm_min": -1, "window": {"from_min": -1, "to_min": 15}, "declared_lookahead": [{"field": "sign", "reason": "x"}, {"field": "control_sign", "reason": "y"}]}),
           "toxic")
    broken(lambda d: d["events"][1].update({"arm_min": 6}), "armed before")
    broken(lambda d: d["events"][1].update({"rest_until_min": 61}), "rest_until_min")
    broken(lambda d: d["events"][1].update({"window": {"from_min": 5, "to_min": 5}}), "from_min must be")
    broken(lambda d: d["events"][1].update({"exit_until_min": 75}), "Gate 2")  # a capture-only key on the history would be ignored
    broken(lambda d: d["events"].append(copy.deepcopy(d["events"][1])), "duplicate")
    broken(lambda d: [e.update({"primary": False}) for e in d["events"] if e.get("evaluate", True)], "primary")
    broken(lambda d: d["events"][2].update({"primary": True}), "cannot be primary")
    broken(lambda d: d.update({"primary_measure": "sim_net_bp"}), "execution.sim")
    broken(lambda d: d.update({"alpha": 1.5}))
    broken(lambda d: d.update({"min_instants": True}), "integer")  # a boolean is not an integer
    broken(lambda d: d["quality"].update({"exclude_zero_volume": "yes"}), "true or false")
    broken(lambda d: d.update({"events": []}), "empty")
    # Optional blocks declare their capability: eligibility (quant B3), programme family, assumed hours outside the coverage.
    broken(lambda d: d.update({"eligibility": {"days_after_list": 30}}), "without declaring")
    assert spec_of(eligibility={"days_after_list": 30}, requires=SPEC["requires"] + ["eligibility_days_after_list"]).eligibility_days == 30.0
    assert spec_of(programme={"m_max": 7}, requires=SPEC["requires"] + ["programme_family"]).programme_m_max == 7
    # Quarantine: no window may touch [2026-10-14, 2026-11-02); a history window ends at END_EXCLUSIVE at the latest.
    for window in ({"start": "2026-10-01", "end_exclusive": "2026-10-15"}, {"start": "2026-10-20", "end_exclusive": "2026-10-25"}, {"start": "2026-10-31", "end_exclusive": "2026-11-05"}):
        with pytest.raises(SystemExit):
            g5_eval.guard_window(spec_of(window=window))
    assert g5_eval.guard_window(spec_of(window={"start": "2026-03-30", "end_exclusive": "2026-10-14"}))["end_exclusive"] == "2026-10-14T00:00:00Z"


def test_a_field_observed_after_the_decision_is_refused_unless_declared_by_name():
    """CTO B2: the test spec of the first version (condition premium(h−1), order armed at h−15) is refused now."""
    old_style = {"type": "pre", "primary": True, "execution_role": "maker_maker", "days_per_year": 365, "generator": {"kind": "fixed_time", "times": ["08:00"]},
                 "condition": {"field": "premium_mark_index", "at_min": -1, "op": ">", "value": 0.0}, "sign": {"kind": "constant", "value": -1},
                 "window": {"from_min": -15, "to_min": 0}, "arm_min": -15, "rest_until_min": -14, "restrict_to_coverage": ["mark_1m", "index_1m"]}
    requires = SPEC["requires"]
    with pytest.raises(g5_eval.SpecError, match="look-ahead"):
        g5_eval.parse_spec(doc(events=[old_style]))
    declared = {**old_style, "declared_lookahead": {"field": "condition", "reason": "test"}}
    spec = g5_eval.parse_spec(doc(events=[declared], requires=requires))
    assert spec.events[0].declared_lookahead == ({"field": "condition", "reason": "test"},)
    with pytest.raises(g5_eval.SpecError, match="nothing to declare"):  # a declaration with no look-ahead behind it
        g5_eval.parse_spec(doc(events=[{**old_style, "condition": {**old_style["condition"], "at_min": -15}, "declared_lookahead": {"field": "condition", "reason": "x"}}]))
    # A return sign read until h+5 for an order armed at h: refused; armed at h+5 (T05): accepted.
    late_sign = {**T05, "arm_min": 0, "window": {"from_min": 0, "to_min": 60}}
    with pytest.raises(g5_eval.SpecError, match="sign is observed at h\\+5"):
        g5_eval.parse_spec(doc(events=[late_sign]))
    # T01 without its declaration: the realized rate (observed at h−1) and an order armed at h−5.
    with pytest.raises(g5_eval.SpecError, match="look-ahead"):
        g5_eval.parse_spec(doc(events=[{k: v for k, v in T01.items() if k != "declared_lookahead"}, T05]))
    # The control proxy read at h−1 (the prereg §7.2 form) needs its own declaration.
    proxy_late = {**T01, "control_sign": {**T01["control_sign"], "at_min": -1}}
    with pytest.raises(g5_eval.SpecError, match="control_sign is observed"):
        g5_eval.parse_spec(doc(events=[proxy_late, T05]))
    both = {**proxy_late, "declared_lookahead": [T01["declared_lookahead"], {"field": "control_sign", "reason": "P̂(c−1), prereg §7.2"}]}
    assert len(g5_eval.parse_spec(doc(events=[both, T05])).events[0].declared_lookahead) == 2
    # The quant-review alternative (one classifier P̂(h−5) for events and controls, no look-ahead) is expressible without any declaration.
    unified = {"type": "T01_proxy", "primary": True, "execution_role": "maker_maker", "days_per_year": 365, "generator": T01["generator"],
               "sign": {k: v for k, v in T01["control_sign"].items() if k not in ("concordance_min", "concordance_on")}, "window": T01["window"], "arm_min": -5, "rest_until_min": 1,
               "restrict_to_coverage": ["mark_1m", "index_1m"]}
    assert g5_eval.parse_spec(doc(events=[unified])).events[0].declared_lookahead == ()


# ----------------------------------------------------------------------------- loaders


def test_history_loader_reference_price_without_fallback_unconfirmed_realized_and_coverage(tmp_path: Path):
    spec = spec_of(window={"start": "2026-06-01", "end_exclusive": "2026-06-03"})
    arrays = synthetic(spec, unknown_rate_at=(tape.parse_iso_utc("2026-06-01T08:00:00Z"),))
    t_unconf = int(arrays["grid"][100])
    t_missing = int(arrays["grid"][200])
    write_tape(tmp_path, spec, [arrays], unconfirmed_at=(t_unconf,), drop_last_at=(t_missing,))
    series = g5_eval.load_history_series(tmp_path, INST, INDEX, spec)
    k = 500
    assert series.size == 3 * 1440 + 1 and series.price[k] == pytest.approx(arrays["price"][k]) and series.price_code[k] == g5_eval.PRICE_CODE["last"]
    assert series.alt_price[k] == pytest.approx(arrays["mark"][k]) and series.premium["premium_mark_index"][k] == pytest.approx(arrays["premium"][k], abs=1e-12)
    # (i) no fallback: a missing last-price candle leaves the reference price missing although the mark exists; an unconfirmed candle is dropped and counted.
    assert np.isnan(series.price[201]) and series.price_code[201] == 0 and np.isfinite(series.alt_price[201])
    assert np.isnan(series.price[101]) and series.coverage["unconfirmed_dropped"]["candles_1m"] == 1
    # (ii) realized rates only: the settlement without realizedRate is known as an instant, its rate is unknown.
    h_unknown = tape.parse_iso_utc("2026-06-01T08:00:00Z")
    assert h_unknown in series.settlements_all.tolist() and h_unknown in series.funding_unknown_ts.tolist() and h_unknown not in series.funding.ts_us.tolist()
    assert series.coverage["funding_rows_without_realized"] == 1 and series.funding.rate[0] in RATE_CYCLE
    # Coverage from the rows when the manifest has no fetch state; from the manifest's end_reason when it has one (M1).
    assert series.coverage_start_us["funding_hist"] == int(arrays["hs"][0])
    manifest = {"g5_streams": {f"{INST}:funding_hist": {"complete": True, "end_reason": "server_empty_before_start", "coverage_start_us": spec.start_us + DAY_US, "start_us": 0},
                               f"{INST}:candles_1m": {"complete": True, "end_reason": "reached_start", "start_us": 123, "coverage_start_us": 123}}}
    series = g5_eval.load_history_series(tmp_path, INST, INDEX, spec, manifest=manifest)
    assert series.coverage_start_us["funding_hist"] == spec.start_us + DAY_US and series.coverage_start_us["candles_1m"] == 123
    # The quarantine bound holds in the loader too.
    rows = np.zeros(3, dtype=tape.PX_CANDLES_1M_DTYPE)
    rows["ts_us"] = [g5_fetch.END_EXCLUSIVE_US - MINUTE_US, g5_fetch.END_EXCLUSIVE_US, g5_fetch.END_EXCLUSIVE_US + MINUTE_US]
    kept, dropped = g5_eval._drop_after_end(rows)
    assert kept.size == 1 and dropped == 2
    mark_spec = spec_of(window={"start": "2026-06-01", "end_exclusive": "2026-06-03"}, price_source="mark")
    assert g5_eval.load_history_series(tmp_path, INST, INDEX, mark_spec).price[201] == pytest.approx(arrays["mark"][201])


# ----------------------------------------------------------------------------- generators and controls


def test_generators_dst_exclusion_dates_and_the_8h_interval_filter():
    spec = spec_of(window={"start": "2026-03-02", "end_exclusive": "2026-03-14"})
    series = memory_series(spec, synthetic(spec))
    instants, source, diag = g5_eval.generate_instants(spec.events[1].generator, series, spec)  # exclude_dates are June dates: nothing excluded in March
    labels = [tape.iso_utc(int(t))[5:16] for t in instants]
    assert source == "us_open_dst_aware" and labels == ["03-02T14:30", "03-03T14:30", "03-04T14:30", "03-05T14:30", "03-06T14:30",
                                                         "03-09T13:30", "03-10T13:30", "03-11T13:30", "03-12T13:30", "03-13T13:30"]
    us = g5_eval._parse_generator({"kind": "us_open", "exclude_dates": ["2026-03-04"], "skip_dates": ["2026-03-11"]}, "g", set())
    instants, _, diag = g5_eval.generate_instants(us, series, spec)
    assert instants.size == 8 and diag["excluded_dates"] == 1 and diag["skipped_dates"] == 1 and [tape.iso_utc(t)[5:16] for t in diag["skipped_instants"]] == ["03-11T13:30"]
    with pytest.raises(g5_eval.SpecError, match="both excluded"):
        g5_eval._parse_generator({"kind": "us_open", "exclude_dates": ["2026-03-04"], "skip_dates": ["2026-03-04"]}, "g", set())
    fixed = g5_eval._parse_generator({"kind": "fixed_time", "times": ["08:00"], "weekdays": [4], "exclude_dates": ["2026-03-13"]}, "g", set())
    fridays, _, diag = g5_eval.generate_instants(fixed, series, spec)
    assert [tape.iso_utc(int(t))[5:16] for t in fridays] == ["03-06T08:00"] and diag["excluded_dates"] == 1
    # Interval filter: a 4 h settlement inserted at 2026-03-05 12:00 removes 08:00 and 16:00 (gaps 4 h) and itself.
    extra = tape.parse_iso_utc("2026-03-05T12:00:00Z")
    series.settlements_all = np.sort(np.append(series.settlements_all, extra))
    funding, source, diag = g5_eval.generate_instants(spec.events[0].generator, series, spec)
    stamps = {tape.iso_utc(int(t))[5:16] for t in funding}
    assert source == "instrument_settlements" and diag["settlements"] == 12 * 3 + 1 and diag["interval_kept"] == 12 * 3 - 2 and diag["interval_other"] == 3
    assert "03-05T08:00" not in stamps and "03-05T12:00" not in stamps and "03-05T16:00" not in stamps and "03-05T00:00" in stamps
    # Without neighbours (first / last settlement of what was read) the interval is unknown: excluded and counted.
    series.settlements_all = series.settlements_all[(series.settlements_all >= spec.start_us) & (series.settlements_all < spec.end_us)]
    _, _, diag = g5_eval.generate_instants(spec.events[0].generator, series, spec)
    assert diag["interval_unknown"] == 2
    explicit, source, _ = g5_eval.generate_instants(g5_eval._parse_generator({"kind": "explicit", "instants": ["2026-03-05T12:30:00Z", "2026-03-05T12:30:30Z", "2026-04-01T12:30:00Z"]}, "g", set()),
                                                    series, spec)
    assert source == "explicit_list" and [tape.iso_utc(int(t))[5:16] for t in explicit] == ["03-05T12:30"]


def test_controls_same_days_minute_of_the_hour_and_the_union_exclusion():
    spec = spec_of()
    series = memory_series(spec, synthetic(spec))
    t01, t05 = spec.events[0], spec.events[1]
    us_open, _, _ = g5_eval.generate_instants(t05.generator, series, spec)
    macro, _, _ = g5_eval.generate_instants(spec.events[2].generator, series, spec)
    union = np.unique(np.concatenate([series.settlements_all, us_open, macro]))
    days = (us_open // DAY_US)[:5]
    controls, diag = g5_eval.control_instants(series, spec, t05, 1, union, days)
    minutes_of_hour = {int(t // MINUTE_US % 60) for t in controls}
    assert minutes_of_hour == {30} and set((controls // DAY_US).tolist()) <= set(days.tolist())  # (B) iv + quant B2: same days, minute of the event
    assert np.all(np.min(np.abs(controls[:, None] - union[None, :]), axis=1) > 60 * MINUTE_US)  # ≥ 60 min from every settlement, open and macro instant
    assert diag["fixed"] == 5 * 2 and diag["random"] == 5 * 3  # 12:30 is within 60 min of 13:30 every day
    again, _ = g5_eval.control_instants(series, spec, t05, 1, union, days)
    other, _ = g5_eval.control_instants(series, spec, t05, 0, union, days)
    assert again.tolist() == controls.tolist() and other.tolist() != controls.tolist()  # reproducible, type-specific draws
    t01_days = (series.settlements_all[(series.settlements_all >= spec.start_us) & (series.settlements_all < spec.end_us)] // DAY_US)[:6]
    controls, _ = g5_eval.control_instants(series, spec, t01, 0, union, t01_days)
    assert {int(t // MINUTE_US % 60) for t in controls} == {0} and {int(t // HOUR_US % 24) for t in controls} & {0, 8, 16, 7, 9, 15, 17, 23, 1} == set()
    # A settlement without a known rate still excludes its neighbourhood (union = every settlement, all intervals).
    hole = g5_eval.control_instants(series, spec, t01, 0, np.append(union, spec.start_us + 4 * HOUR_US), t01_days[:1])[0]
    assert spec.start_us + 4 * HOUR_US not in hole.tolist()
    # Window exclusion: the whole control window [c + lo ; c + to] stays ≥ exclusion_min from the union.
    wspec = spec_of(controls={**SPEC["controls"], "exclusion_on": "window"}, requires=SPEC["requires"] + ["controls_window_exclusion"])
    wcontrols, _ = g5_eval.control_instants(series, wspec, wspec.events[1], 1, union, days)
    assert wcontrols.size and all(np.min(np.abs(union - (c + 60 * MINUTE_US))) > 60 * MINUTE_US for c in wcontrols.tolist())
    # The union of the study: settlements, assumed hours, every type's instants (the filter included).
    raw = {e.type: g5_eval.generate_instants(e.generator, series, spec)[0] for e in spec.events}
    skipped = g5_eval.generate_instants(t05.generator, series, spec)[2]["skipped_instants"]
    full = g5_eval.exclusion_union(series, raw, skipped)
    assert set(macro.tolist()) <= set(full.tolist()) and set(series.settlements_all.tolist()) <= set(full.tolist()) and set(us_open.tolist()) <= set(full.tolist())
    assert skipped == [tape.parse_iso_utc("2026-06-10T13:30:00Z")] and skipped[0] in full.tolist()  # the open of a skipped CPI session still excludes its neighbourhood


# ----------------------------------------------------------------------------- measures


def test_measure_roles_costs_stress_signs_and_exclusions():
    spec = spec_of(window={"start": "2026-06-01", "end_exclusive": "2026-06-03"})
    arrays = synthetic(spec, sigma=0.0)
    n = arrays["grid"].size
    arrays["price"] = 100.0 * np.exp(np.arange(n) * 1e-4)  # +1 bp per minute, deterministic
    series = memory_series(spec, arrays)
    t01, t05 = spec.events[0], spec.events[1]
    # (vi) costs at the role: maker/maker 2 + 2 fees, 2 × 2 adverse selection, 2 slippage = 10 bp; taker/maker 5 + 2, 1 × 2, 2 = 11 bp; stress × 1.5 on every term.
    assert g5_eval.cost_terms(t01, spec)["total_bp"] == 10.0 and g5_eval.cost_terms(t05, spec)["total_bp"] == 11.0
    assert g5_eval.cost_terms(t01, spec)["stress_total_bp"] == 15.0 and g5_eval.cost_terms(t05, spec)["stress_total_bp"] == pytest.approx(16.5) and g5_eval.cost_terms(t01, spec)["sensitivity_total_bp"] == 13.0
    hs = arrays["hs"][(arrays["hs"] >= spec.start_us) & (arrays["hs"] < spec.end_us)]
    m = g5_eval.measure(series, hs, t01, spec, "event")
    rates = dict(zip(arrays["hs"].tolist(), arrays["rs"].tolist()))
    expected_signs = [1 if rates[h] > 1e-4 else -1 for h in hs.tolist() if rates[h] != 1e-4]
    assert m["counts"]["zero_sign"] == 2 and m["counts"]["kept"] == 4 and m["sign"].tolist() == expected_signs  # (ii) dead zone excluded
    long_i = int(np.argmax(m["sign"] > 0))
    assert m["r_bp"][long_i] == pytest.approx(20.0) and m["funding_bp"][long_i] == pytest.approx(-3.0) and m["net_bp"][long_i] == pytest.approx(20.0 - 10.0 - 3.0, abs=0.05)
    assert m["net_bp_stress"][long_i] == pytest.approx(20.0 - 15.0 - 3.0, abs=0.05) and m["net_bp_as_sensitivity"][long_i] == pytest.approx(20.0 - 13.0 - 3.0, abs=0.05)
    short_i = int(np.argmax(m["sign"] < 0))
    assert m["r_bp"][short_i] == pytest.approx(-20.0) and m["funding_bp"][short_i] == pytest.approx(-2.0)  # shorts pay a negative rate
    # Controls of T01 read the control sign (P̂ at c − 5) and skip the funding condition; the funding sign is never read there.
    c = g5_eval.measure(series, np.array([spec.start_us + 4 * HOUR_US + DAY_US], dtype=np.int64), t01, spec, "control")
    assert c["counts"]["condition_unknown"] == 0 and c["counts"]["sign_unknown"] == 0 and c["funding_bp"].tolist() in ([], [0.0])
    # Taker entry: half-spread paid at entry, earned at the maker exit (history: 0 by spec; here 1 bp).
    hspec = spec_of(window={"start": "2026-06-01", "end_exclusive": "2026-06-03"}, execution={**SPEC["execution"], "half_spread_bp": 1.0})
    h = spec.start_us + 13 * HOUR_US + 30 * MINUTE_US
    mt = g5_eval.measure(series, np.array([h], dtype=np.int64), hspec.events[1], hspec)
    assert mt["sign"].tolist() == [1] and mt["rt_bp"][0] == pytest.approx(55.15, abs=0.05) and mt["net_bp"][0] == pytest.approx(55.15 - 11.0, abs=0.05)  # simple return of e^55bp
    mm = g5_eval.measure(series, np.array([h], dtype=np.int64), dataclasses.replace(hspec.events[1], execution_role="maker_maker"), hspec)
    assert mm["rt_bp"][0] == pytest.approx(55.15 + 2.0, abs=0.05)  # the maker entry earns the half-spread instead of paying it
    # (ii) a settlement without realizedRate supplies no rate: the funding sign is unknown there (counted), never the predicted rate.
    mu = g5_eval.measure(memory_series(spec, synthetic(spec, unknown_rate_at=(int(hs[0]),))), hs, t01, spec)
    assert mu["counts"]["sign_unknown"] == 1 and int(hs[0]) not in mu["instants_us"].tolist()
    # M1: a window that crosses a settlement of unknown rate is excluded and counted (`funding_unknown`): here T05's rule at 07:30 crosses 08:00.
    early = spec.start_us + 7 * HOUR_US + 30 * MINUTE_US
    mf = g5_eval.measure(memory_series(spec, synthetic(spec, unknown_rate_at=(spec.start_us + 8 * HOUR_US,))), np.array([early], dtype=np.int64), t05, spec)
    assert mf["counts"]["funding_unknown"] == 1 and mf["counts"]["kept"] == 0
    known = g5_eval.measure(series, np.array([early], dtype=np.int64), t05, spec)
    assert known["counts"]["kept"] == 1 and known["settlements"][0] == 1 and known["funding_bp"][0] == pytest.approx(-1e4 * rates[spec.start_us + 8 * HOUR_US])
    # Zero volume (quant M3): a candle without trade at the arming, the window ends or the sign ends excludes the observation.
    zspec = spec_of(window={"start": "2026-06-01", "end_exclusive": "2026-06-03"}, quality={**SPEC["quality"], "exclude_zero_volume": True},
                    requires=SPEC["requires"] + ["zero_volume_exclusion"])
    volume = np.full(n, 10.0)
    volume[series.index_of(np.array([h + 5 * MINUTE_US]))[0]] = 0.0
    mz = g5_eval.measure(memory_series(zspec, arrays, volume=volume), np.array([h], dtype=np.int64), zspec.events[1], zspec)
    assert mz["counts"]["zero_volume"] == 1 and mz["counts"]["kept"] == 0
    # Mixed source: ends of one return from two different price sources are excluded and counted, never mixed (B1 (i)).
    mixed = memory_series(spec, arrays)
    mixed.price_code[series.index_of(np.array([h + 60 * MINUTE_US]))[0]] = g5_eval.PRICE_CODE["mark"]
    mx = g5_eval.measure(mixed, np.array([h], dtype=np.int64), t05, spec)
    assert mx["counts"]["mixed_source"] == 1 and mx["counts"]["kept"] == 0
    # A missing price at an end is a missing observation, not a fallback.
    holed = memory_series(spec, arrays)
    holed.price[series.index_of(np.array([h + 60 * MINUTE_US]))[0]] = np.nan
    assert g5_eval.measure(holed, np.array([h], dtype=np.int64), t05, spec)["counts"]["missing_price"] == 1


def test_premium_average_weights_coverage_and_classes():
    spec = spec_of(window={"start": "2026-06-01", "end_exclusive": "2026-06-03"})
    series = memory_series(spec, synthetic(spec))
    block = {"base": "premium_mark_index", "minutes": 4, "min_coverage": 0.75, "upper": 0.0006, "lower": -0.0005}
    series.premium["premium_mark_index"][:] = np.nan
    series.premium["premium_mark_index"][10:14] = [1.0, 2.0, 3.0, 4.0]
    assert g5_eval.premium_wavg_at(series, np.array([13]), block)[0] == pytest.approx((1 * 1 + 2 * 2 + 3 * 3 + 4 * 4) / 10.0)  # weights 1…n, the latest heaviest
    series.premium["premium_mark_index"][11] = np.nan  # 3 of 4 present: 0.75 ≥ min_coverage
    assert g5_eval.premium_wavg_at(series, np.array([13]), block)[0] == pytest.approx((1 + 9 + 16) / 8.0)
    series.premium["premium_mark_index"][12] = np.nan
    assert np.isnan(g5_eval.premium_wavg_at(series, np.array([13]), block)[0]) and np.isnan(g5_eval.premium_wavg_at(series, np.array([2]), block)[0])
    assert g5_eval._classify(np.array([7e-4, 6e-4, 0.0, -5e-4, -6e-4, np.nan]), 6e-4, -5e-4).tolist() == [1, 0, 0, 0, -1, 0]


# ----------------------------------------------------------------------------- the study


def test_a_planted_effect_is_recovered_with_c1_to_c6_at_the_instant_unit():
    spec = spec_of()
    series = {INST: memory_series(spec, synthetic(spec, seed=3, drift_bp=30.0)), INST2: memory_series(spec, synthetic(spec, seed=4, drift_bp=30.0, inst=INST2))}
    study = g5_eval.run_study(series, spec)
    t01 = study["types"]["T01_funding_8h"]
    assert t01["n_instants"] == 24 * 3 * 2 // 3 and t01["n_observations"] == 2 * t01["n_instants"]  # (B) v: n = unique instants, both instruments at each
    assert t01["instruments_per_instant"] == {"min": 2, "median": 2.0, "max": 2, "share_below_5": 1.0}
    assert t01["exclusions"]["event"]["zero_sign"] == 2 * 24 and t01["exclusions"]["event"]["condition_failed"] == 0  # the dead zone of the sign excludes R = I
    net = t01["primary_statistic"]
    assert net["point"] == pytest.approx(30.0 - 2.5, abs=4.0) and net["lb90"] > 15.0 and net["p_one_sided"] == 0.0
    c = t01["criteria"]
    assert all(c[k]["pass"] for k in ("C1", "C2", "C3", "C4", "C5", "C6")) and c["C2"]["event_net_mean"] == pytest.approx(30.0 - 10.0 - 2.5, abs=4.0) and c["C6"]["kappa"] == 1.0
    assert t01["verdict"] == "PASS" and t01["verdict_label"] == "G5_GATE1_PASS" and t01["holm_local_confirmed"] is True and t01["fail_annotation"] is None
    assert t01["kappa"]["observations"] == t01["n_observations"] and t01["proxy_table"]["sensitivity_outside"] == 1.0 and t01["proxy_table"]["false_positive_inside_dead_zone"] == 0.0
    assert t01["declared_lookahead"][0]["field"] == "sign" and t01["observed_min"]["sign"] == -1
    eco = t01["economic"]  # 2 fills per instant (≤ 4), 2 conditioned settlements per day, +17.5 bp: 4 × 100 × 0.00175 × 365 ≈ 255 USD/year ≥ 97 with LB90 > 0
    assert eco["fills"] == 2 * t01["n_instants"] and eco["fills_per_day"] == pytest.approx(4.0) and eco["usd_per_year_point"] == pytest.approx(4 * 100 * eco["net_bp_mean"] / 1e4 * 365)
    assert eco["pursued"] is True and t01["reading"] == "PASS, poursuivi"
    t05 = study["types"]["T05_us_open"]
    assert t05["n_instants"] == 16 and t05["verdict"] == "NOT_INFORMATIVE" and t05["reading"] == "NOT_INFORMATIVE" and t05["costs_bp"]["total_bp"] == 11.0  # 18 weekdays − holiday − CPI day
    # The economic reading of T05 is per session day: the skipped CPI session is a candidate day (17 = 18 weekdays − the holiday), the holiday is not.
    assert t05["economic"]["candidate_days"] == 17 and t05["by_instrument"][INST]["generator"] == {"source": "us_open_dst_aware", "excluded_dates": 1, "skipped_dates": 1}
    assert study["types"]["T11_macro_filter"]["evaluate"] is False and "T11_macro_filter" not in study["holm"]["tests"]
    assert study["holm"]["m"] == 2 and study["holm"]["tests"]["T05_us_open"]["p"] == 1.0 and study["summary"]["holm_local_confirmed"] == ["T01_funding_8h"]
    assert study["summary"]["readings"] == {"T01_funding_8h": "PASS, poursuivi", "T05_us_open": "NOT_INFORMATIVE"}
    # The same effect under a higher S_éco: still a scientific PASS, « non poursuivi ».
    rich = g5_eval.run_study(series, spec_of(economic={**SPEC["economic"], "s_eco_usd_per_year": 1000.0}))
    assert rich["types"]["T01_funding_8h"]["reading"] == "PASS, non poursuivi (sous S_éco)"
    # CTO B3: costs cancel in D, so a large adverse selection keeps C1 but turns the absolute net negative: FAIL, never PASS.
    costly = g5_eval.run_study(series, spec_of(execution={**SPEC["execution"], "adverse_selection_bp_per_leg": 15.0}))
    r = costly["types"]["T01_funding_8h"]
    assert r["criteria"]["C1"]["pass"] and not r["criteria"]["C2"]["pass"] and r["verdict"] == "FAIL" and "C2 fails" in r["verdict_reasons"] and r["reading"] == "FAIL"
    assert r["primary_statistic"]["point"] == pytest.approx(net["point"], abs=1e-9) and r["holm_local_confirmed"] is False


def test_the_unit_is_the_instant_not_the_instrument_instant():
    """Piège 23: an instant with many instruments must not weigh more than an instant with one."""
    parts = [{"instants_us": np.array([0, DAY_US], dtype=np.int64), "inst": "A", "x": np.array([10.0, 0.0])},
             {"instants_us": np.array([0], dtype=np.int64), "inst": "B", "x": np.array([20.0])},
             {"instants_us": np.array([0, 2 * DAY_US], dtype=np.int64), "inst": "C", "x": np.array([30.0, np.nan])}]
    instants, values, counts = g5_eval.instant_means(parts, "x")
    assert instants.tolist() == [0, DAY_US] and values.tolist() == [20.0, 0.0] and counts.tolist() == [3, 1]  # NaN observations do not count
    spec = spec_of(min_instants=60)
    series = {INST: memory_series(spec, synthetic(spec, drift_bp=30.0)), INST2: memory_series(spec, synthetic(spec, seed=9, drift_bp=30.0, inst=INST2))}
    t01 = g5_eval.run_study(series, spec)["types"]["T01_funding_8h"]
    assert t01["n_observations"] == 96 and t01["n_instants"] == 48 and t01["verdict"] == "NOT_INFORMATIVE" and "n=48 < min_instants=60" in t01["verdict_reasons"][0]


def test_inconclusive_paths_kappa_proxy_funding_and_fetch():
    spec = spec_of()
    # C6: a proxy that classifies against the contractual rate (κ = 0) makes T01 INCONCLUSIVE, never PASS or FAIL; Holm counts it p = 1.
    flipped = {INST: memory_series(spec, synthetic(spec, drift_bp=30.0, flip_proxy=True))}
    study = g5_eval.run_study(flipped, spec)
    t01 = study["types"]["T01_funding_8h"]
    assert t01["kappa"]["kappa"] == 0.0 and t01["verdict"] == "INCONCLUSIVE" and any(r.startswith("C6") for r in t01["verdict_reasons"]) and study["holm"]["tests"]["T01_funding_8h"]["p"] == 1.0
    # No proxy at all (mark or index missing): the instrument leaves T01, which becomes INCONCLUSIVE (§4.5); T05 does not need the proxy.
    arrays = synthetic(spec, drift_bp=30.0)
    no_proxy = memory_series(spec, arrays)
    no_proxy.premium["premium_mark_index"][:] = np.nan
    study = g5_eval.run_study({INST: no_proxy}, spec)
    assert study["types"]["T01_funding_8h"]["verdict"] == "INCONCLUSIVE" and study["types"]["T01_funding_8h"]["exclusions"]["instruments"]["no_proxy"] == [INST]
    assert study["types"]["T05_us_open"]["verdict"] == "NOT_INFORMATIVE"
    # Fetch errors on more than max_fetch_error_share of the (instrument, stream) jobs: every type INCONCLUSIVE (§4.5).
    manifest = {"g5_streams": {f"{INST}:{s}": {"complete": s != "mark_1m", "end_reason": "reached_start" if s != "mark_1m" else "malformed_page", "errors": []} for s in tape.HISTORY_LAYOUTS}}
    fetch = g5_eval.fetch_quality(manifest, spec)
    assert fetch["jobs"] == 4 and fetch["jobs_in_error"] == 1 and fetch["error_share"] == 0.25
    study = g5_eval.run_study({INST: memory_series(spec, synthetic(spec, drift_bp=30.0))}, spec, fetch)
    assert {r["verdict"] for r in study["types"].values() if r.get("evaluate")} == {"INCONCLUSIVE"} and study["summary"]["global_device_defects"]
    # Many event windows crossing a settlement of unknown rate: excluded, counted, and past max_funding_unknown_share the type is INCONCLUSIVE (§4.5, M1).
    pre = {"type": "pre_08", "primary": True, "execution_role": "maker_maker", "days_per_year": 365, "generator": {"kind": "fixed_time", "times": ["07:50"]},
           "sign": {"kind": "constant", "value": 1}, "window": {"from_min": -5, "to_min": 15}, "arm_min": -5, "rest_until_min": 1}
    pspec = spec_of(events=[pre])
    days = range(int(pspec.start_us // DAY_US), int(pspec.end_us // DAY_US))
    holes = [d * DAY_US + 8 * HOUR_US for d in days][::2]
    r = g5_eval.run_study({INST: memory_series(pspec, synthetic(pspec, unknown_rate_at=holes))}, pspec)["types"]["pre_08"]
    assert r["exclusions"]["event"]["funding_unknown"] == len(holes) and r["exclusions"]["funding_unknown_share"] == pytest.approx(0.5) and r["verdict"] == "INCONCLUSIVE"
    assert any(reason.startswith("funding:") for reason in r["verdict_reasons"])


def test_coverage_restriction_bounds_events_and_controls_and_assumed_hours_exclude_outside(tmp_path: Path):
    requires = SPEC["requires"]
    spec = spec_of(requires=requires, funding_outside_coverage={"assumed_hours": [0, 8, 16]})
    start = spec.start_us + 10 * DAY_US
    series = {INST: memory_series(spec, synthetic(spec, drift_bp=30.0, funding_start_us=start))}
    study = g5_eval.run_study(series, spec)
    r = study["types"]["T01_funding_8h"]
    hs, rs = settlement_plan(spec)
    expected = sum(1 for h, rate in zip(hs.tolist(), rs.tolist()) if start < h < spec.end_us and rate != 1e-4)  # the first settlement served has no previous gap: interval unknown
    assert r["n_instants"] == expected and r["verdict"] == "NOT_INFORMATIVE" and r["by_instrument"][INST]["generator"]["interval_unknown"] == 1
    s = series[INST]
    kept, counts = g5_eval.restrict(np.array([start - DAY_US, start + DAY_US], dtype=np.int64), s, spec.events[0], spec)
    assert kept.tolist() == [start + DAY_US] and counts["before_coverage_funding_hist"] == 1  # the same bound for the controls
    # T05 keeps its whole window; the assumed settlement hours before the funding coverage enter the control exclusion union (quant M5).
    assert s.assumed_unknown_ts.size and np.all(s.assumed_unknown_ts < start) and {int(t // HOUR_US % 24) for t in s.assumed_unknown_ts.tolist()} == {0, 8, 16}
    union = g5_eval.exclusion_union(s, {e.type: g5_eval.generate_instants(e.generator, s, spec)[0] for e in spec.events})
    t05_controls, _ = g5_eval.control_instants(s, spec, spec.events[1], 1, union, np.arange(int(spec.start_us // DAY_US), int(start // DAY_US)))
    assert t05_controls.size and np.min(np.abs(t05_controls[:, None] - s.assumed_unknown_ts[None, :])) > 60 * MINUTE_US
    assert study["types"]["T05_us_open"]["n_instants"] == 16
    # A history window that ends at END_EXCLUSIVE: the settlement at exactly 2026-10-14 00:00 is never known (m4 edge, counted).
    edge = spec_of(events=[T05, {**T01, "primary": False, "type": "late", "generator": {"kind": "fixed_time", "times": ["23:45"]}, "condition": None,
                                 "sign": {"kind": "constant", "value": 1}, "control_sign": None, "declared_lookahead": [], "window": {"from_min": 0, "to_min": 15}, "arm_min": 0}],
                   window={"start": "2026-10-11", "end_exclusive": "2026-10-14"}, requires=requires, funding_outside_coverage={"assumed_hours": [0, 8, 16]})
    edge_series = memory_series(edge, synthetic(edge))
    m = g5_eval.measure(edge_series, np.array([g5_fetch.END_EXCLUSIVE_US - 15 * MINUTE_US], dtype=np.int64), edge.events[1], edge)
    assert m["counts"]["funding_unknown_end_edge"] == 1 and m["counts"]["kept"] == 0


def test_eligibility_after_listing_applies_to_events_and_controls():
    requires = SPEC["requires"] + ["eligibility_days_after_list"]
    spec = spec_of(eligibility={"days_after_list": 30}, requires=requires)
    series = memory_series(spec, synthetic(spec, drift_bp=30.0))
    series.eligible_from_us = spec.start_us + 12 * DAY_US
    kept, counts = g5_eval.restrict(np.array([spec.start_us + DAY_US, spec.start_us + 13 * DAY_US], dtype=np.int64), series, spec.events[0], spec)
    assert kept.tolist() == [spec.start_us + 13 * DAY_US] and counts["before_eligibility"] == 1
    r = g5_eval.run_study({INST: series}, spec)["types"]["T01_funding_8h"]
    assert r["n_instants"] == 12 * 2 and r["exclusions"]["restriction"]["before_eligibility"] == 12 * 3
    unknown = memory_series(spec, synthetic(spec))
    unknown.eligibility_known = False
    assert g5_eval.restrict(np.array([spec.start_us], dtype=np.int64), unknown, spec.events[0], spec)[1]["eligibility_unknown"] == 1


def test_h0_calibration_no_pass_beyond_alpha_over_1000_seeds():
    """(B) xi: under H0 (random walk, fictitious instants) the one-sided p is calibrated and no verdict is PASS beyond α.
    Costs are set to zero and the threshold to 0 bp so that only the statistics decide (the strictest reading of C1–C5);
    the pre-registered costs and +10 bp threshold then never pass at all."""
    lean = {"type": "H0", "primary": True, "execution_role": "maker_maker", "days_per_year": 365, "generator": {"kind": "fixed_time", "times": ["02:00", "10:00", "18:00"]},
            "sign": {"kind": "constant", "value": 1}, "window": {"from_min": 0, "to_min": 20}, "arm_min": 0, "rest_until_min": 1}
    zero = {"maker_bp": 0.0, "taker_bp": 0.0, "half_spread_bp": 0.0, "adverse_selection_bp_per_leg": 0.0, "slippage_bp": 0.0, "cost_stress_multiplier": 1.0}
    spec = spec_of(events=[lean], execution=zero, threshold_bp=0.0, min_instants=60, min_days=20, window={"start": "2026-05-01", "end_exclusive": "2026-06-05"},
                   bootstrap={"resamples": 200, "seed": 5, "block_days": 1}, requires=BASE_REQUIRES + ["controls_same_days_minute_zero"])
    strict = spec_of(events=[lean], min_instants=60, min_days=20, window={"start": "2026-05-01", "end_exclusive": "2026-06-05"},
                     bootstrap={"resamples": 200, "seed": 5, "block_days": 1}, requires=BASE_REQUIRES + ["controls_same_days_minute_zero"])
    rejected, passes, strict_passes = 0, 0, 0
    seeds = 1000
    for seed in range(seeds):
        series = {INST: memory_series(spec, synthetic(spec, seed=1000 + seed, sigma=3e-4))}
        r = g5_eval.run_study(series, spec)["types"]["H0"]
        p = r["primary_statistic"]["p_one_sided"]
        rejected += int(p is not None and p <= spec.alpha)
        passes += int(r["verdict"] == "PASS")
        # The pre-registered costs (10 bp) and threshold (+10 bp) on the same draw: D is unchanged by the costs, C1 needs D ≥ 10, C2 a positive net.
        d = r["primary_statistic"]
        strict_passes += int(d["point"] is not None and d["point"] >= strict.threshold_bp and d["lb90"] > 0 and r["criteria"]["C2"]["event_net_mean"] - 10.0 > 0)
    tolerance = 3.0 * math.sqrt(spec.alpha * (1 - spec.alpha) / seeds)
    assert rejected / seeds <= spec.alpha + tolerance  # calibrated (a percentile bootstrap may be slightly anti-conservative; within 3 σ)
    assert passes / seeds <= spec.alpha and strict_passes == 0


def test_day_block_bootstrap_and_holm():
    days = np.repeat(np.arange(20), 5)
    constant = g5_eval.day_block_bootstrap(np.full(100, 12.0), days, resamples=100, seed=1)
    assert constant["point"] == 12.0 and constant["lb90"] == pytest.approx(12.0) and constant["ub90"] == pytest.approx(12.0) and constant["p_one_sided"] == 0.0 and constant["blocks"] == 20
    rng = np.random.default_rng(5)
    values = rng.normal(10.0, 30.0, 100)
    noisy = g5_eval.day_block_bootstrap(values, days, resamples=400, seed=2)
    assert noisy["lb90"] < noisy["point"] < noisy["ub90"] and 0.0 <= noisy["p_one_sided"] <= 1.0 and noisy["n"] == 100 and noisy["event_median"] == pytest.approx(float(np.median(values)))
    day_effect = np.repeat(rng.normal(0.0, 40.0, 20), 5)
    clustered = day_effect + rng.normal(0.0, 1.0, 100)
    by_day = g5_eval.day_block_bootstrap(clustered, days, resamples=400, seed=3)
    iid = g5_eval.day_block_bootstrap(clustered, np.arange(100), resamples=400, seed=3)
    assert (by_day["ub90"] - by_day["lb90"]) > 1.5 * (iid["ub90"] - iid["lb90"]) and by_day["blocks"] == 20 and iid["blocks"] == 100
    same = g5_eval.day_block_bootstrap(values, days, values, days, resamples=100, seed=4)
    assert same["point"] == 0.0 and same["lb90"] == pytest.approx(0.0) and same["ub90"] == pytest.approx(0.0) and same["n_control"] == 100
    shifted = g5_eval.day_block_bootstrap(values + 25.0, days, values, days, resamples=100, seed=4)
    assert shifted["point"] == pytest.approx(25.0) and shifted["lb90"] == pytest.approx(25.0) and shifted["p_one_sided"] == 0.0
    two_blocks = g5_eval.day_block_bootstrap(np.array([1.0, 5.0]), np.array([0, 1]), resamples=50, seed=1, block_days=7)
    assert two_blocks["blocks"] == 1 and two_blocks["lb90"] == two_blocks["point"] == 3.0 and two_blocks["p_one_sided"] is None
    assert g5_eval.day_block_bootstrap(np.zeros(0), np.zeros(0, dtype=np.int64))["point"] is None
    assert g5_eval.day_block_bootstrap(np.array([np.nan, 2.0]), np.array([0, 1]), resamples=10, seed=1)["n"] == 1
    result = g5_eval.holm({"a": 0.01, "b": 0.04, "c": 0.5}, 0.10)
    assert result["confirmed"] == ["a", "b"] and result["tests"]["a"]["threshold"] == pytest.approx(0.10 / 3) and result["tests"]["b"]["threshold"] == pytest.approx(0.05)
    stopped = g5_eval.holm({"a": 0.09, "b": 0.06, "c": 0.001}, 0.10)
    assert stopped["confirmed"] == ["c"] and stopped["tests"]["b"]["confirmed"] is False and stopped["tests"]["a"]["confirmed"] is False and stopped["tests"]["a"]["rank"] == 3
    assert g5_eval.holm({"a": 0.04, "b": 0.045, "c": 0.001}, 0.10)["confirmed"] == ["c", "a", "b"]
    missing = g5_eval.holm({"a": 0.001, "b": None, "c": float("nan")}, 0.10)
    assert missing["m"] == 3 and missing["confirmed"] == ["a"] and missing["tests"]["b"]["p"] == 1.0 and missing["tests"]["c"]["p"] == 1.0
    assert g5_eval.holm({}, 0.10) == {"alpha": 0.10, "m": 0, "tests": {}, "confirmed": []}


def test_verdict_order_and_fail_annotation():
    spec = spec_of()
    ok = {name: {"pass": True} for name in ("C1", "C2", "C3", "C4", "C5", "C6")}
    diff = {"point": 15.0, "lb90": 3.0, "ub90": 25.0}
    assert g5_eval.verdict_for(spec, 100, 40, diff, ok, ["C6: κ"])[0] == "INCONCLUSIVE"  # a device defect comes first, whatever the power
    assert g5_eval.verdict_for(spec, 29, 40, diff, ok, [])[0] == "NOT_INFORMATIVE" and g5_eval.verdict_for(spec, 100, 9, diff, ok, [])[0] == "NOT_INFORMATIVE"
    assert g5_eval.verdict_for(spec, 100, 40, {"point": None, "lb90": None, "ub90": None}, ok, [])[0] == "NOT_INFORMATIVE"
    assert g5_eval.verdict_for(spec, 100, 40, diff, ok, [])[0] == "PASS"
    for failing in ("C2", "C3", "C4", "C5"):
        verdict, reasons, annotation = g5_eval.verdict_for(spec, 100, 40, diff, {**ok, failing: {"pass": False}}, [])
        assert verdict == "FAIL" and reasons == [f"{failing} fails"] and annotation == "non détecté"
    verdict, _, annotation = g5_eval.verdict_for(spec, 100, 40, {"point": 2.0, "lb90": -4.0, "ub90": 8.0}, {**ok, "C1": {"pass": False}}, [])
    assert verdict == "FAIL" and annotation == "effet exclu"  # UB90 < +10 bp


# ----------------------------------------------------------------------------- capture (Gate 2)


def test_capture_simulation_maker_and_taker_entries_and_the_funding_grace(tmp_path: Path):
    root = tmp_path / "capture"
    store = tape.TapeStore(root, "values", source={"ws": "synthetic"}, not_before="2026-11-02T00:00:00Z")
    day0 = tape.day_of_date("2026-11-03") * DAY_US
    for s in range(0, 2 * tape.DAY_S, 10):  # a quiet one-tick book every 10 s for two days
        ts = day0 + s * US
        store.append("books5", INST, ts, (ts, ts + 1000, s, [100.0, 99.9, 99.8, 99.7, 99.6], [5.0] * 5, [100.2, 100.3, 100.4, 100.5, 100.6], [5.0] * 5))
        store.append("trades", INST, ts, (ts, ts + 1000, 100.0 if s % 20 == 0 else 100.2, 2.0, 0 if s % 20 == 0 else 1, s, 1))
        if s % 60 == 0:
            store.append("mark", INST, ts, (ts, ts + 1000, 100.1))
        if s % 3600 == 0:
            nxt = ((ts // (8 * HOUR_US)) + 1) * 8 * HOUR_US
            prev = nxt - 8 * HOUR_US
            store.append("funding", INST, ts, (ts, ts + 1, nxt, 0.0001, nxt + 8 * HOUR_US, np.nan, 0.0004, 0.0001, 1, 1, 0.00375, -0.00375, 0.0001))
            store.append("funding", INST, ts + 1, (ts + 1, ts + 2, prev, 0.0001, nxt, np.nan, 0.0004, 0.0001, 1, 1, 0.00375, -0.00375, 0.0001))
    store.checkpoint()
    store.finalize_days_before(tape.day_of_date("2026-11-05"))
    (root / "instruments-futures.json").write_text(json.dumps({"instruments": [{"instId": INST, "tickSz": "0.1", "ctVal": "0.0001", "lotSz": "1", "minSz": "1"}]}))
    sim = {"rule": "touch", "latency_ms": 250.0, "funding_fill_grace_min": 1.0, "stale_s": 10.0}
    maker = {"type": "maker_08", "primary": True, "execution_role": "maker_maker", "days_per_year": 365, "generator": {"kind": "fixed_time", "times": ["08:00"]},
             "sign": {"kind": "constant", "value": 1}, "window": {"from_min": -5, "to_min": 15}, "arm_min": -5, "rest_until_min": 1, "exit_until_min": 60}
    taker = {**maker, "type": "taker_0930", "primary": False, "execution_role": "taker_maker", "generator": {"kind": "fixed_time", "times": ["09:30"]},
             "window": {"from_min": 5, "to_min": 15}, "arm_min": 5, "rest_until_min": 6, "exit_until_min": 30}
    document = doc(source="capture", price_source="mark", window={"start": "2026-11-03", "end_exclusive": "2026-11-05"}, min_instants=1, min_days=1, primary_measure="sim_net_bp",
                   execution={**SPEC["execution"], "sim": sim}, controls={**SPEC["controls"], "hours": [4], "random_per_day": 1}, events=[maker, taker],
                   requires=BASE_REQUIRES + ["controls_same_days_minute_zero", "gate2_lp_sim_roles"])
    spec = g5_eval.parse_spec(document)
    with pytest.raises(g5_eval.SpecError, match="exit_until_min"):
        g5_eval.parse_spec({**document, "events": [{k: v for k, v in maker.items() if k != "exit_until_min"}]})
    series = g5_eval.load_capture_series(root, INST, spec, row={"instId": INST, "tickSz": "0.1", "ctVal": "0.0001", "lotSz": "1", "minSz": "1"})
    assert series.premium["premium_okx"][series.index_of(np.array([day0 + 10 * HOUR_US]))[0]] == pytest.approx(0.0004) and series.inst_spec is not None
    assert series.funding.ts_us.size >= 4 and series.price[series.index_of(np.array([day0 + 10 * HOUR_US]))[0]] == pytest.approx(100.1)
    study = g5_eval.run_study({INST: series}, spec)
    m = study["types"]["maker_08"]
    # Maker entry at the bid 100.0 (armed at h−5, filled by the next seller), passive exit at the ask 100.2 from h+15: 20 bp − 4 bp of fees,
    # and the 08:00 settlement crossed (fill before h): the long pays 1 bp.
    assert m["n_instants"] == 2 and m["lp_sim"]["status_event"]["filled"] == 2 and m["statistics_bp"]["sim_net_bp"]["event"]["event_mean"] == pytest.approx(20.0 - 4.0 - 1.0, abs=0.1)
    t = study["types"]["taker_0930"]
    # Taker entry at the ask 100.2 after the latency (+2 bp slippage), passive exit at the ask from h+15: 0 bp, fees 5 + 2, slippage 2.
    assert t["lp_sim"]["status_event"]["filled"] == 2 and t["statistics_bp"]["sim_net_bp"]["event"]["event_mean"] == pytest.approx(0.0 - 7.0 - 2.0, abs=0.1)
    # The funding grace: a fill within a minute after the settlement crosses it ("may take up to a minute"); later, it does not.
    espec = dataclasses.replace(spec.events[0], arm_min=0, from_min=0, rest_until_min=1)
    late = g5_eval.simulate_clock_scalp(series, np.array([day0 + 8 * HOUR_US], dtype=np.int64), np.array([1], dtype=np.int8), espec, spec)[0]
    assert late["sim_funding_bp"][0] == pytest.approx(-1.0)
    no_grace = g5_eval.parse_spec({**document, "execution": {**document["execution"], "sim": {**sim, "funding_fill_grace_min": 0.0}}})
    later = g5_eval.simulate_clock_scalp(series, np.array([day0 + 8 * HOUR_US], dtype=np.int64), np.array([1], dtype=np.int8), espec, no_grace)[0]
    assert later["sim_funding_bp"][0] == pytest.approx(0.0)
    with pytest.raises(g5_eval.SpecError, match="history only"):
        g5_eval.parse_spec({**document, "events": [{**maker, "restrict_to_coverage": ["funding_hist"]}]})
    with pytest.raises(g5_eval.SpecError, match="history only|capture"):
        g5_eval.parse_spec({**document, "events": [{**maker, "sign": {"kind": "field_sign", "field": "premium_mark_index", "at_min": -5}}]})


# ----------------------------------------------------------------------------- command line


def test_cli_g5_eval_writes_json_and_markdown_and_checks_the_spec_and_the_code(tmp_path: Path, capsys):
    root = tmp_path / "history"
    spec_doc = doc(window={"start": "2026-06-01", "end_exclusive": "2026-06-13"}, min_instants=20, min_days=8)
    spec = g5_eval.parse_spec(spec_doc)
    write_tape(root, spec, [synthetic(spec, drift_bp=30.0)])
    spec_path = tmp_path / "spec.json"
    spec_path.write_text(json.dumps(spec_doc, indent=1, ensure_ascii=False))
    out_json, out_md = tmp_path / "g5.json", tmp_path / "g5.md"
    digest = cli.code_digest()
    assert cli.main(["g5-eval", "--tape", str(root), "--spec", str(spec_path), "--out-json", str(out_json), "--out-md", str(out_md), "--expect-code-digest", digest]) == 0
    printed = json.loads(capsys.readouterr().out)
    document = json.loads(out_json.read_text(), parse_constant=lambda c: pytest.fail(f"non-finite number {c} in the JSON report"))
    assert printed["spec_sha256"] == document["spec"]["sha256"] == g5_fetch.sha256_text(spec_path.read_text()) and printed["code_digest"] == digest == document["code_digest"]
    assert document["evaluator"] == g5_eval.EVALUATOR_VERSION and document["spec"]["requires"] == spec_doc["requires"] and set(document["spec"]["capabilities_used"]) <= set(spec_doc["requires"])
    assert document["types"]["T01_funding_8h"]["verdict"] == "PASS" and document["summary"]["holm_local_confirmed"] == ["T01_funding_8h"] and document["holm"]["m"] == 2
    assert document["coverage"][INST]["price_source"] == "last" and document["coverage"][INST]["index_id"] == INDEX and document["instruments_snapshot"]["snapshot_date"] == "2026-06-30"
    assert document["fetch_quality"]["jobs"] == 0 and "g5_eval.py" in document["code"] and document["spec"]["document"] == spec_doc
    assert document["types"]["T01_funding_8h"]["declared_lookahead"][0]["reason"].startswith("R_h is computed at h-1")
    text = out_md.read_text()
    assert text.startswith("# G5 — étude d'événements `g5-synthetic-test` (history)") and "GATES` §9.7 interdit de promouvoir" in text.split("## Verdicts")[0]
    assert "**G5_GATE1_PASS**" in text and "PASS, poursuivi" in text and "regard en avant déclaré" in text and "## Couverture et qualité" in text and "UNAVAILABLE" in text
    with pytest.raises(SystemExit, match="code digest"):
        cli.main(["g5-eval", "--tape", str(root), "--spec", str(spec_path), "--expect-code-digest", "0" * 64])
    with pytest.raises(SystemExit, match="sha256 mismatch"):
        cli.main(["g5-eval", "--tape", str(root), "--spec", str(spec_path), "--expect-spec-sha256", "deadbeef"])
    assert cli.main(["g5-eval", "--tape", str(root), "--spec", str(spec_path), "--expect-spec-sha256", document["spec"]["sha256"].upper()]) == 0
    bad = tmp_path / "bad.json"
    for broken in ({**spec_doc, "window": {"start": "2026-10-01", "end_exclusive": "2026-10-20"}}, {**spec_doc, "events": []}, {**spec_doc, "requires": spec_doc["requires"] + ["magic"]},
                   {**spec_doc, "surprise": 1}):
        bad.write_text(json.dumps(broken))
        with pytest.raises(SystemExit):
            cli.main(["g5-eval", "--tape", str(root), "--spec", str(bad)])
    counting = tmp_path / "counting"
    tape.TapeStore(counting, "counting")
    with pytest.raises(SystemExit):
        cli.main(["g5-eval", "--tape", str(counting), "--spec", str(spec_path)])
    capture_like = tmp_path / "capture"
    tape.TapeStore(capture_like, "values")
    with pytest.raises(SystemExit, match="not a lp-xperp-1m-history"):
        cli.main(["g5-eval", "--tape", str(capture_like), "--spec", str(spec_path)])
    with pytest.raises(SystemExit):
        cli.main(["g5-eval", "--tape", str(tmp_path / "empty"), "--spec", str(spec_path)])
    with pytest.raises(SystemExit):  # the command line exposes no threshold, sign, window or seed
        cli.build_parser().parse_args(["g5-eval", "--tape", str(root), "--spec", str(spec_path), "--threshold-bp", "5"])
    capsys.readouterr()


def test_annex_a_shaped_spec_parses_once_its_lists_are_filled():
    """The draft Annexe A of LP-G5 in the g5_eval/2 format (what a1 freezes); the `<Annexe C …>` placeholders replaced by test lists."""
    annex = {
        "spec_id": "LP-G5-GATE1-2026", "source": "history", "unit": "instant", "price_source": "last",
        "requires": ["price_source_last", "funding_rate_sign_dead_zone", "interval_8h_filter", "control_sign_premium_wavg", "controls_same_days_minute_zero", "n_unit_instant",
                     "execution_role_costs", "us_open_exclude_dates", "verdict_c2_c6", "days_per_year", "quality_rules", "premium_named_fields", "declared_lookahead",
                     "controls_minute_of_hour", "coverage_restriction", "eligibility_days_after_list", "programme_family"],
        "window": {"start": "2026-04-01", "end_exclusive": "2026-10-14"},
        "alpha": 0.10, "threshold_bp": 10.0, "min_instants": 100, "min_days": 30, "primary_measure": "net_bp",
        "bootstrap": {"resamples": 1000, "seed": 20261102, "block_days": 1},
        "execution": {"half_spread_bp": 0.0, "maker_bp": 2.0, "taker_bp": 5.0, "adverse_selection_bp_per_leg": 2.0, "adverse_selection_sensitivity_bp_per_leg": 3.5, "slippage_bp": 2.0,
                      "cost_stress_multiplier": 1.5},
        "controls": {"hours": [4, 12, 20], "random_per_day": 3, "minute_zero_only": True, "same_days_as_events": True, "exclusion_min": 60, "seed": 20261102},
        "quality": {"max_missing_window_share": 0.20, "max_proxy_missing_share": 0.50, "max_fetch_error_share": 0.10, "max_funding_unknown_share": 0.10, "exclude_zero_volume": False},
        "economic": {"s_eco_usd_per_year": 97.0, "max_fills_per_instant": 4, "order_notional_usd": 100.0},
        "eligibility": {"days_after_list": 30}, "funding_outside_coverage": {"assumed_hours": [0, 8, 16]}, "programme": {"m_max": 7},
        "events": [
            {**T01, "type": "T01_funding_8h",
             "control_sign": {**T01["control_sign"], "at_min": -1},
             "declared_lookahead": [T01["declared_lookahead"], {"field": "control_sign", "reason": "P̂(c−1) mirrors R_h computed at h−1 (LP-G5 §7.2)"}]},
            {**T05, "generator": {"kind": "us_open", "exclude_dates": ["2026-04-03", "2026-05-25", "2026-06-19", "2026-07-03", "2026-09-07"],
                                  "skip_dates": ["2026-04-10", "2026-05-12", "2026-06-10", "2026-07-14", "2026-08-12", "2026-09-11", "2026-05-08", "2026-06-05", "2026-07-02",
                                                 "2026-08-07", "2026-09-04", "2026-10-02"]}},
            {"type": "T11_macro_filter", "evaluate": False, "generator": {"kind": "explicit", "instants": ["2026-04-10T12:30:00Z", "2026-04-29T18:00:00Z"]}},
        ],
    }
    spec = g5_eval.parse_spec(annex)
    assert spec.events[0].restrict_to_coverage == ("funding_hist", "mark_1m", "index_1m") and spec.programme_m_max == 7 and spec.eligibility_days == 30.0
    g5_eval.guard_window(spec)
