from __future__ import annotations

import time
from typing import Any, Dict, Optional

import requests

from .config import BitmartConfig
from .logging_config import get_logger
from .signing import BitmartSigner


logger = get_logger(__name__)


class BitmartRestClient:
    def __init__(self, config: BitmartConfig, signer: BitmartSigner):
        self._config = config
        self._signer = signer

    def fetch_positions(self, symbol: Optional[str] = None) -> Dict[str, Any]:
        params = {"symbol": symbol} if symbol else None
        return self._request("GET", "/contract/private/position-v2", params=params)

    def _request(self, method: str, path: str, *, params: Optional[Dict[str, Any]] = None, json_body: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        timestamp = str(int(time.time() * 1000))
        payload, body_string = self._signer.build_rest_components(method, path, params, json_body)
        signature = self._signer.sign(timestamp, payload)

        headers = {
            "X-BM-KEY": self._config.api_key,
            "X-BM-TIMESTAMP": timestamp,
            "X-BM-SIGN": signature,
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

        url = f"{self._config.rest_url}{path}"
        request_kwargs = {
            "method": method,
            "url": url,
            "params": params,
            "timeout": self._config.rest_timeout,
            "headers": headers,
        }
        if body_string:
            request_kwargs["data"] = body_string

        response = requests.request(**request_kwargs)
        if response.status_code >= 400:
            logger.warning("Bitmart HTTP %s %s failed: %s", method, path, response.text)
            response.raise_for_status()

        payload = response.json()
        code = int(payload.get("code", 0))
        if code != 1000:
            message = payload.get("message", "unknown error")
            raise RuntimeError(f"Bitmart API error {code}: {message}")
        return payload
