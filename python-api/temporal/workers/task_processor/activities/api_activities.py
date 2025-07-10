from temporalio import activity
from models.api_call_request import ApiCallRequest
import requests
import logging

logger = logging.getLogger(__name__)

@activity.defn(name="activities.api_activities.call_api")
async def call_api(request: ApiCallRequest) -> dict:
    try:
        session = requests.Session()
        req = requests.Request(
            request.method,
            request.uri,
            json=request.payload or {},
            headers=request.headers or {}
        )
        prepped = session.prepare_request(req)
        response = session.send(prepped, timeout=request.timeout)

        logger.info(f"API {request.method} {request.uri} -> {response.status_code}")

        if not response.ok:
            return {
                "status": "error",
                "code": response.status_code,
                "body": response.text,
                "message": f"HTTP error {response.status_code}"
            }

        return {
            "status": "success",
            "code": response.status_code,
            "body": response.text
        }

    except requests.exceptions.Timeout:
        logger.error(f"Timeout on {request.uri}")
        return {"status": "error", "message": "Request timed out"}

    except requests.exceptions.RequestException as e:
        logger.error(f"Request failed: {str(e)}")
        return {"status": "error", "message": str(e)}
