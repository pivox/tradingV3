"""Synthetic tests of the H1 S_B decisive Gate 1 verdict (v1 §9.2 + A2 §3.2); no market data."""

from __future__ import annotations

import json
from datetime import datetime, timezone

from scripts.research_listing import sb


def _ts(y: int, m: int, d: int = 1) -> int:
    return int(datetime(y, m, d, tzinfo=timezone.utc).timestamp())


def test_months_between_spans_year_end():
    assert sb.months_between(_ts(2023, 11, 20), _ts(2024, 2, 3)) == ["2023-11", "2023-12", "2024-01", "2024-02"]


def _events(values: list[float]) -> list[dict]:
    months = [f"{y}-{m:02d}" for y in (2024, 2025) for m in range(1, 13)]
    return [{"month": months[i % len(months)], "main_s14": v, "sens_s14": v, "main_ar14": v - 0.01, "sens_ar14": v - 0.01,
             "main_control_ar14_median": -0.01, "sens_control_ar14_median": -0.01} for i, v in enumerate(values)]


def _block(mean: float, ci_upper: float, z_mean: float, z_upper: float) -> dict:
    return {"s14": {"n": 1, "mean": mean}, "s14_ci90_1m": [mean - 0.05, ci_upper], "s14_ci90_2m": [mean - 0.06, ci_upper],
            "z14": {"n": 1, "mean": z_mean}, "z14_ci90_1m": [z_mean - 0.1, z_upper], "z14_ci90_2m": [z_mean - 0.1, z_upper]}


def _write(tmp_path, values, block, coverage=0.9):
    (tmp_path / "prepare.json").write_text(json.dumps({"diagnostics": {"primary": {"a2_coverage": coverage}}}))
    path = tmp_path / "outcomes.json"
    path.write_text(json.dumps({"events": _events(values), "report": {"primary": {"main": block, "sens": block}}}))
    return path


def test_verdict_passes_a_broad_stable_negative_effect(tmp_path):
    values = [-0.10 + 0.002 * (i % 7) for i in range(240)]  # every event negative, evenly spread over 24 months
    mean = sum(values) / len(values)
    doc = sb.verdict(_write(tmp_path, values, _block(mean, -0.02, -0.2, -0.05)), tmp_path / "v.json")
    assert doc["verdict"] == "GATE1_PASS", doc["failed"]


def test_verdict_fails_when_one_event_carries_the_effect(tmp_path):
    values = [0.001] * 239 + [-3.0]
    mean = sum(values) / len(values)
    doc = sb.verdict(_write(tmp_path, values, _block(mean, -0.001, -0.2, -0.05)), tmp_path / "v.json")
    assert doc["verdict"] == "GATE1_FAIL"
    assert "main_4_no_event_over_5pct" in doc["failed"] and "main_2_p_negative_ge_55pct" in doc["failed"]


def test_verdict_fails_on_threshold_risk_check_and_coverage(tmp_path):
    values = [-0.03] * 240  # broad but smaller than the -5 % threshold
    doc = sb.verdict(_write(tmp_path, values, _block(-0.03, -0.01, -0.1, 0.02), coverage=0.6), tmp_path / "v.json")
    failed = set(doc["failed"])
    assert {"main_1_mean_le_minus_5pct", "main_A2_z_ci_upper_lt_0_1m", "5_a2_coverage_ge_70pct"} <= failed
