"""Pure async orchestration with bounded concurrency and fail_policy.

The per-target runner is injected (``run_target``), so this logic is unit-testable with plain
asyncio and no Temporal server. The Workflow passes a ``run_target`` that executes the Temporal
activities for one target; tests pass a fake coroutine.
"""
from __future__ import annotations

import asyncio
from typing import Any, Awaitable, Callable, Dict, List

from dashboards.aggregate import aggregate_target_results, plan_batches

# run_target(target) -> {"ok": bool, "target_id": str, ...}
TargetRunner = Callable[[Dict[str, Any]], Awaitable[Dict[str, Any]]]


async def _safe_run(run_target: TargetRunner, target: Dict[str, Any]) -> Dict[str, Any]:
    try:
        result = await run_target(target)
    except Exception as exc:  # noqa: BLE001 - a failing target must not crash the batch gather
        return {"target_id": target.get("target_id"), "ok": False, "error": str(exc)}
    if not isinstance(result, dict):
        result = {"ok": bool(result)}
    result.setdefault("target_id", target.get("target_id"))
    result.setdefault("ok", False)
    return result


async def orchestrate(
    dashboard_id: str,
    targets: List[Dict[str, Any]],
    fail_policy: str,
    max_concurrency: int,
    run_target: TargetRunner,
) -> Dict[str, Any]:
    """Run targets in bounded-concurrency batches; aggregate all-or-nothing.

    ``fail_fast`` stops scheduling further batches once a batch contains a failure; ``continue``
    runs every target then aggregates.
    """
    results: List[Dict[str, Any]] = []
    for batch in plan_batches(targets, max_concurrency):
        batch_results = await asyncio.gather(*[_safe_run(run_target, target) for target in batch])
        results.extend(batch_results)
        if fail_policy == "fail_fast" and any(not result["ok"] for result in batch_results):
            break
    return aggregate_target_results(dashboard_id, fail_policy, len(targets), results)
