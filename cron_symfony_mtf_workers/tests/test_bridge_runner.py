"""Tests for the sequential dashboard orchestrator (pure, no HTTP)."""

import pytest

from bridge.dashboard import Dashboard, DashboardTarget
from bridge.runner import run_dashboard


def _dashboard(fail_policy="continue", exchanges=("okx", "hyperliquid"), dry_run=True):
    targets = [
        DashboardTarget(target_id=f"t{i}", exchange=exchange, mtf_profile="scalper", dry_run=dry_run)
        for i, exchange in enumerate(exchanges)
    ]
    return Dashboard(dashboard_id="dash", targets=targets, fail_policy=fail_policy)


class RecordingCaller:
    """Deterministic caller stub recording call order; fails for ``fail_ids`` targets."""

    def __init__(self, fail_for=()):
        self.calls = []
        self.fail_for = set(fail_for)

    def __call__(self, url, payload):
        self.calls.append((url, payload))
        ok = payload["idempotency_key"].split(":")[1] not in self.fail_for
        return {"ok": ok, "status": 200 if ok else 500, "summary": "ok" if ok else "boom"}


def test_all_targets_ok():
    caller = RecordingCaller()

    result = run_dashboard(_dashboard(), "ts", caller)

    assert result["ok"] is True
    assert result["targets_total"] == 2
    assert result["targets_called"] == 2
    assert result["targets_ok"] == 2
    assert len(caller.calls) == 2


def test_one_failure_aggregates_to_not_ok_with_detail_continue():
    caller = RecordingCaller(fail_for={"t1"})

    result = run_dashboard(_dashboard(fail_policy="continue"), "ts", caller)

    assert result["ok"] is False
    # continue policy still calls every target.
    assert result["targets_called"] == 2
    assert result["targets_ok"] == 1
    failed = [r for r in result["results"] if not r["ok"]]
    assert [r["target_id"] for r in failed] == ["t1"]
    assert failed[0]["status"] == 500


def test_fail_fast_stops_at_first_failure():
    caller = RecordingCaller(fail_for={"t0"})

    result = run_dashboard(_dashboard(fail_policy="fail_fast"), "ts", caller)

    assert result["ok"] is False
    assert result["targets_called"] == 1
    assert len(caller.calls) == 1  # second target never called


def test_sequential_order_and_idempotency_keys_are_stable():
    caller = RecordingCaller()

    result = run_dashboard(_dashboard(), "2026-06-16T00:00:00+00:00", caller, run_id="r1", schedule_id="s1")

    keys = [payload["idempotency_key"] for _, payload in caller.calls]
    assert keys == [
        "dash:t0:2026-06-16T00:00:00+00:00",
        "dash:t1:2026-06-16T00:00:00+00:00",
    ]
    assert result["run_id"] == "r1"
    assert result["schedule_id"] == "s1"
    assert result["tick_timestamp"] == "2026-06-16T00:00:00+00:00"


def test_caller_exception_is_captured_as_failed_target():
    def boom(url, payload):
        raise RuntimeError("network down")

    result = run_dashboard(_dashboard(exchanges=("okx",)), "ts", boom)

    assert result["ok"] is False
    assert result["results"][0]["error"] == "network down"


def test_run_dashboard_refuses_live_okx_target():
    dashboard = _dashboard(exchanges=("okx",), dry_run=False)

    with pytest.raises(RuntimeError, match="dry_run=true"):
        run_dashboard(dashboard, "ts", RecordingCaller())
