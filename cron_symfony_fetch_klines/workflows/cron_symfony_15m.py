from typing import Any, Iterable, List

from temporalio import workflow
from datetime import timedelta
from tools.endpoint_types import EndpointJob


def _normalize_jobs(jobs_any: Iterable[Any]) -> List[EndpointJob]:
    jobs: List[EndpointJob] = []
    for it in jobs_any:
        if isinstance(it, EndpointJob):
            jobs.append(it)
        elif isinstance(it, str):
            jobs.append(EndpointJob(url=it))
        elif isinstance(it, dict) and 'url' in it:
            jobs.append(EndpointJob(url=str(it['url'])))
        else:
            jobs.append(EndpointJob(url=str(it)))
    return jobs

@workflow.defn(name="CronSymfony15mWorkflow")
class CronSymfony15mWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[Any]) -> None:
        normalized = _normalize_jobs(jobs)
        for job in normalized:
            workflow.logger.info(f"[Cron15min] Appel Symfony: {job.url}")
            result = await workflow.execute_activity(
                "call_symfony_endpoint",
                args=[job.url],
                start_to_close_timeout=timedelta(seconds=60),
            )
            workflow.logger.info(f"[Cron15m] Réponse Symfony: {result}")
