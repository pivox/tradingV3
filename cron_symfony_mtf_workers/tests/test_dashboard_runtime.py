"""Tests for the pure per-target helpers: Symfony body, success contract, dry-run-only guardrail."""

import pytest

from dashboards.runtime import (
    decide_runtime_check,
    is_mtf_run_success,
    is_transient_http,
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


def test_is_transient_http():
    assert is_transient_http(None) is True       # transport failure
    assert is_transient_http(429) is True
    assert is_transient_http(503) is True
    assert is_transient_http(400) is False       # deterministic client error
    assert is_transient_http(200) is False


def test_is_mtf_run_success_requires_http_2xx():
    assert is_mtf_run_success({"ok": False, "status": 500, "body": {}}) is False


def test_is_mtf_run_success_true_on_status_success():
    assert is_mtf_run_success(
        {"ok": True, "status": 200, "body": {"status": "success", "data": {"summary": {}}}}
    ) is True


def test_is_mtf_run_success_false_on_top_level_error_status():
    # HTTP 200 but Symfony reports a blocking business status -> not a success.
    assert is_mtf_run_success({"ok": True, "status": 200, "body": {"status": "error"}}) is False


def test_is_mtf_run_success_false_on_application_errors_or_flag():
    assert is_mtf_run_success({"ok": True, "status": 200, "body": {"data": {"errors": ["boom"]}}}) is False
    assert is_mtf_run_success({"ok": True, "status": 200, "body": {"success": False}}) is False


def test_is_mtf_run_success_fail_closed_on_non_json_body():
    # /api/mtf/run must return a JSON object; a 2xx HTML/empty/"OK" body is NOT a trading success.
    assert is_mtf_run_success({"ok": True, "status": 200, "body": "OK"}) is False


def test_decide_runtime_check_skips_for_dry_run():
    result = decide_runtime_check({"target_id": "t", "exchange": "okx", "dry_run": True})

    assert result == {"ok": True, "checked": False, "reason": "dry_run"}


def test_decide_runtime_check_blocks_any_live_target():
    for exchange in ("okx", "hyperliquid", "bitmart"):
        with pytest.raises(RuntimeError, match="dry_run=true"):
            decide_runtime_check({"target_id": "t", "exchange": exchange, "dry_run": False})
