from temporalio import workflow
from datetime import timedelta

@workflow.defn(name="CronSymfony1mWorkflow")
class CronSymfony1mWorkflow:
    @workflow.run
    async def run(self, urls: list[str]) -> None:
        """
        Workflow cron 1m : exécute call_symfony_endpoint sur chaque URL fournie.
        """
        for url in urls:
            workflow.logger.info(f"[Cron1m] Appel Symfony: {url}")
            try:
                result = await workflow.execute_activity(
                    "call_symfony_endpoint",
                    args=[url],
                    start_to_close_timeout=timedelta(seconds=60),
                )
                workflow.logger.info(f"[Cron1m] Réponse {url}: {result}")
            except Exception as e:
                workflow.logger.error(f"[Cron1m] Erreur pour {url}: {e}")
