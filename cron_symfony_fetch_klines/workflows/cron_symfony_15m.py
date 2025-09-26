from typing import Iterable

from temporalio import workflow
from datetime import timedelta
from tools.endpoint_types import EndpointJob

@workflow.defn(name="CronSymfony15mWorkflow")
class CronSymfony15mWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[EndpointJob]) -> None:
        for job in jobs:
            workflow.logger.info(f"[Cron15min] Appel Symfony: {job.url}")
            result = await workflow.execute_activity(
                "call_symfony_endpoint",
                args=[job.url],
                start_to_close_timeout=timedelta(seconds=60),
            )
            workflow.logger.info(f"[Cron15m] Réponse Symfony: {result}")
