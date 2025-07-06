from temporalio import workflow
from datetime import timedelta
from activities.hello_activity import say_hello

@workflow.defn
class HelloWorkflow:
    @workflow.run
    async def run(self, name: str) -> str:
        return await workflow.execute_activity(
            say_hello,
            name,
            schedule_to_close_timeout=timedelta(seconds=5),
        )