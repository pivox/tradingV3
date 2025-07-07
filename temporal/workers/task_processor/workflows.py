from temporalio import workflow
from datetime import timedelta

@workflow.defn
class GenericTaskProcessorWorkflow:
    @workflow.run
    async def run(self, queue_name: str, symfony_base_url: str, poll_interval: int = 5):
        while True:
            task = await workflow.execute_activity(
                "check_redis_for_task",  # ← nom de l’activity (string), pas fonction directe
                queue_name,
                5,
                start_to_close_timeout=timedelta(seconds=10)
            )

            if task:
                result = await workflow.execute_activity(
                    "call_api",
                    task,
                    start_to_close_timeout=timedelta(seconds=60)
                )

                response_target = task.get("response_target")
                if response_target:
                    await workflow.execute_activity(
                        "post_result_to_symfony",
                        result,
                        symfony_base_url,
                        response_target,
                        start_to_close_timeout=timedelta(seconds=30)
                    )

            await workflow.sleep(poll_interval)
