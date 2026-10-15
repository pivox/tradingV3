"""H2 Gate 1 module tests, on SYNTHETIC data only (no archive, no network)."""

from __future__ import annotations

import hashlib
import io
import json
import math
import zipfile

import pytest

np = pytest.importorskip("numpy")

from scripts.research_h2 import claim as cl  # noqa: E402
from scripts.research_h2 import data as dt  # noqa: E402
from scripts.research_h2 import events as ev  # noqa: E402
from scripts.research_h2 import outcomes as oc  # noqa: E402
from scripts.research_h2 import protocol as pr  # noqa: E402
from scripts.research_h2 import stats as st  # noqa: E402
from scripts.research_h2 import universe as un  # noqa: E402

H = pr.HOUR_MS
MONTH = "2023-03"
COINS = [f"C{k:02d}-USDT-SWAP" for k in range(23)] + [pr.BTC]


def synthetic_closes(seed=7, shock=None, coins=COINS):
    """Random-walk hourly closes on the real bar grid: one market factor, known betas, idiosyncratic noise."""
    rng = np.random.default_rng(seed)
    hours = dt.n_hours()
    market = rng.standard_normal(hours) * 0.005
    betas = {inst: 0.6 + 0.05 * k for k, inst in enumerate(coins)}
    closes = {}
    for inst in coins:
        logs = np.cumsum(betas[inst] * market + rng.standard_normal(hours) * 0.006)
        closes[inst] = 100.0 * np.exp(logs)
    if shock is not None:
        inst, bar, size = shock
        closes[inst][bar - 3:] *= math.exp(size)  # a coin-only move inside the 24 h before `bar`
    return closes, betas


def universe_doc(coins=COINS, months=(MONTH,)):
    return {"months": {m: {"universe": sorted(coins)} for m in months}}


# ----------------------------------------------------------------------------- bars and closed-bar decisions
def test_hourly_bar_is_the_last_closed_minute_and_needs_54_minutes():
    start = pr.GRID_START_MS // 1000
    ts = np.array([start + 60 * m for m in range(120)], dtype=np.int64)
    close = np.arange(120, dtype=np.float64) + 1.0
    bars, counts = dt.hourly_bars(ts, close, hours=3)
    assert bars[0] == 60.0 and bars[1] == 120.0 and math.isnan(bars[2])
    assert counts.tolist() == [60, 60, 0]
    # The minute opening at the bar close belongs to the next bar: it cannot change bar 0.
    later = dt.hourly_bars(np.append(ts, start + 7200), np.append(close, 999.0), hours=3)[0]
    assert later[0] == 60.0 and later[1] == 120.0
    # 53 minutes: invalid; 54: valid.
    keep = np.r_[np.arange(0, 53), np.arange(60, 114)]
    bars, _ = dt.hourly_bars(ts[keep], close[keep], hours=2)
    assert math.isnan(bars[0]) and bars[1] == close[113]


def test_bar_close_times():
    assert dt.bar_close_ms(0) == pr.GRID_START_MS + H
    assert dt.bar_of_close(pr.DECISION_FROM_MS) == (pr.DECISION_FROM_MS - pr.GRID_START_MS) // H - 1
    with pytest.raises(ValueError):
        dt.bar_of_close(pr.DECISION_FROM_MS + 1)


# ----------------------------------------------------------------------------- β, residual, no lookahead
def _reference(closes, members, inst, bar):
    """Direct OLS on the window [t - 24 h - 60 d, t - 24 h) and the shock window, for one decision."""
    def ret(c, b):
        return math.log(c[b]) - math.log(c[b - 1])

    def market(b):
        values = [ret(closes[m], b) for m in members if m != inst]
        return sum(values) / len(values)

    window = range(bar - 24 - 1439, bar - 24 + 1)
    x = np.array([market(b) for b in window])
    y = np.array([ret(closes[inst], b) for b in window])
    beta = float((x * y).sum() / (x * x).sum())
    resid = y - beta * x
    sigma_e = math.sqrt(24) * float(np.std(resid, ddof=1))
    sigma_m = math.sqrt(24) * float(np.std(x, ddof=1))
    r_i = math.log(closes[inst][bar]) - math.log(closes[inst][bar - 24])
    r_m = sum(market(b) for b in range(bar - 23, bar + 1))
    return beta, sigma_e, sigma_m, r_i, r_m


