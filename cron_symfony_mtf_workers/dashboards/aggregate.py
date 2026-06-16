"""Pure batching + all-or-nothing aggregation (stdlib only, sandbox-safe for the workflow)."""
from __future__ import annotations

from typing import Any, Dict, List, Sequence


def plan_batches(targets: Sequence[Any], max_concurrency: int) -> List[List[Any]]:
    """Split targets into bounded-concurrency batches (chunks of ``max_concurrency``)."""
    size = max(1, int(max_concurrency))
    return [list(targets[i : i + size]) for i in range(0, len(targets), size)]


def aggregate_target_results(
    dashboard_id: str,
    fail_policy: str,
    targets_total: int,
    results: List[Dict[str, Any]],
) -> Dict[str, Any]:
    """All-or-nothing aggregate.

    ``ok`` is True only when **every** target was called and succeeded. Under ``fail_fast`` the
    run may stop early (``targets_called < targets_total``), which keeps ``ok`` False.
    """
    targets_ok = sum(1 for result in results if result.get("ok"))
    overall_ok = len(results) == targets_total and targets_ok == len(results)
    return {
        "ok": overall_ok,
        "dashboard_id": dashboard_id,
        "fail_policy": fail_policy,
        "targets_total": targets_total,
        "targets_called": len(results),
        "targets_ok": targets_ok,
        "results": results,
    }
