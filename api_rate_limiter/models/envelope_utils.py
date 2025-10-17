# -*- coding: utf-8 -*-
from __future__ import annotations
import os
import uuid
from typing import Dict, Any, Optional

# Base URL de l'app Symfony (utilisée si url_callback est relative)
SYMFONY_BASE_URL = os.getenv("SYMFONY_BASE_URL", "http://symfony-app")

# Mapping TF → bucket du rate limiter
TF_TO_BUCKET: Dict[str, str] = {
    "4h": "4h",
    "1h": "1h",
    "15m": "15m",
    "5m": "5m",
    "1m": "1m",
}


def build_kline_envelope(symbol: str, timeframe: str, limit: int = 270, extra_meta: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    """Construit une enveloppe de callback pour get-kline côté Symfony.
    Renvoie un dict directement consommable par ApiCallRequest.from_dict().
    """
    meta = {
        "source": "temporal",
        "batch_id": (extra_meta or {}).get("batch_id") or str(uuid.uuid4()),
        "request_id": (extra_meta or {}).get("request_id") or str(uuid.uuid4()),
        "root_tf": timeframe,
    }
    parent_tf = (extra_meta or {}).get("parent_tf")
    pipeline = (extra_meta or {}).get("pipeline")
    if parent_tf:
        meta["parent_tf"] = parent_tf
    if pipeline:
        meta["pipeline"] = pipeline

    return {
        "url_callback": "api/callback/bitmart/get-kline",
        "base_url": SYMFONY_BASE_URL,
        "method": "POST",
        "params": {
            "contract": symbol,
            "timeframe": timeframe,
            "limit": int(limit),
            "meta": meta,
        },
    }

