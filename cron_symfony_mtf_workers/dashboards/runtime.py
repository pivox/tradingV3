"""Pure per-target helpers used by the Temporal activities.

No Temporal, no Docker, no HTTP here: these functions take plain inputs so they are fully
unit-testable. The activities in ``activities.dashboard`` are thin wrappers that perform the
actual I/O (Docker runtime-check, httpx) and delegate the decisions here.
"""
from __future__ import annotations

from typing import Any, Callable, Dict, Optional

from scripts.manage_exchange_profile_schedule import (
    assert_exchange_schedule_policy,
    validate_live_guardrails,
)

DEFAULT_SYMFONY_URL = "http://trading-app-nginx:80/api/mtf/run"

# loader(exchange, market_type) -> parsed runtime-check dict (called only for live targets)
RuntimeCheckLoader = Callable[[str, str], Dict[str, str]]


def to_symfony_body(target: Dict[str, Any], idempotency_key: str) -> Dict[str, Any]:
    """Build the Symfony ``/api/mtf/run`` body for a target snapshot.

    Same keys as the legacy ``MtfJob.payload`` plus ``environment`` (effective-config dimension)
    and ``idempotency_key``. No ``url``: the endpoint is resolved from ``MTF_WORKERS_URL`` in the
    activity (single source of truth, no per-target URL).
    """
    body: Dict[str, Any] = {
        "workers": max(1, int(target.get("workers", 4))),
        "dry_run": bool(target.get("dry_run", True)),
        "force_run": bool(target.get("force_run", False)),
        "exchange": target["exchange"],
        "market_type": target.get("market_type", "perpetual"),
        "idempotency_key": idempotency_key,
    }
    if target.get("mtf_profile"):
        body["mtf_profile"] = target["mtf_profile"]
    if target.get("symbols"):
        body["symbols"] = target["symbols"]
    if target.get("environment"):
        body["environment"] = target["environment"]
    return body


def is_mtf_run_success(raw_response: Dict[str, Any]) -> bool:
    """Applicative success contract for an /api/mtf/run response (not just HTTP 2xx).

    Success requires HTTP 2xx AND, when the JSON body is available, ``success`` is not ``False``
    and there is no non-empty top-level ``data.errors``. Per-symbol ERROR statuses inside the
    results are NOT treated as a run failure (that is normal MTF output).
    """
    if not raw_response.get("ok"):  # HTTP-level failure
        return False

    body = raw_response.get("body")
    if not isinstance(body, dict):
        # Non-JSON body on a 2xx: cannot assess applicative content, trust the HTTP status.
        return True

    if body.get("success") is False:
        return False

    data = body.get("data")
    if isinstance(data, dict) and data.get("errors"):
        return False

    return True


def decide_runtime_check(
    target: Dict[str, Any],
    runtime_check_loader: RuntimeCheckLoader,
) -> Dict[str, Any]:
    """Per-target live guardrail decision.

    - OKX/Hyperliquid: refuse live (dry-run only, casing-proof).
    - dry-run target: no runtime-check needed.
    - live target: run the SAME guardrails as ``manage_exchange_profile_schedule`` (schedule_ready,
      credentials, live_trading) via the injected loader; raises if not satisfied.
    """
    assert_exchange_schedule_policy(target["exchange"], target["dry_run"])

    if target.get("dry_run", True):
        return {"ok": True, "checked": False, "reason": "dry_run"}

    runtime_check = runtime_check_loader(target["exchange"], target.get("market_type", "perpetual"))
    validate_live_guardrails(False, runtime_check)  # raises RuntimeError if not live-ready
    return {"ok": True, "checked": True, "runtime_check": runtime_check}
