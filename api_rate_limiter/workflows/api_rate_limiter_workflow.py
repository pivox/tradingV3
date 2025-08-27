# workflows/api_rate_limiter_workflow.py
from temporalio import workflow
from datetime import timedelta
from typing import Dict, Any, List, Optional

ONE_SECOND = timedelta(seconds=1)

@workflow.defn(name="ApiRateLimiterClient")  # aligne ce nom avec PHP (workflowType)
class ApiRateLimiterClient:
    def __init__(self):
        self._queue: List[Dict[str, Any]] = []
        self._closed: bool = False
        self._results: Dict[str, Dict[str, Any]] = {}

    @workflow.signal
    async def submit(self, envelope: Dict[str, Any]) -> None:
        envelope.setdefault("request_id", workflow.uuid4())  # UUID "workflow-safe"
        self._queue.append(envelope)

    @workflow.signal
    async def close(self) -> None:
        self._closed = True

    @workflow.query
    def size(self) -> int:
        return len(self._queue)

    @workflow.query
    def get_result(self, request_id: str) -> Optional[Dict[str, Any]]:
        return self._results.get(request_id)

    @workflow.run
    async def run(self) -> None:
        while True:
            await workflow.wait_condition(lambda: self._queue or self._closed)
            if self._closed and not self._queue:
                # rester vivant, prêt à recevoir un futur signal
                self._closed = False
                continue

            envelope = self._queue.pop(0)

            await workflow.sleep(ONE_SECOND)

            result = await workflow.execute_activity(
                "post_callback",
                args=[envelope],
                start_to_close_timeout=timedelta(seconds=30),
                schedule_to_close_timeout=timedelta(seconds=60),
            )

            self._results[envelope["request_id"]] = {
                "status": result.get("status"),
                "meta": result,
            }
