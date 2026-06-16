"""Tests for the pure async orchestration: batching, bounded concurrency, fail_policy, aggregation.

These cover the Workflow's orchestration logic without a Temporal server, by injecting a fake
per-target runner.
"""

import asyncio

from dashboards.aggregate import aggregate_target_results, plan_batches
from dashboards.orchestrate import orchestrate


def _targets(n):
    return [{"target_id": f"t{i}"} for i in range(n)]


def test_plan_batches_chunks_by_concurrency():
    assert plan_batches(_targets(5), 2) == [
        [{"target_id": "t0"}, {"target_id": "t1"}],
        [{"target_id": "t2"}, {"target_id": "t3"}],
        [{"target_id": "t4"}],
    ]
    assert plan_batches(_targets(2), 0) == [[{"target_id": "t0"}], [{"target_id": "t1"}]]


def test_aggregate_all_ok():
    agg = aggregate_target_results("d", "continue", 2, [{"ok": True}, {"ok": True}])

    assert agg["ok"] is True
    assert agg["targets_ok"] == 2
    assert agg["targets_called"] == 2


def test_aggregate_not_ok_when_a_target_failed():
    agg = aggregate_target_results("d", "continue", 2, [{"ok": True}, {"ok": False}])

    assert agg["ok"] is False
    assert agg["targets_ok"] == 1


def test_aggregate_not_ok_when_fail_fast_stopped_early():
    # Only one of two targets was called -> all-or-nothing keeps ok False.
    agg = aggregate_target_results("d", "fail_fast", 2, [{"ok": False}])

    assert agg["ok"] is False
    assert agg["targets_called"] == 1
    assert agg["targets_total"] == 2


def test_orchestrate_runs_all_targets_when_ok():
    async def run_target(target):
        return {"ok": True, "target_id": target["target_id"]}

    agg = asyncio.run(orchestrate("d", _targets(3), "continue", 2, run_target))

    assert agg["ok"] is True
    assert agg["targets_called"] == 3


def test_orchestrate_continue_calls_every_target_even_with_a_failure():
    calls = []

    async def run_target(target):
        calls.append(target["target_id"])
        ok = target["target_id"] != "t1"
        return {"ok": ok}

    agg = asyncio.run(orchestrate("d", _targets(3), "continue", 1, run_target))

    assert agg["ok"] is False
    assert calls == ["t0", "t1", "t2"]  # continue ran all
    assert agg["targets_ok"] == 2


def test_orchestrate_fail_fast_stops_after_failing_batch():
    calls = []

    async def run_target(target):
        calls.append(target["target_id"])
        ok = target["target_id"] != "t0"
        return {"ok": ok}

    agg = asyncio.run(orchestrate("d", _targets(4), "fail_fast", 1, run_target))

    assert agg["ok"] is False
    assert calls == ["t0"]  # stopped after the first failing batch
    assert agg["targets_called"] == 1


def test_orchestrate_target_exception_is_captured_as_failure():
    async def run_target(target):
        raise RuntimeError("activity blew up")

    agg = asyncio.run(orchestrate("d", _targets(1), "continue", 1, run_target))

    assert agg["ok"] is False
    assert agg["results"][0]["error"] == "activity blew up"


def test_orchestrate_respects_bounded_concurrency():
    state = {"current": 0, "max": 0}

    async def run_target(target):
        state["current"] += 1
        state["max"] = max(state["max"], state["current"])
        await asyncio.sleep(0)  # yield so concurrent tasks in a batch interleave
        state["current"] -= 1
        return {"ok": True}

    asyncio.run(orchestrate("d", _targets(6), "continue", 2, run_target))

    assert state["max"] <= 2  # never more than max_concurrency in flight
