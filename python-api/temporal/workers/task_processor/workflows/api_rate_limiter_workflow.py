from temporalio import workflow
from datetime import timedelta
import json

from models.api_call_request import ApiCallRequest


@workflow.defn
class ApiRateLimiterWorkflow:
    def __init__(self):
        self.queue = []
        self.last_call_time = None

    @workflow.run
    async def run(self):
        workflow.logger.info("ApiRateLimiterWorkflow started")

        while True:
            # Attend un signal s'il n'y a rien à traiter
            await workflow.wait_condition(lambda: len(self.queue) > 0)

            request = self.queue.pop(0)

            now = workflow.now()

            # Respect du throttling : au moins 1 seconde entre deux appels
            if self.last_call_time:
                elapsed = (now - self.last_call_time).total_seconds()
                if elapsed < 1:
                    to_wait = 1 - elapsed
                    workflow.logger.info(f"Throttling: sleeping {to_wait:.2f}s")
                    await workflow.sleep(to_wait)

            # Exécute l'activité
            workflow.logger.info(f"Calling API for: {request.uri}")
            await workflow.execute_activity(
                'activities.api_activities.call_api',  # Le nom de l'activité doit être aligné avec l'enregistrement côté worker
                request,
                start_to_close_timeout=timedelta(seconds=30),
            )

            self.last_call_time = workflow.now()

    @workflow.signal
    async def call_api_with_throttle(self, request):
        """
        Signal pour injecter une nouvelle requête dans la queue.
        Accepte un dict Python ou un JSON string.
        """

        # Vérifie si vide
        if not request:
            workflow.logger.error("Received empty signal input")
            return

        # Si c'est un JSON string, essaie de décoder
        if isinstance(request, str):
            try:
                request = json.loads(request)
                workflow.logger.info("Signal payload parsed from JSON string")
            except json.JSONDecodeError:
                workflow.logger.error(f"Invalid JSON string received: {request}")
                return

        # Vérifie que c'est bien un dict
        if not isinstance(request, dict):
            workflow.logger.error(f"Invalid request type received: {type(request)}")
            return

        # Tente de construire l'objet ApiCallRequest
        try:
            request_obj = ApiCallRequest(**request)
            self.queue.append(request_obj)
            workflow.logger.info(f"Signal received and enqueued: {request_obj.uri}")
        except Exception as e:
            workflow.logger.error(f"Failed to build ApiCallRequest: {str(e)}")
