from temporalio import workflow
from datetime import timedelta
from typing import Iterable
from tools.endpoint_types import EndpointJob


@workflow.defn(name="CronSymfony4hWorkflow")
class CronSymfony4hWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[EndpointJob]) -> None:
        for job in jobs:
            result = await workflow.execute_activity(
                "call_symfony_endpoint",              # 👈 nom de l’activité
                args=[job.url],
                start_to_close_timeout=timedelta(seconds=60),
            )
            workflow.logger.info(f"[Cron4h] Réponse Symfony: {result}")
