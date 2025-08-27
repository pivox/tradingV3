from dataclasses import dataclass
from typing import Optional, Dict, Any

@dataclass
class ApiCallRequest:
    uri: str
    method: str = "GET"
    payload: Optional[Dict[str, Any]] = None
    headers: Optional[Dict[str, str]] = None
    timeout: int = 10
    request_id: Optional[str] = None
    callback_url: Optional[str] = None
