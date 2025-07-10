from temporalio import workflow
from datetime import timedelta

from models.api_call_request import ApiCallRequest

@workflow.defn
class ApiRateLimiterWorkflow:
    def __init__(self):
        self.last_call_time = None

    @workflow.run
    async def run(self):
        await workflow.sleep(float('inf'))

    @workflow.signal
    async def call_api_with_throttle(self, request: ApiCallRequest):
        now = workflow.now()

        if self.last_call_time:
            elapsed = (now - self.last_call_time).total_seconds()
            if elapsed < 1:
                await workflow.sleep(1 - elapsed)

        await workflow.execute_activity(
            'activities.api_activities.call_api',
            request,
            start_to_close_timeout=timedelta(seconds=30)
        )

        self.last_call_time = workflow.now()
