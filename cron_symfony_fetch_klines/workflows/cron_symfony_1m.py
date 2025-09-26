from temporalio import workflow
from datetime import timedelta
from typing import Iterable
from tools.endpoint_types import EndpointJob


@workflow.defn(name="CronSymfony1mWorkflow")
class CronSymfony1mWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[EndpointJob]) -> None:
        for job in jobs:
            workflow.logger.info(f"[Cron1m] Appel Symfony: {job.url}")
            try:
                result = await workflow.execute_activity(
                    "call_symfony_endpoint",
                    args=[job.url],  # on ne passe que l’URL à l’activité
                    start_to_close_timeout=timedelta(seconds=60),
                )
                workflow.logger.info(f"[Cron1m] Réponse {job.url}: {result}")
            except Exception as e:
                workflow.logger.error(f"[Cron1m] Erreur pour {job.url}: {e}")
