from typing import Iterable

from temporalio import workflow
from datetime import timedelta
from tools.endpoint_types import EndpointJob


@workflow.defn(name="CronSymfony1hWorkflow")
class CronSymfony1hWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[EndpointJob]) -> None:
        for job in jobs:
            workflow.logger.info(f"[Cron1h] Appel Symfony: {job.url}")
            result = await workflow.execute_activity(
                "call_symfony_endpoint",              # 👈 nom de l’activité
                args=[job.url],
                start_to_close_timeout=timedelta(seconds=60),
            )
            workflow.logger.info(f"[Cron1h] Réponse Symfony: {result}")
