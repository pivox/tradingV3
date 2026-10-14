"""Synthetic tests of the H1 conventions and the listing-order classification (no market data)."""

from __future__ import annotations

import math

import pytest

from scripts.research_listing import audit as au
from scripts.research_listing import conventions as cv

DAY = 86_400_000


def test_relative_return_and_paired_surplus():
    r_coin = cv.log_return(1.0, 1.10)  # +10 %
    r_market = cv.log_return(100.0, 115.0)  # +15 %
    ar = cv.relative_return(r_coin, r_market)
    assert ar == pytest.approx(math.log(1.10) - math.log(1.15))
    assert ar < 0  # underperforms the market...
    assert cv.short_return(1.0, 1.10) == pytest.approx(-0.10)  # ...while an unhedged short loses 10 % before costs
    assert cv.paired_surplus(-0.20, [-0.05, -0.15]) == pytest.approx(-0.10)
    with pytest.raises(ValueError):
        cv.paired_surplus(-0.2, [])


def test_funding_sign_convention():
    # A positive rate: the long pays, the short receives.
    assert cv.funding_cashflow("short", 10.0, 2.0, 0.0001) == pytest.approx(0.002)
    assert cv.funding_cashflow("long", 10.0, 2.0, 0.0001) == pytest.approx(-0.002)
    # A negative rate (crowded shorts): the short pays.
    assert cv.funding_cashflow("short", 10.0, 2.0, -0.0075) == pytest.approx(-0.15)


def test_stop_distance_is_three_sigma_within_floor_and_cap():
    assert cv.stop_distance(0.02) == 0.15
    assert cv.stop_distance(0.10) == pytest.approx(0.30)
    assert cv.stop_distance(0.50) == 0.60
    returns = [0.001, -0.001] * 720
    assert cv.sigma24(returns) == pytest.approx(0.001 * math.sqrt(1440) * math.sqrt(1440 / 1439), rel=1e-6)


def test_sizing_never_rounds_up_or_exceeds_the_budget():
    plan = cv.size_short(500.0, 0.005, entry=1.0, distance=0.15, contract_value=1.0, lot=1.0, min_contracts=1.0, costs=cv.BASELINE)
    assert plan.reason is None and plan.planned_loss <= 2.50 and plan.contracts == 14.0  # 2.50 / 0.1706 = 14.65 -> 14
    # One contract worth more than the allowed notional: refused, not rounded up.
    big = cv.size_short(500.0, 0.005, entry=20.0, distance=0.15, contract_value=1.0, lot=1.0, min_contracts=1.0, costs=cv.BASELINE)
    assert big.reason == "below_minimum_size" and big.contracts == 0
    # A venue minimum notional above the allowed notional (wide stop): refused.
    wide = cv.size_short(500.0, 0.005, entry=0.01, distance=0.60, contract_value=1.0, lot=1.0, min_contracts=1.0, costs=cv.BASELINE,
                         min_notional=5.0)
    assert wide.reason == "below_minimum_notional"


def test_pessimistic_costs_reduce_the_allowed_notional():
    base = cv.loss_fraction_at_stop(0.15, cv.BASELINE)
    pess = cv.loss_fraction_at_stop(0.15, cv.PESSIMISTIC)
    assert base == pytest.approx(0.15 + 0.0005 + 0.002 + 0.0005 + 0.005 + 0.0126)
    assert pess > base and 2.5 / pess < 2.5 / base


def test_max_entry_price_for_the_minimum_order():
    assert cv.max_entry_price(500.0, 0.005, 0.15, contract_value=10.0, min_contracts=0.1, costs=cv.BASELINE) == pytest.approx(2.5 / 0.1706 / 1.0)
    assert cv.max_entry_price(500.0, 0.005, 0.60, contract_value=1.0, min_contracts=1.0, costs=cv.BASELINE, min_notional=5.0) is None


def _okx(t0, verified=True, premarket=False):
    return {"t0_ms": t0, "date_verified": verified, "premarket": premarket}


def _bin(t0, source="binance_exchangeinfo_onboardDate"):
    return {"t0_ms": t0, "date_source": source}