def test_beta_residual_and_z_match_a_direct_computation_and_recover_the_true_beta():
    closes, betas = synthetic_closes()
    members = sorted(COINS)
    panel = ev.month_panel(closes, members, MONTH)
    inst = "C05-USDT-SWAP"
    k = 200
    bar = int(panel[inst]["bar"][k])
    beta, sigma_e, sigma_m, r_i, r_m = _reference(closes, members, inst, bar)
    cols = panel[inst]
    assert cols["reason"][k] == 0
    assert cols["beta"][k] == pytest.approx(beta, rel=1e-9)
    assert cols["sigma_e"][k] == pytest.approx(sigma_e, rel=1e-7)
    assert cols["sigma_m"][k] == pytest.approx(sigma_m, rel=1e-7)
    assert cols["R_i"][k] == pytest.approx(r_i, rel=1e-12)
    assert cols["R_m"][k] == pytest.approx(r_m, rel=1e-9)
    assert cols["e"][k] == pytest.approx(r_i - beta * r_m, rel=1e-7)
    assert cols["z"][k] == pytest.approx((r_i - beta * r_m) / sigma_e, rel=1e-6)
    # The leave-one-out market of 23 others carries the coin's beta relative to their mean beta.
    others = [betas[m] for m in members if m != inst]
    assert cols["beta"][k] == pytest.approx(betas[inst] / (sum(others) / len(others)), rel=0.08)


def test_no_lookahead_beta_window_ends_at_t_minus_24h_and_later_bars_are_ignored():
    closes, _ = synthetic_closes()
    members = sorted(COINS)
    inst = "C03-USDT-SWAP"
    base = ev.month_panel(closes, members, MONTH)[inst]
    k = 300
    bar = int(base["bar"][k])
    # Bars after t: nothing at t (or before) changes.
    future = {m: c.copy() for m, c in closes.items()}
    for m in members:
        future[m][bar + 1:] *= 1.5
    after = ev.month_panel(future, members, MONTH)[inst]
    for name in ("beta", "sigma_e", "sigma_m", "R_i", "R_m", "z", "z_m"):
        np.testing.assert_array_equal(after[name][: k + 1], base[name][: k + 1])
    # A bar inside the shock window (t - 23 h): β and σ at t unchanged, R_i changes; β at t + 1 h changes.
    shocked = {m: c.copy() for m, c in closes.items()}
    shocked[inst][bar - 23] *= 1.3
    moved = ev.month_panel(shocked, members, MONTH)[inst]
    assert moved["beta"][k] == base["beta"][k] and moved["sigma_e"][k] == base["sigma_e"][k]
    assert moved["beta"][k + 1] != base["beta"][k + 1]


def test_market_needs_twenty_other_members_and_beta_needs_80_percent():
    closes, _ = synthetic_closes()
    few = sorted(COINS)[:20]  # each coin sees 19 others
    panel = ev.month_panel(closes, few, MONTH)
    assert set(np.unique(panel[few[0]]["reason"])) == {2}
    closes2 = {m: c.copy() for m, c in closes.items()}
    inst = "C01-USDT-SWAP"
    d_lo, _ = ev.decision_bars(MONTH)
    closes2[inst][d_lo - 24 - 1439: d_lo - 24 - 1439 + 400] = np.nan  # 400 of 1,440 window hours lost
    panel = ev.month_panel(closes2, sorted(COINS), MONTH)
    assert panel[inst]["reason"][0] == 3


# ----------------------------------------------------------------------------- trigger and refractory
def _table(z, z_m, bars, inst=None, reason=None):
    n = len(z)
    return {"z": np.array(z, dtype=float), "z_m": np.array(z_m, dtype=float), "bar": np.array(bars),
            "inst": np.array(inst if inst is not None else [0] * n), "reason": np.array(reason if reason is not None else [0] * n)}


def test_trigger_thresholds_and_refractory_period():
    table = _table(z=[2.99, 3.0, -3.5, 3.2, 4.0, 3.1, 3.1], z_m=[0, 1.0, 0.2, 1.01, 0, 0, 0], bars=[0, 1, 50, 60, 121, 240, 241],
                   reason=[0, 0, 0, 0, 0, 0, 1])
    events, suppressed = ev.detect(table, 3.0)
    # 2.99 is below k and |z_m| 1.01 is not calm; first crossing at bar 1; 49 h later suppressed; exactly 120 h later
    # allowed (bar 121); 119 h after that suppressed (bar 240); bar 241 is not evaluable.
    assert events == [1, 4]
    assert suppressed == [2, 5]
    events, _ = ev.detect(table, 2.5)
    assert events[0] == 0


