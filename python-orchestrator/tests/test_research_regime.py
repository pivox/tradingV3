"""Tests of regime-r2: annexes, segments, universe at date, detectors without look-ahead, comparators, costs."""

from __future__ import annotations

from datetime import date, timedelta

import pytest

np = pytest.importorskip("numpy")

from scripts.research_regime import annexes, data, detectors as det, evaluate as ev  # noqa: E402

START = date(2019, 1, 1)


def _series(n: int, start: date = START, seed: int = 0, drift: float = 0.0) -> dict:
    rng = np.random.default_rng(seed)
    close = 100 * np.exp(np.cumsum(drift + 0.03 * rng.standard_normal(n)))
    opn = np.concatenate([[100.0], close[:-1]])
    d0 = data.day_of(start)
    return {"day": np.arange(d0, d0 + n, dtype=np.int64), "open": opn, "high": np.maximum(opn, close) * 1.01,
            "low": np.minimum(opn, close) * 0.99, "close": close, "qvol": rng.uniform(1e6, 2e6, n)}


def _funding(n: int, start: date = START, seed: int = 1) -> dict:
    rng = np.random.default_rng(seed)
    d0 = data.day_of(start)
    ts = np.array([(d0 + i) * 86_400_000 + h * 3_600_000 for i in range(n) for h in (0, 8, 16)], dtype=np.int64)
    return {"ts_ms": ts, "rate": 1e-4 + 1e-4 * rng.standard_normal(len(ts))}


def _market(n: int = 900, k: int = 25) -> data.Market:
    spot = {f"S{i:02d}USDT": _series(n, seed=i, drift=0.0005 * (i % 3 - 1)) for i in range(k)}
    spot["BTCUSDT"] = _series(n, seed=99)
    perp = {s: v for s, v in spot.items()}
    fund = {s: _funding(n, seed=i) for i, s in enumerate(spot)}
    return data.build_market(spot, perp, fund, START, START + timedelta(days=n - 1), set(), [])


# ---------------------------------------------------------------------------------------------- annexes

def test_frozen_files_and_annexes_parse():
    checked = annexes.verify_frozen()
    assert set(checked) == {"PREREGISTRATION-regime-r2.md", "ANNEX-A-exclusions.md", "ANNEX-B-symbol-identity.md",
                            "DIFF-regime-r1-r2.diff"}
    a = annexes.annex_a_symbols()
    assert {"USDCUSDT", "WBTCUSDT", "NVDABUSDT", "BTCUPUSDT", "PAXGUSDT"} <= a
    assert not {"BTCUSDT", "BBUSDT", "JUPUSDT", "SYRUPUSDT", "STXUSDT", "QNTUSDT"} & a
    b = {(e.symbol, e.start, e.end) for e in annexes.annex_b_exclusions()}
    assert ("LUNAUSDT", date(2022, 6, 1), None) in b
    assert ("SUNUSDT", None, None) in b
    assert annexes.excluded_on("LUNAUSDT", date(2022, 6, 1), a, annexes.annex_b_exclusions())
    assert not annexes.excluded_on("LUNAUSDT", date(2022, 5, 12), a, annexes.annex_b_exclusions())


# ---------------------------------------------------------------------------------------------- segments and identity

def test_gap_over_seven_days_splits_but_extreme_crash_does_not():
    s = _series(400)
    s["close"][200:] *= 0.001  # a real -99.9 % collapse stays inside the asset
    s["open"][201:] *= 0.001
    keep = np.ones(400, dtype=bool)
    keep[300:308] = False  # 8 missing days -> new segment
    g = {k: v[keep] for k, v in s.items()}
    m = data.build_market({"XUSDT": g}, {}, {}, START, START + timedelta(days=399), set(), [])
    assert m.asset_ids == ["XUSDT#0", "XUSDT#1"]
    assert np.isfinite(m.close[0, 199]) and np.isfinite(m.close[0, 201])  # crash kept in segment 0
    keep7 = np.ones(400, dtype=bool)
    keep7[300:307] = False  # 7 missing days -> same segment, days stay NaN
    m7 = data.build_market({"XUSDT": {k: v[keep7] for k, v in s.items()}}, {}, {}, START,
                           START + timedelta(days=399), set(), [])
    assert m7.asset_ids == ["XUSDT#0"] and np.isnan(m7.close[0, 303])


def test_annex_b_period_is_masked():
    s = _series(200, start=date(2022, 5, 1))
    rows = [annexes.Exclusion("LUNAUSDT", date(2022, 6, 1), None)]
    m = data.build_market({"LUNAUSDT": s}, {}, {}, date(2022, 5, 1), date(2022, 11, 16), set(), rows)
    assert m.asset_ids == ["LUNAUSDT#0"]
    assert np.isfinite(m.close[0, m.index(date(2022, 5, 31))]) and np.isnan(m.close[0, m.index(date(2022, 6, 1))])


