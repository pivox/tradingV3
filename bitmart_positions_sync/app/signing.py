from __future__ import annotations

import hashlib
import hmac
from dataclasses import dataclass
from typing import Any, Dict, Optional

from urllib.parse import urlencode


@dataclass(frozen=True)
class BitmartSigner:
    api_secret: str
    api_memo: str

    def sign(self, timestamp_ms: str, payload: str) -> str:
        message = f"{timestamp_ms}#{self.api_memo}#{payload}"
        digest = hmac.new(self.api_secret.encode("utf-8"), message.encode("utf-8"), hashlib.sha256)
        return digest.hexdigest()

    def sign_ws_login(self, timestamp_ms: str, payload: str) -> str:
        return self.sign(timestamp_ms, payload)

    def build_rest_components(
        self,
        method: str,
        path: str,
        params: Optional[Dict[str, Any]] = None,
        json_body: Optional[Dict[str, Any]] = None,
    ) -> tuple[str, str]:
        serializable_params = params or {}
        query_string = urlencode(serializable_params, doseq=True)
        target = path
        if query_string:
            target = f"{path}?{query_string}"

        body = ""
        if json_body:
            body = _compact_json(json_body)

        payload = f"{method.upper()}\n{target}\n{body}"
        return payload, body


def _compact_json(payload: Dict[str, Any]) -> str:
    import json

    return json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