def test_refractory_is_per_coin():
    table = _table(z=[3, 3, 3], z_m=[0, 0, 0], bars=[10, 11, 12], inst=[0, 1, 0])
    events, suppressed = ev.detect(table, 3.0)
    assert events == [0, 1] and suppressed == [2]


def test_synthetic_coin_only_shock_is_an_event_with_the_reversal_side():
    d_lo, _ = ev.decision_bars(MONTH)
    bar = d_lo + 400
    closes, _ = synthetic_closes(shock=("C07-USDT-SWAP", bar, -0.25))
    document = ev.compute(closes, universe_doc(), [MONTH])
    # The drop enters the 24 h window at bar - 3; the event is the first hour of that window with a calm market.
    hits = [r for r in document["events"] if r["inst"] == "C07-USDT-SWAP" and bar - 3 <= r["bar"] <= bar + 20]
    assert hits and hits[0]["side"] == "long" and hits[0]["z"] <= -3 and abs(hits[0]["z_m"]) <= 1
    assert hits[0]["decision_ms"] == dt.bar_close_ms(hits[0]["bar"])


# ----------------------------------------------------------------------------- controls
def test_c1_excludes_five_days_around_every_event_and_matches_tercile_and_market_sign():
    rng = np.random.default_rng(3)
    n = 720
    bars = np.arange(1000, 1000 + n)
    table = {"inst": np.zeros(n, dtype=int), "month": np.zeros(n, dtype=int), "bar": bars, "reason": np.zeros(n, dtype=np.int8),
             "sigma_e": rng.uniform(0.01, 0.05, n), "R_m": rng.standard_normal(n)}
    events = [100, 400]
    picked = ev.select_c1(table, events)
    for row, choice in zip(events, picked):
        assert len(choice["rows"]) == pr.C1_PER_EVENT <= choice["eligible"]
        cuts = np.quantile(table["sigma_e"], [1 / 3, 2 / 3])
        for r in choice["rows"]:
            assert all(abs(table["bar"][r] - table["bar"][e]) > 120 for e in events)
            assert np.digitize([table["sigma_e"][r]], cuts)[0] == np.digitize([table["sigma_e"][row]], cuts)[0]
            assert np.sign(table["R_m"][r]) == np.sign(table["R_m"][row])
    assert ev.select_c1(table, events) == picked  # seeded, deterministic


def test_c2_nearest_size_same_month_and_sign_reuse_and_caliper():
    table = {"R_i": np.array([0.10, -0.12, 0.08, 0.20, 0.095, 0.30, 0.02]), "month": np.array([0, 0, 0, 0, 0, 0, 0]),
             "bar": np.array([10, 11, 12, 20, 3, 4, 5]), "inst": np.array([0, 1, 2, 0, 3, 4, 5])}
    pools = {(0, True): np.array([4, 5, 6])}
    events = [0, 2, 3]
    matches = ev.match_c2(table, events, pools, None)
    assert matches[0] == {"row": 4, "reuse": False}  # 0.10 -> 0.095
    assert matches[1] == {"row": 6, "reuse": False}  # 0.08 -> 0.02 (0.095 used)
    assert matches[2] == {"row": 5, "reuse": False}  # 0.20 -> 0.30
    tight = ev.match_c2(table, events, pools, 0.25)
    assert tight[0] == {"row": 4, "reuse": False}
    assert tight[1] == {"row": 4, "reuse": True}  # 0.02 is outside ±25 %; the used 0.095 is reused
    assert tight[2] is None  # 0.30 is 50 % away from 0.20
    assert ev.match_c2(table, [1], pools, None) == [None]  # negative sign: no pool


def test_c2_pool_requires_idiosyncratic_calm_and_market_move():
    table = {"reason": np.array([0, 0, 0, 1]), "z": np.array([0.5, 1.0, 0.2, 0.1]), "z_m": np.array([2.0, 3.0, 1.9, 2.5]),
             "inst": np.array([0, 0, 1, 1]), "bar": np.array([1, 2, 3, 4]), "month": np.zeros(4, dtype=int), "R_i": np.array([0.1, 0.2, 0.3, 0.4])}
    assert {k: v.tolist() for k, v in ev.c2_pool(table).items()} == {(0, True): [0]}


