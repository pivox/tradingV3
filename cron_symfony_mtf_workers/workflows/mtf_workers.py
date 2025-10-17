from __future__ import annotations

from datetime import timedelta
from typing import Any, Iterable, List

from temporalio import workflow

from models.mtf_job import MtfJob


def _normalize_jobs(raw_jobs: Iterable[Any]) -> List[MtfJob]:
    jobs: List[MtfJob] = []
    for item in raw_jobs:
        if isinstance(item, MtfJob):
            jobs.append(item)
        elif isinstance(item, dict):
            jobs.append(MtfJob.from_dict(item))
        else:
            jobs.append(MtfJob.from_dict({"url": str(item)}))
    return jobs


@workflow.defn(name="CronSymfonyMtfWorkersWorkflow")
class CronSymfonyMtfWorkersWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[Any]) -> None:
        normalized = _normalize_jobs(jobs)
        for job in normalized:
            payload = job.payload()
            payload.setdefault("workers", job.workers)
            workflow.logger.info(
                "[CronMtfWorkers] calling %s with payload=%s",
                job.url,
                payload,
            )
            try:
                result = await workflow.execute_activity(
                    "mtf_api_call",
                    args=[job.url, payload],
                    start_to_close_timeout=timedelta(minutes=5),
                )
                workflow.logger.info("[CronMtfWorkers] response %s", result)
            except Exception as exc:  # noqa: BLE001
                workflow.logger.error("[CronMtfWorkers] error for %s: %s", job.url, exc)
