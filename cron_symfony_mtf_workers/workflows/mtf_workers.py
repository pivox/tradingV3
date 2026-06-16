from __future__ import annotations

from datetime import timedelta
from typing import Any, Iterable

from temporalio import workflow

from models.mtf_job import MtfJob

# Default bridge endpoint (kept as a literal to stay deterministic inside the workflow
# sandbox). A schedule normally sets ``bridge_url`` explicitly in the bridge job.
DEFAULT_BRIDGE_URL = "http://mtf-bridge:8090/bridge/run"


def _is_bridge_job(item: Any) -> bool:
    """A bridge job is a dict carrying a ``dashboard_id`` (matrix owned by the bridge)."""
    return isinstance(item, dict) and bool(item.get("dashboard_id"))


def _to_mtf_job(item: Any) -> MtfJob:
    if isinstance(item, MtfJob):
        return item
    if isinstance(item, dict):
        return MtfJob.from_dict(item)
    return MtfJob.from_dict({"url": str(item)})


@workflow.defn(name="CronSymfonyMtfWorkersWorkflow")
class CronSymfonyMtfWorkersWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[Any]) -> None:
        for item in jobs:
            if _is_bridge_job(item):
                await self._run_bridge_job(item)
            else:
                await self._run_legacy_job(item)

    async def _run_legacy_job(self, item: Any) -> None:
        """Unchanged direct path: POST the Symfony /api/mtf/run endpoint (Bitmart legacy)."""
        job = _to_mtf_job(item)
        payload = job.payload()
        payload.setdefault("workers", job.workers)
        timeout = timedelta(minutes=job.timeout_minutes)
        workflow.logger.info(
            "[CronMtfWorkers] calling %s with payload=%s (timeout=%d min)",
            job.url,
            payload,
            job.timeout_minutes,
        )
        try:
            result = await workflow.execute_activity(
                "mtf_api_call",
                args=[job.url, payload],
                start_to_close_timeout=timeout,
            )
            # Log concise summary only (full response in result["full_response"])
            workflow.logger.info(
                "[CronMtfWorkers] ✅ Result:\n%s",
                result.get("summary", "No summary available"),
            )
        except Exception as exc:  # noqa: BLE001
            workflow.logger.error("[CronMtfWorkers] error for %s: %s", job.url, exc)

    async def _run_bridge_job(self, item: dict) -> None:
        """Bridge path: send a thin dashboard payload; the bridge owns the matrix."""
        dashboard_id = str(item.get("dashboard_id"))
        bridge_url = str(item.get("bridge_url") or DEFAULT_BRIDGE_URL)
        timeout_minutes = max(1, int(item.get("timeout_minutes", 15) or 15))
        # Deterministic tick time -> stable idempotency keys across activity retries/replays.
        tick_timestamp = workflow.now().replace(microsecond=0).isoformat()
        payload = {
            "dashboard_id": dashboard_id,
            "run_id": workflow.info().run_id,
            "schedule_id": item.get("schedule_id"),
            "dry_run": item.get("dry_run", True),
            "tick_timestamp": tick_timestamp,
        }
        workflow.logger.info(
            "[CronMtfWorkers] bridge call %s dashboard=%s payload=%s",
            bridge_url,
            dashboard_id,
            payload,
        )
        try:
            result = await workflow.execute_activity(
                "bridge_dashboard_call",
                args=[bridge_url, payload],
                start_to_close_timeout=timedelta(minutes=timeout_minutes),
            )
            workflow.logger.info(
                "[CronMtfWorkers] ✅ bridge dashboard=%s ok=%s targets_ok=%s/%s",
                dashboard_id,
                result.get("ok"),
                result.get("targets_ok"),
                result.get("targets_total"),
            )
        except Exception as exc:  # noqa: BLE001
            workflow.logger.error("[CronMtfWorkers] bridge error dashboard=%s: %s", dashboard_id, exc)