def test_caliper_rule_is_the_simulation_choice():
    assert pr.C2_CALIPER_NAME == "none" and pr.caliper() is None
    assert pr.CALIPER_RULE_SHA256 == "3753964e537cf5ebda76f425e4b3e5b0651807674b33b4f6e9d1a1aaec5ce372"


# ----------------------------------------------------------------------------- latency and outcome prices
def test_entry_is_the_close_at_t_plus_5_min_within_15_min_and_exit_has_a_bounded_delay():
    t = pr.DECISION_FROM_MS
    opens = np.arange(t // 1000 - 3600, t // 1000 + 4 * 86_400, 60)
    close = 100.0 + np.arange(opens.size) * 0.01
    prices = dt.Prices(opens, close)
    out = dt.entry_exit(prices, np.array([t]), 24, truncate=True)
    k = np.flatnonzero(opens == t // 1000 + 240)[0]  # the candle opening at t + 4 min closes at t + 5 min
    assert out["entry"][0] == close[k] and out["entry_ms"][0] == t + 5 * 60_000
    j = np.flatnonzero(opens == t // 1000 + 300 + 86_400 - 60)[0]
    assert out["exit"][0] == close[j] and not out["truncated"][0]
    # Missing minutes: the next close within 15 min is used; after 15 min there is no entry.
    gap = (opens < t // 1000 + 240) | (opens >= t // 1000 + 240 + 10 * 60)
    out = dt.entry_exit(dt.Prices(opens[gap], close[gap]), np.array([t]), 24, truncate=True)
    assert out["entry_ms"][0] == t + 15 * 60_000
    gap = (opens < t // 1000 + 240) | (opens >= t // 1000 + 240 + 16 * 60)
    out = dt.entry_exit(dt.Prices(opens[gap], close[gap]), np.array([t]), 24, truncate=True)
    assert math.isnan(out["ret"][0])


def test_exit_truncates_at_the_last_close_only_for_the_coin_itself():
    t = pr.DECISION_FROM_MS
    opens = np.arange(t // 1000, t // 1000 + 86_400 // 2, 60)  # delisted 12 h after t
    close = np.linspace(100.0, 90.0, opens.size)
    prices = dt.Prices(opens, close)
    own = dt.entry_exit(prices, np.array([t]), 24, truncate=True)
    assert own["truncated"][0] and own["exit"][0] == close[-1]
    market = dt.entry_exit(prices, np.array([t]), 24, truncate=False)
    assert math.isnan(market["ret"][0])


# ----------------------------------------------------------------------------- statistics and gates
def _observations(n=400, mean=0.006, c2_shift=-0.002, sd=0.02, seed=1, months=40):
    rng = np.random.default_rng(seed)
    out = []
    for k in range(n):
        month = f"{2023 + (k % months) // 12}-{(k % months) % 12 + 1:02d}"
        y = mean + rng.standard_normal() * sd
        out.append({"month": month, "half": ev.half_of(month), "base": f"B{k % 20}", "y": y, "y_raw": y,
                    "c1": [float(v) for v in rng.standard_normal(5) * sd], "c2": y + c2_shift + (0.1 if k % 2 else -0.1)})
    return out


def test_block_ci_level_parameter_and_determinism():
    obs = _observations()
    months = [o["month"] for o in obs]
    values = [o["y"] for o in obs]
    ci95 = st.block_ci(months, values, 0.95, 2000)
    ci90 = st.block_ci(months, values, 0.90, 2000)
    assert ci95[0] < ci90[0] < ci90[1] < ci95[1]
    assert st.block_ci(months, values, 0.95, 2000) == ci95


def test_amended_g4_is_the_point_estimate_and_a_g4_only_fail_is_flagged():
    passing = st.evaluate(_observations(c2_shift=-0.002), draws=500)
    assert passing["gates"]["G4"] and passing["s2"] > 0
    assert passing["ci_s2"][0] < 0 and not passing["g4_ci_lower_bound_positive"]  # the CI is published, not decisive
    assert passing["verdict"] == "PASS"
    failing = st.evaluate(_observations(c2_shift=+0.004), draws=500)
    assert failing["s2"] < 0 and not failing["gates"]["G4"]
    assert failing["verdict"] == "FAIL" and failing["failed_gates"] == ["G4"] and failing["fail_due_to_G4_alone"]


def test_g1_insufficient_evidence_and_c2_80_percent_blocker():
    few = st.evaluate(_observations(n=150), draws=200)
    assert few["verdict"] == "INSUFFICIENT_EVIDENCE"
    obs = _observations()
    for k, o in enumerate(obs):
        if k % 4 == 0 or k % 7 == 0:
            o["c2"] = None  # about 64 % matched
    blocked = st.evaluate(obs, draws=200)
    assert blocked["c2_match_rate"] < 0.8 and blocked["verdict"] == "STATISTICAL_BLOCKER"


def test_g6_half_years_and_g7_robustness():
    obs = _observations(mean=0.0, sd=0.001)
    obs[0]["y"] = 10.0  # one outlier carries the mean
    result = st.evaluate(obs, draws=200)
    assert result["mean_y"] > 0 and not result["gates"]["G7"]
    assert ev.half_of("2026-09") == 6 and ev.half_of("2023-07") == 1


# ----------------------------------------------------------------------------- module order, claims, code digest
def test_outcomes_refuse_without_the_event_hash(tmp_path):
    document = {"events": []}
    digest = ev.write(document, tmp_path)
    with pytest.raises(oc.OrderError):
        oc.read_events(tmp_path / ev.EVENTS_FILE, "")
    with pytest.raises(oc.OrderError):
        oc.read_events(tmp_path / ev.EVENTS_FILE, "0" * 64)
    assert oc.read_events(tmp_path / ev.EVENTS_FILE, digest) == document
    with pytest.raises(FileExistsError):
        ev.write(document, tmp_path)  # the event file is written once


def _frozen_prereg(path, code=None, body_extra=""):
    text = f"# H2\n\nbody{body_extra}\n\n{pr.FREEZE_MARKER}\n\n"
    body = hashlib.sha256(text.encode()).hexdigest()
    path.write_text(text + f"FROZEN 2026-10-16T00:00:00Z body sha256 {body} code sha256 {code or cl.code_digest()}\n")
    return body


def test_claims_are_single_use_and_pin_the_code(tmp_path, monkeypatch):
    prereg = tmp_path / "PRE.md"
    body = _frozen_prereg(prereg)
    out = tmp_path / "run"
    with pytest.raises(cl.ClaimError):
        cl.claim(out, "events", prereg, body, owner_go="", inputs={})
    cl.claim(out, "events", prereg, body, owner_go="owner 2026-10-16", inputs={})
    with pytest.raises(cl.ClaimError):
        cl.claim(out, "events", prereg, body, owner_go="owner", inputs={})
    with pytest.raises(cl.ClaimError):
        cl.claim(out, "outcomes", prereg, body, owner_go="owner", inputs={})  # events not complete
    cl.consume(out, "events")
    with pytest.raises(cl.ClaimError):
        cl.consume(out, "events")  # a second read is refused
    cl.abort(out, "events", "guard")
    cl.consume(out, "events", resume=True)  # one resumption, nothing written yet
    cl.abort(out, "events", "guard")
    with pytest.raises(cl.ClaimError):
        cl.consume(out, "events", resume=True)
    _ = ev.write({"events": []}, out)
    cl.finish(out, "events")
    assert cl.events_sha256(out) == hashlib.sha256((out / ev.EVENTS_FILE).read_bytes()).hexdigest()
    cl.claim(out, "outcomes", prereg, body, owner_go="owner", inputs={})
    cl.consume(out, "outcomes")
    with pytest.raises(cl.ClaimError):
        cl.consume(out, "outcomes", resume=True)  # no resumption for outcomes


def test_claim_refuses_unfrozen_protocol_wrong_hash_and_other_code(tmp_path, monkeypatch):
    prereg = tmp_path / "PRE.md"
    prereg.write_text(f"# H2\n\nREADY_TO_FREEZE\n\n{pr.FREEZE_MARKER}\n\n")
    body = cl.body_sha256(prereg)
    with pytest.raises(cl.ClaimError):
        cl.claim(tmp_path / "a", "events", prereg, body, owner_go="owner", inputs={})
    body = _frozen_prereg(prereg, code="f" * 64)
    with pytest.raises(cl.ClaimError):
        cl.claim(tmp_path / "b", "events", prereg, body, owner_go="owner", inputs={})
    body = _frozen_prereg(prereg)
    with pytest.raises(cl.ClaimError):
        cl.claim(tmp_path / "c", "events", prereg, "0" * 64, owner_go="owner", inputs={})
    monkeypatch.setattr(pr, "G4_RULE", None)
    with pytest.raises(cl.ClaimError):
        cl.claim(tmp_path / "d", "events", prereg, body, owner_go="owner", inputs={})


def test_code_digest_covers_the_package_and_the_reused_modules():
    identity = cl.code_identity()
    assert "research_h2/events.py" in identity and "research_swing2/okx.py" in identity and "research_swing2/okx_universe.py" in identity
    assert cl.code_digest(identity) == cl.code_digest()
    changed = dict(identity, **{"research_h2/events.py": "0" * 64})
    assert cl.code_digest(changed) != cl.code_digest(identity)


# ----------------------------------------------------------------------------- determinism and end to end
def test_event_document_is_deterministic():
    d_lo, _ = ev.decision_bars(MONTH)
    closes, _ = synthetic_closes(shock=("C07-USDT-SWAP", d_lo + 400, -0.25))
    first = ev.compute(closes, universe_doc(), [MONTH])
    second = ev.compute(closes, universe_doc(), [MONTH])
    assert hashlib.sha256(ev.canonical(first)).hexdigest() == hashlib.sha256(ev.canonical(second)).hexdigest()
    assert first["sensitivities"]["btc_market"] is not None
    for record in first["events"]:
        assert pr.DECISION_FROM_MS <= record["decision_ms"] < pr.DECISION_TO_MS
        if record["c2"] is not None:
            assert record["c2"]["month"] == record["month"] and (record["c2"]["R_i"] > 0) == (record["R_i"] > 0)
            assert abs(record["c2"]["z"]) < 1 <= 2 <= abs(record["c2"]["z_m"])
        assert all(abs(b - record["bar"]) > pr.C1_EXCLUSION_H for b in record["c1"]["bars"])


def test_outcome_pipeline_on_synthetic_minutes(tmp_path):
    """Hourly synthetic closes -> events; then minutes built from the same path -> outcomes and the report."""
    d_lo, _ = ev.decision_bars(MONTH)
    closes, _ = synthetic_closes(shock=("C07-USDT-SWAP", d_lo + 400, -0.25))
    document = ev.compute(closes, universe_doc(), [MONTH])
    digest = ev.write(document, tmp_path)
    loaded = oc.read_events(tmp_path / ev.EVENTS_FILE, digest)

    def minutes(inst):
        bars = closes[inst]
        lo, hi = d_lo - 30, d_lo + 31 * 24 + 6 * 24
        opens = (pr.GRID_START_MS // 1000 + np.arange(lo * 60, hi * 60) * 60).astype(np.int64)
        values = np.repeat(bars[lo:hi], 60)
        return {"ts_s": opens, "close": values}

    measured = oc.measure(loaded, minutes)
    result = oc.outcomes(loaded, measured)
    event = next(r for r in result["events"] if r["inst"] == "C07-USDT-SWAP")
    cell = event["h"]["72"]
    assert cell["y"] is not None and cell["market_members"] == len(COINS) - 1
    report = oc.evaluate(result, draws=200)
    assert report["verdict"] in ("INSUFFICIENT_EVIDENCE", "STATISTICAL_BLOCKER", "FAIL", "PASS")
    assert set(report["sides"]) == {"long", "short"} and report["sensitivities"]["k2.5"]["verdict"] == "REPORTED_ONLY"


# ----------------------------------------------------------------------------- universe and archive reading
def test_universe_rule_volume_warmup_and_days():
    start = un._month_start_ms("2023-03") // pr.DAY_MS
    prev = un._month_start_ms("2023-02") // pr.DAY_MS
    volumes, first = {}, {}
    for k in range(35):
        inst = f"X{k:02d}-USDT-SWAP"
        volumes[inst] = {str(d): [1000.0 + k, 1440, 0] for d in range(prev, start)}
        first[inst] = start - 100
    first["X34-USDT-SWAP"] = start - 59  # less than 60 days of candles
    volumes["X33-USDT-SWAP"] = {str(d): [1e9, 600, 0] for d in range(prev, start)}  # thin days do not count
    volumes["USDC-USDT-SWAP"] = {str(d): [1e12, 1440, 0] for d in range(prev, start)}
    first["USDC-USDT-SWAP"] = start - 100
    volumes["BTC-USDT-SWAP"] = {str(d): [1e10, 1440, 0] for d in range(prev, start)}
    first["BTC-USDT-SWAP"] = start - 100
    universe = un.build(first, volumes, ["2023-03"])["months"]["2023-03"]["universe"]
    assert "BTC-USDT-SWAP" in universe and "USDC-USDT-SWAP" not in universe
    assert "X34-USDT-SWAP" not in universe and "X33-USDT-SWAP" not in universe
    # 34 eligible (X00-X32 and BTC): BTC and the 29 largest X04-X32 enter.
    assert len(universe) == 30 and "X04-USDT-SWAP" in universe and "X03-USDT-SWAP" not in universe


def test_daily_quote_volume_matches_the_swing_v2_rule(tmp_path, monkeypatch):
    from scripts.research_swing2 import okx_universe as ou

    inst = "AAA-USDT-SWAP"
    ts = np.arange(1_700_000_000 // 60 * 60, 1_700_000_000 // 60 * 60 + 3 * 86_400, 60, dtype=np.int64)
    rng = np.random.default_rng(5)
    arrays = {"ts_s": ts, "open": np.ones(ts.size), "high": np.ones(ts.size), "low": np.ones(ts.size), "close": rng.uniform(1, 2, ts.size),
              "volume": rng.uniform(10, 20, ts.size), "base_volume": rng.uniform(5, 10, ts.size), "contracts": rng.uniform(1, 3, ts.size)}
    arrays["volume"][::7] = np.nan
    arrays["base_volume"][::14] = np.nan
    (tmp_path / "klines" / inst).mkdir(parents=True)
    np.savez_compressed(tmp_path / "klines" / inst / f"{inst}-1m-2023-11.npz", **arrays)
    monkeypatch.setattr(ou, "_get", lambda path: {"data": [{"instId": inst, "ctVal": "0.5"}]})
    reference = ou.volumes(tmp_path, tmp_path / "v.json")["days"][inst]
    mine, _ = dt.daily_quote_volume({k: arrays[k] for k in dt.COLUMNS}, ct_val=0.5)
    assert mine.keys() == reference.keys()
    for day in mine:
        assert mine[day][1:] == reference[day][1:] and mine[day][0] == pytest.approx(reference[day][0])


def _zip(rows):
    header = "instrument_name,open,high,low,close,vol,vol_ccy,vol_quote,open_time,confirm\n"
    blob = io.BytesIO()
    with zipfile.ZipFile(blob, "w") as archive:
        archive.writestr("x.csv", header + "".join(rows))
    return blob.getvalue()


def test_archive_reading_skips_later_periods_and_cuts_at_the_data_end(tmp_path):
    inst = "BTC-USDT-SWAP"
    folder = tmp_path / "h2" / "zips" / inst
    folder.mkdir(parents=True)
    end = pr.DATA_END_MS
    rows = [f"{inst},1,1,1,{v},1,1,10,{end - 120_000 + 60_000 * k},1\n" for k, v in enumerate((5.0, 6.0, 7.0))]
    (folder / f"{inst}-candlesticks-2026-10-06.zip").write_bytes(_zip(rows))
    (folder / f"{inst}-candlesticks-2026-10-07.zip").write_bytes(_zip([f"{inst},1,1,1,9,1,1,10,{end + 86_400_000},1\n"]))
    sources = dt.Sources(tmp_path / "h2", tmp_path / "swing")
    assert [p.name for p in sources.files(inst)] == [f"{inst}-candlesticks-2026-10-06.zip"]
    minutes = dt.load_minutes(sources, inst)
    assert minutes["close"].tolist() == [5.0, 6.0]  # the candle opening at the data end is dropped
    assert sources.programme() == [inst] and sources.swing() == []


def test_secondary_universe_is_the_frozen_xperp_list():
    assert len(pr.XPERP_BASES) == 160
    assert un.secondary("SOL-USDT-SWAP") and not un.secondary("LUNA-USDT-SWAP")
    assert pr.INPUT_SHA256["eea-futures.json"] == "732044a8a7ce9dd31f1025f1a501c72ee0b268ef87c30d229e444beac9c44f8d"