# ---------------------------------------------------------------------------------------------- universe

def test_universe_uses_only_past_volume_and_history():
    m = _market(500, 30)
    u = data.universe(m, 20)
    ms = m.index(date(2019, 10, 1))
    before = u[:, ms].copy()
    m.qvol[:, ms:] *= np.random.default_rng(5).uniform(0, 100, m.qvol[:, ms:].shape)
    assert (data.universe(m, 20)[:, ms] == before).all()
    assert not u[:, :m.index(date(2019, 7, 20))].any()  # fewer than 200 days of history before
    assert u[:, ms].sum() == 20


# ---------------------------------------------------------------------------------------------- detectors: no look-ahead

@pytest.mark.parametrize("t", [450, 600, 799])
def test_detectors_ignore_the_future(t):
    m = _market(820, 25)
    u50 = data.universe(m, 50)
    rows = np.arange(len(m.asset_ids))
    d2 = det.d2_momentum(m)
    base = {"d1": det.d1_ma200(m), "d2": d2, "d3": det.d3_breadth(m, u50)[0], "d4": det.d4_funding(m, rows),
            "d5": det.d5_volatility(m, d2, rows), "trend": det.simple_trend(m)}
    rng = np.random.default_rng(t)
    m.close[:, t:] *= rng.uniform(0.2, 5.0, m.close[:, t:].shape)  # day t and later change
    m.fund_sum[:, t:] = rng.normal(0, 1e-2, m.fund_sum[:, t:].shape)
    d2b = det.d2_momentum(m)
    after = {"d1": det.d1_ma200(m), "d2": d2b, "d3": det.d3_breadth(m, u50)[0], "d4": det.d4_funding(m, rows),
             "d5": det.d5_volatility(m, d2b, rows), "trend": det.simple_trend(m)}
    for k in base:  # decisions of days <= t use closes up to t-1 only
        np.testing.assert_array_equal(base[k][:, :t + 1], after[k][:, :t + 1], err_msg=k)


def test_warm_up_first_eligible_days():
    m = _market(820, 3)
    rows = np.arange(len(m.asset_ids))
    d1, d2 = det.d1_ma200(m), det.d2_momentum(m)
    assert np.isnan(d1[:, :200]).all() and np.isfinite(d1[:, 200]).all()
    assert np.isnan(d2[:, :85]).all() and np.isfinite(d2[:, 85]).all()
    d5 = det.d5_volatility(m, d2, rows)
    assert np.isnan(d5[:, :396]).all() and np.isfinite(d5[:, 396]).all()  # 31 closes + 365 values
    d4 = det.d4_funding(m, rows)
    assert np.isnan(d4[:, :372]).all() and np.isfinite(d4[:, 372]).all()  # 7 days + 365 values


def test_d6_and_three_states():
    d1 = np.array([[1.0, -1.0, 0.0, np.nan, 1.0]])
    d3 = np.array([[1.0, -1.0, 1.0, 1.0, -1.0]])
    np.testing.assert_array_equal(det.d6_combo(d1, d3), [[1.0, -1.0, 0.0, np.nan, 0.0]])


# ---------------------------------------------------------------------------------------------- returns and comparators

def test_forward_return_needs_every_day():
    m = _market(60, 1)
    r = ev.forward_return(m, 14)
    assert r[0, 3] == pytest.approx(m.close[0, 17] / m.open[0, 3] - 1)
    m.close[0, 10] = np.nan
    r = ev.forward_return(m, 14)
    assert np.isnan(r[0, 0]) and np.isnan(r[0, 10]) and np.isfinite(r[0, 11])
    assert np.isnan(r[0, 46:]).all()


def test_markov_control_matches_frequency_and_persistence():
    rng = np.random.default_rng(0)
    states = np.repeat(rng.choice([1.0, -1.0, 0.0], size=200, p=[0.5, 0.2, 0.3]), 9)
    pi, q = ev.chain_params(states)
    draws = ev.sample_chains(pi, q, 20_000, 1, np.random.default_rng(1))[0]
    for v, p in zip([1.0, -1.0, 0.0], pi):
        assert (draws == v).mean() == pytest.approx(p, abs=0.03)
    runs = 1 + (draws[1:] != draws[:-1]).sum()
    obs_runs = 1 + (states[1:] != states[:-1]).sum()
    assert len(draws) / runs == pytest.approx(len(states) / obs_runs, rel=0.15)


