from temporalio import workflow
from datetime import timedelta

@workflow.defn(name="CronSymfony15mWorkflow")
class CronSymfony15mWorkflow:
    @workflow.run
        async def run(self, urls: list[str]) -> None:
        workflow.logger.info(f"[Cron15min] Appel Symfony: {url}")
        for url in urls:
            result = await workflow.execute_activity(
                "call_symfony_endpoint",
                args=[url],
                start_to_close_timeout=timedelta(seconds=60),
            )
            workflow.logger.info(f"[Cron15m] Réponse Symfony: {result}")
