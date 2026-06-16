"""Tests for the pure per-target helpers: Symfony body, success contract, runtime-check decision."""

import pytest

from dashboards.runtime import (
    decide_runtime_check,
    is_mtf_run_success,
    to_symfony_body,
)


def test_to_symfony_body_has_no_url_and_carries_environment_and_key():
    target = {
        "target_id": "t",
        "exchange": "okx",
        "market_type": "perpetual",
        "mtf_profile": "scalper",
        "environment": "demo",
        "dry_run": True,
        "workers": 4,
    }

    body = to_symfony_body(target, "dash:t:ts:abc123")

    assert body == {
        "workers": 4,
        "dry_run": True,
        "force_run": False,
        "exchange": "okx",
        "market_type": "perpetual",
        "idempotency_key": "dash:t:ts:abc123",
        "mtf_profile": "scalper",
        "environment": "demo",
    }
    assert "url" not in body


def test_to_symfony_body_omits_absent_optionals():
    body = to_symfony_body({"target_id": "t", "exchange": "bitmart"}, "k")

    assert "mtf_profile" not in body
    assert "environment" not in body
    assert "symbols" not in body


def test_is_mtf_run_success_requires_http_2xx():
    assert is_mtf_run_success({"ok": False, "status": 500, "body": {}}) is False


def test_is_mtf_run_success_true_on_clean_body():
    assert is_mtf_run_success({"ok": True, "status": 200, "body": {"data": {"summary": {}}}}) is True


def test_is_mtf_run_success_false_on_application_errors_in_2xx():
    assert is_mtf_run_success({"ok": True, "status": 200, "body": {"data": {"errors": ["boom"]}}}) is False
    assert is_mtf_run_success({"ok": True, "status": 200, "body": {"success": False}}) is False


def test_is_mtf_run_success_lenient_on_non_dict_body():
    # A 2xx with a non-JSON body cannot be assessed applicatively -> trust the HTTP status.
    assert is_mtf_run_success({"ok": True, "status": 200, "body": "OK"}) is True


def test_decide_runtime_check_skips_for_dry_run():
    def loader(exchange, market_type):
        raise AssertionError("dry-run target must not run a runtime-check")

    result = decide_runtime_check({"exchange": "okx", "dry_run": True}, loader)

    assert result == {"ok": True, "checked": False, "reason": "dry_run"}


def test_decide_runtime_check_blocks_live_okx_before_loader():
    def loader(exchange, market_type):
        raise AssertionError("live OKX must be blocked before any runtime-check")

    with pytest.raises(RuntimeError, match="dry_run=true"):
        decide_runtime_check({"exchange": "okx", "dry_run": False, "market_type": "perpetual"}, loader)


def test_decide_runtime_check_live_bitmart_runs_guardrails():
    def loader(exchange, market_type):
        return {"schedule_ready": "yes", "credentials": "ok", "live_trading": "enabled"}

    result = decide_runtime_check(
        {"exchange": "bitmart", "dry_run": False, "market_type": "perpetual"}, loader
    )

    assert result["ok"] is True
    assert result["checked"] is True


def test_decide_runtime_check_live_bitmart_fails_when_not_ready():
    def loader(exchange, market_type):
        return {"schedule_ready": "no", "credentials": "missing", "live_trading": "disabled"}

    with pytest.raises(RuntimeError):
        decide_runtime_check(
            {"exchange": "bitmart", "dry_run": False, "market_type": "perpetual"}, loader
        )