def test_oracle_beats_random_and_bootstrap_ci():
    rng = np.random.default_rng(3)
    ret = rng.normal(0, 0.05, (10, 400))
    oracle = np.sign(ret)
    elig = np.ones_like(ret, dtype=bool)
    la = ev.layer_a(oracle, ret, elig, 200, rng)
    assert la.score > 0.03 and (la.rand_scores < la.score).all()
    lo, hi = ev.block_bootstrap_ci(la.daily - la.rand_daily, 2000, np.random.default_rng(4))
    assert 0 < lo < hi


def test_block_bootstrap_is_seeded_and_centered():
    x = np.random.default_rng(9).normal(0.01, 0.02, 600)
    a = ev.block_bootstrap_ci(x, 3000, np.random.default_rng(1))
    b = ev.block_bootstrap_ci(x, 3000, np.random.default_rng(1))
    assert a == b and a[0] < x.mean() < a[1]


# ---------------------------------------------------------------------------------------------- layer B

def test_tranche_pnl_costs_and_funding_sign():
    n = 60
    days = np.arange(data.day_of(START), data.day_of(START) + n)
    price = 100 * 1.01 ** np.arange(n)
    m = data.Market(days, ["AUSDT#0"], ["AUSDT"], price[None, :].copy(), price[None, :].copy(), np.ones((1, n)),
                    np.array([0]), price[None, :].copy(), price[None, :].copy(), np.full((1, n), 1e-4),
                    np.full((1, n), 3, dtype=np.int16))
    book = ev.build_book(m)
    one = np.zeros((1, n), dtype=bool)
    one[0, 10] = True  # a single tranche opened on day 10, held 14 days
    costs = ev.side_cost(["AUSDT"], "normal")
    long = ev.simulate(np.ones((1, n)), one, book, costs)
    gross = (price[24] / price[10] - 1) / 14
    expected = gross - 2 * costs[0] / 14 - 14 * 1e-4 / 14
    assert long["pnl"].sum() == pytest.approx(expected, rel=1e-9)
    short = ev.simulate(-np.ones((1, n)), one, book, costs)
    assert short["pnl"].sum() == pytest.approx(-gross - 2 * costs[0] / 14 + 14 * 1e-4 / 14, rel=1e-9)
    neutral = ev.simulate(np.zeros((1, n)), one, book, costs)
    assert neutral["pnl"].sum() == 0
    assert ev.side_cost(["BTCUSDT", "XUSDT"], "adverse") == pytest.approx([9e-4, 1.2e-3])


def test_metrics_drawdown_and_years():
    pnl = np.array([0.1, -0.2, 0.05, 0.0])
    days = np.arange(data.day_of(date(2020, 12, 30)), data.day_of(date(2020, 12, 30)) + 4)
    r = ev.metrics(pnl, days, 0, 3, np.array([1.0, 0.5, 0.5, 1.0]))
    assert r["max_dd"] == pytest.approx(0.2) and r["net"] == pytest.approx(-0.05)
    assert set(r["by_year"]) == {2020, 2021} and r["exposure"] == pytest.approx(0.75)


def test_training_pipeline_end_to_end_on_synthetic_data(tmp_path, monkeypatch):
    from scripts.research_regime import run

    monkeypatch.setattr(run, "N_RANDOM_A", 20)
    monkeypatch.setattr(run, "N_RANDOM_B", 10)
    monkeypatch.setattr(run, "N_BOOT", 200)
    n = (date(2023, 1, 31) - date(2017, 8, 17)).days + 1
    for kind in ("spot", "perp", "funding"):
        (tmp_path / kind).mkdir()
    for i in range(24):
        sym = "BTCUSDT" if i == 0 else f"S{i:02d}USDT"
        s = _series(n, start=date(2017, 8, 17), seed=i, drift=0.0003 * (i % 3 - 1))
        np.savez(tmp_path / "spot" / f"{sym}.npz", **s)
        k = s["day"] >= data.day_of(date(2020, 1, 1))
        np.savez(tmp_path / "perp" / f"{sym}.npz", **{key: v[k] for key, v in s.items()})
        f = _funding((date(2023, 1, 31) - date(2020, 1, 1)).days + 1, start=date(2020, 1, 1), seed=i)
        np.savez(tmp_path / "funding" / f"{sym}.npz", **f)
    (tmp_path / "manifest.json").write_text("{}")
    out = run.train(tmp_path, tmp_path / "out")
    assert set(out["results"]) == {"D1", "D2", "D3", "D4", "D5", "D6"}
    assert out["verdict"] in ("selected", "no_detector_passes_training")
    d4 = out["results"]["D4"]
    assert d4["window"][0] == "2021-01-08"
    for r in out["results"].values():
        q1 = r["q1_basket"]
        assert q1["ci_surplus_90"][0] <= q1["ci_surplus_90"][1]
        assert set(r["q2_basket"]["normal"]["comparators"]) == {"always_long", "always_short", "simple_trend"}
    assert (tmp_path / "out" / "training.json").exists()
