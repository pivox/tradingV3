import asyncio
from temporalio import workflow
from task_processor.activities import (
    check_redis_for_task,
    call_api,
    post_result_to_symfony
)

@workflow.defn
class GenericTaskProcessorWorkflow:
    @workflow.run
    async def run(self, queue_name: str, symfony_base_url: str, poll_interval: int = 5):
        while True:
            task = await workflow.execute_activity(
                check_redis_for_task,
                queue_name,
                5,
                start_to_close_timeout=10
            )

            if task:
                # Appel API générique
                result = await workflow.execute_activity(
                    call_api,
                    task,
                    start_to_close_timeout=60
                )

                # Envoi vers Symfony
                response_target = task.get("response_target")
                if response_target:
                    await workflow.execute_activity(
                        post_result_to_symfony,
                        result,
                        symfony_base_url,
                        response_target,
                        start_to_close_timeout=30
                    )

            await asyncio.sleep(poll_interval)
