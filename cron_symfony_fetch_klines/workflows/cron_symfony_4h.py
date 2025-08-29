from temporalio import workflow
from datetime import timedelta

@workflow.defn(name="CronSymfony4hWorkflow")
class CronSymfony4hWorkflow:
    @workflow.run
    async def run(self, url: str) -> None:
        workflow.logger.info(f"[Cron4h] Appel Symfony: {url}")
        result = await workflow.execute_activity(
            "call_symfony_endpoint",              # 👈 nom de l’activité
            args=[url],
            start_to_close_timeout=timedelta(seconds=60),
        )
        workflow.logger.info(f"[Cron4h] Réponse Symfony: {result}")
