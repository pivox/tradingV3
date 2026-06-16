"""Temporal-native MTF dashboard orchestrator (PR13, voie 1).

A single Temporal schedule starts this workflow with ``{dashboard_id, dashboards_path?}``. The
workflow:

1. loads a fresh dashboard **snapshot** at run start (``load_dashboard_snapshot``);
2. runs **one Activity per target** (``runtime_check_target`` then ``call_mtf_run_target``) with
   per-target retry/timeout and **bounded concurrency**;
3. aggregates **all-or-nothing** per ``fail_policy`` and **raises** if any target failed, so a
   target failure is never swallowed and Temporal sees and manages each target separately.

Orchestration logic lives in the pure ``dashboards.orchestrate`` (unit-tested without a Temporal
server). OKX/Hyperliquid stay dry-run only; the legacy direct path (Bitmart) is untouched.
"""
from __future__ import annotations

from datetime import timedelta
from typing import Any, Dict

from temporalio import workflow
from temporalio.common import RetryPolicy
from temporalio.exceptions import ApplicationError

with workflow.unsafe.imports_passed_through():
    from dashboards.orchestrate import orchestrate

LOAD_TIMEOUT = timedelta(minutes=1)
RUNTIME_CHECK_TIMEOUT = timedelta(minutes=3)
TARGET_TIMEOUT = timedelta(minutes=15)
DEFAULT_MAX_CONCURRENCY = 4


@workflow.defn(name="MtfDashboardOrchestratorWorkflow")
class MtfDashboardOrchestratorWorkflow:
    @workflow.run
    async def run(self, request: Dict[str, Any]) -> Dict[str, Any]:
        dashboard_id = str(request["dashboard_id"])
        dashboards_path = request.get("dashboards_path")

        snapshot = await workflow.execute_activity(
            "load_dashboard_snapshot",
            args=[dashboard_id, dashboards_path],
            start_to_close_timeout=LOAD_TIMEOUT,
            retry_policy=RetryPolicy(maximum_attempts=3),
        )

        # Deterministic tick -> stable idempotency keys across retries/replays; the fingerprint
        # binds the key to the effective target config (no stale-payload collision).
        tick_timestamp = workflow.now().replace(microsecond=0).isoformat()
        targets = []
        for raw_target in snapshot["targets"]:
            target = dict(raw_target)
            target["idempotency_key"] = (
                f"{dashboard_id}:{target['target_id']}:{tick_timestamp}:{target['fingerprint']}"
            )
            targets.append(target)

        aggregate = await orchestrate(
            dashboard_id,
            targets,
            snapshot.get("fail_policy", "continue"),
            snapshot.get("max_concurrency", DEFAULT_MAX_CONCURRENCY),
            self._run_target,
        )
        aggregate["tick_timestamp"] = tick_timestamp
        aggregate["run_id"] = workflow.info().run_id

        workflow.logger.info(
            "[MtfDashboard] dashboard=%s ok=%s targets_ok=%s/%s",
            dashboard_id,
            aggregate["ok"],
            aggregate["targets_ok"],
            aggregate["targets_total"],
        )

        if not aggregate["ok"]:
            # All-or-nothing: never swallow a target failure.
            raise ApplicationError(f"dashboard '{dashboard_id}' had failing targets", aggregate)

        return aggregate

    async def _run_target(self, target: Dict[str, Any]) -> Dict[str, Any]:
        # Per-target live guardrail (dry-run-only OKX/HL + live runtime-check).
        await workflow.execute_activity(
            "runtime_check_target",
            args=[target],
            start_to_close_timeout=RUNTIME_CHECK_TIMEOUT,
            retry_policy=RetryPolicy(maximum_attempts=2),
        )
        result = await workflow.execute_activity(
            "call_mtf_run_target",
            args=[target, target["idempotency_key"]],
            start_to_close_timeout=TARGET_TIMEOUT,
            retry_policy=RetryPolicy(maximum_attempts=3),
        )
        return {
            "target_id": target["target_id"],
            "ok": bool(result.get("ok")),
            "status": result.get("status"),
            "summary": result.get("summary"),
            "idempotency_key": target["idempotency_key"],
        }
