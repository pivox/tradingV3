from temporalio import workflow
from datetime import timedelta

@workflow.defn(name="CronSymfony15mWorkflow")
class CronSymfony15mWorkflow:
    @workflow.run
    async def run(self, url: str) -> None:
        workflow.logger.info(f"[Cron15min] Appel Symfony: {url}")
        result = await workflow.execute_activity(
            "call_symfony_endpoint",
            args=[url],
            start_to_close_timeout=timedelta(seconds=60),
        )
        workflow.logger.info(f"[Cron15m] Réponse Symfony: {result}")
