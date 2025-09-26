from typing import Iterable

from temporalio import workflow
from datetime import timedelta
from tools.endpoint_types import EndpointJob


@workflow.defn(name="CronSymfony5mWorkflow")
class CronSymfony5mWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[EndpointJob]) -> None:
        for job in jobs:
            workflow.logger.info(f"[Cron5m] Appel Symfony: {job.url}")
            try:
                result = await workflow.execute_activity(
                    "call_symfony_endpoint",
                    args=[url],
                    start_to_close_timeout=timedelta(seconds=60),
                )
                workflow.logger.info(f"[Cron5m] Réponse {job.url}: {result}")
            except Exception as e:
                workflow.logger.error(f"[Cron5m] Erreur pour {job.url}: {e}")