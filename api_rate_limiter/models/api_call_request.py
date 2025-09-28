# -*- coding: utf-8 -*-
from __future__ import annotations
from dataclasses import dataclass
from typing import Any, Dict, Optional


@dataclass
class ApiCallRequest:
    """
    Represents one queued API call request.
    Keeps the original dict (raw) and exposes a method
    to transform it into an envelope compatible with
    the `post_callback` activity.
    """
    raw: Dict[str, Any]

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "ApiCallRequest":
        """
        Build an ApiCallRequest from a dict.
        The dict usually comes directly from PHP.
        """
        if not isinstance(data, dict):
            raise TypeError(
                f"ApiCallRequest.from_dict expects dict, got {type(data).__name__}"
            )
        return cls(raw=dict(data))  # keep a copy of the original payload

    def to_activity_payload(self) -> Dict[str, Any]:
        """
        Transform the raw dict into an envelope for the activity.
        The envelope must contain at least:
          - url_callback (absolute or relative URL)
          - method (default: POST)
          - base_url (optional, used if url_callback is relative)
          - params (actual request payload)
          - encoding (optional, default 'form')
        """
        item = self.raw

        # Extract the URL to call: accept multiple possible keys
        url_callback: Optional[str] = (
            item.get("url_callback")
            or item.get("endpoint")
            or item.get("url")
        )

        # Base URL, used when url_callback is relative
        base_url: str = item.get("base_url") or item.get("base") or ""

        # HTTP method, default to POST
        method: str = (item.get("method") or "POST").upper()

        # Determine the payload for the request
        # - Prefer 'params'
        # - Fallback to 'payload' or 'data'
        # - As a last resort, use the raw dict without meta keys
        if isinstance(item.get("params"), dict):
            params = item["params"]
        elif isinstance(item.get("payload"), dict):
            params = item["payload"]
        elif isinstance(item.get("data"), dict):
            params = item["data"]
        else:
            exclude = {"url_callback", "endpoint", "base_url", "base", "method", "encoding"}
            params = {k: v for k, v in item.items() if k not in exclude}

        # Encoding type (not heavily used, but may help later)
        encoding = (item.get("encoding") or "form").lower()

        # Ensure url_callback is present (otherwise the activity will error out)
        if not url_callback:
            url_callback = ""  # minimal fallback to keep envelope valid

        return {
            "url_callback": url_callback,
            "base_url": base_url,
            "method": method,
            "encoding": encoding,
            "params": params,
        }
