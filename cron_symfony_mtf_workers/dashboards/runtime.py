"""Pure per-target helpers used by the Temporal activities.

No Temporal, no Docker, no HTTP here: these functions take plain inputs so they are fully
unit-testable. The activities in ``activities.dashboard`` are thin wrappers that perform the
actual I/O (httpx) and delegate the decisions here.

PR13 scope: the dashboard orchestrator is dry-run-only for every exchange (see
``dashboards.model``), so there is no live runtime-check / Docker exec on this path.
"""
from __future__ import annotations

from typing import Any, Dict

DEFAULT_SYMFONY_URL = "http://trading-app-nginx:80/api/mtf/run"
# Symfony top-level applicative statuses considered a failed run.
_OK_STATUS = "success"
_TRANSIENT_HTTP_STATUSES = {408, 425, 429, 500, 502, 503, 504}


def to_symfony_body(target: Dict[str, Any], idempotency_key: str) -> Dict[str, Any]:
    """Build the Symfony ``/api/mtf/run`` body for a target snapshot.

    Same keys as the legacy ``MtfJob.payload`` plus ``environment`` and ``idempotency_key``, which
    are forwarded for forward-compatibility/audit. NOTE: ``/api/mtf/run`` does not consume them yet
    (no real demo/mainnet piloting, no Symfony-side dedup) — this is why the dashboard path is
    dry-run-only. No ``url``: the endpoint is resolved from ``MTF_WORKERS_URL`` in the activity
    (single source of truth, no per-target URL).
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


def is_transient_http(status: Any) -> bool:
    """A transport-less but transient HTTP outcome that is worth retrying per target."""
    if status is None:
        return True  # transport failure (no response)
    return isinstance(status, int) and status in _TRANSIENT_HTTP_STATUSES


def is_mtf_run_success(raw_response: Dict[str, Any]) -> bool:
    """Applicative success contract for an /api/mtf/run response (fail-closed).

    Requires: HTTP 2xx AND a JSON object body AND top-level ``status == "success"`` (when present)
    AND ``success`` not ``False`` AND no non-empty ``data.errors``. A non-JSON 2xx body (HTML proxy
    page, empty, "OK", ...) is NOT a success. Per-symbol ERROR statuses inside the results are not
    treated as a run failure (normal MTF output).
    """
    if not raw_response.get("ok"):  # HTTP-level failure (non-2xx)
        return False

    body = raw_response.get("body")
    if not isinstance(body, dict):
        return False  # /api/mtf/run must return a JSON object

    status = body.get("status")
    if isinstance(status, str) and status.strip().lower() != _OK_STATUS:
        return False  # e.g. "error", a blocking business status

    if body.get("success") is False:
        return False

    data = body.get("data")
    if isinstance(data, dict) and data.get("errors"):
        return False

    return True


def decide_runtime_check(target: Dict[str, Any]) -> Dict[str, Any]:
    """Per-target guardrail: the dashboard orchestrator is dry-run-only for every exchange.

    Raises ``RuntimeError`` for any ``dry_run=false`` target (defense in depth — the dashboard is
    also validated at snapshot load and at schedule creation). No live runtime-check / Docker exec.
    """
    if not target.get("dry_run", True):
        raise RuntimeError(
            f"target '{target.get('target_id')}' ({target.get('exchange')}) must be dry_run=true: "
            "the dashboard orchestrator is dry-run-only."
        )
    return {"ok": True, "checked": False, "reason": "dry_run"}