def test_listing_order_classification():
    t = 1_700_000_000_000
    assert au.venue_order(_okx(t), None) == "FIRST_OBSERVED_PERP_LISTING"
    assert au.venue_order(_okx(t), _bin(t + 10 * DAY)) == "FIRST_OBSERVED_PERP_LISTING"
    assert au.venue_order(_okx(t + 10 * DAY), _bin(t)) == "FOLLOW_ON_VENUE_LISTING"
    assert au.venue_order(_okx(t + 3_600_000), _bin(t)) == "FIRST_OBSERVED_PERP_LISTING"  # co-listing within 24 h
    assert au.venue_order(_okx(t, premarket=True), _bin(t + 10 * DAY)) == "PREMARKET"
    # An archive-based (unverified) date close to the other venue's: the order is unknown, never promoted.
    assert au.venue_order(_okx(t + 5 * DAY, verified=False), _bin(t)) == "UNKNOWN_ORDER"
    assert au.venue_order(_okx(t), _bin(t + 5 * DAY, "binance_archive_first_daily_file")) == "UNKNOWN_ORDER"


def test_eligibility_requires_a_verified_first_listing():
    row = {"excluded_base": False, "order": "FIRST_OBSERVED_PERP_LISTING", "date_verified": True, "relisting": False,
           "coverage_ok": True, "funding": True}
    assert au.eligible(row)
    for key, value in (("date_verified", False), ("order", "UNKNOWN_ORDER"), ("relisting", True), ("coverage_ok", False),
                       ("funding", False), ("excluded_base", True)):
        assert not au.eligible({**row, key: value})


def test_normalise_bases():
    assert au.normalise("1000PEPE") == "PEPE" and au.normalise("1000000MOG") == "MOG" and au.normalise("1INCH") == "1INCH"


def _series(start, closes):
    np = pytest.importorskip("numpy")
    from scripts.research_listing import study as st

    ts = np.array([start + 60 * i for i in range(len(closes))], dtype=np.int64)
    return st.Series(ts, np.array(closes, dtype=float))


def test_price_before_uses_only_completed_minutes():
    s = _series(3600, [1.0, 2.0, 3.0])
    assert s.price_before(3600 + 120) == 2.0  # the candle opened at +60 ended at +120
    assert s.price_before(3600) is None  # nothing completed before the first candle


def test_hourly_sigma_uses_prices_up_to_t_only():
    from scripts.research_listing import study as st

    closes = [1.0 + 0.01 * (i // 60 % 2) for i in range(26 * 60)]
    s = _series(0, closes)
    t = 25 * 3600
    sigma = st.hourly_sigma(s, t)
    later = _series(0, closes + [100.0] * 600)  # prices after t changed: σ at t unchanged
    assert sigma is not None and sigma == st.hourly_sigma(later, t)


def test_block_ci_widens_with_clustering():
    from scripts.research_listing import study as st

    spread = {f"2024-{m:02d}": [(-1) ** m * 0.1] * 5 for m in range(1, 13)}
    narrow = {f"2024-{m:02d}": [0.1, -0.1] * 2 + [0.0] for m in range(1, 13)}
    a, b = st.block_ci(spread, 1, draws=2000), st.block_ci(narrow, 1, draws=2000)
    assert (a[1] - a[0]) > (b[1] - b[0])


def test_outcomes_refuse_an_existing_output(tmp_path):
    from scripts.research_listing import study as st

    out = tmp_path / "outcomes.json"
    out.write_text("{}")
    with pytest.raises(SystemExit):
        st.outcomes(tmp_path / "prepare.json", tmp_path, out)


def test_normalized_return_uses_the_volatility_at_t():
    assert cv.normalized_return(-0.10, 0.25, 14) == pytest.approx(-0.10 / (0.25 * math.sqrt(14)))
    with pytest.raises(ValueError):
        cv.normalized_return(-0.1, 0.0, 14)


def test_a2_controls_follow_the_volume_ranking_without_volatility_filter():
    ranking = ["A", "B", "C", "D", "E"]
    assert cv.select_established_controls(ranking, {"B", "D", "E"}, 2) == ["B", "D"]
    assert cv.select_established_controls(ranking, set(), 20) == []
