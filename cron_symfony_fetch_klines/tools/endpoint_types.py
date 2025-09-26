# types.py
from dataclasses import dataclass
from typing import Literal, Optional, Dict, Any

@dataclass
class EndpointJob:
    url: str
    # method: Literal["POST", "GET"] = "POST"
    # json: Optional[Dict[str, Any]] = None
    # headers: Optional[Dict[str, str]] = None
    # timeout_sec: int = 30
    # name: Optional[str] = None  # pour le logging
