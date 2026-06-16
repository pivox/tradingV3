"""Sequential dashboard orchestrator (pure, HTTP-free).

``run_dashboard`` expands a dashboard into per-target Symfony calls, runs them
**sequentially** (waiting for each response), aggregates the results and reports OK only
when every called target succeeded. The actual HTTP transport is injected as ``caller``
so the orchestration is deterministic and unit-testable without any network.
"""
from __future__ import annotations

from typing import Any, Callable, Dict, Optional

from bridge.dashboard import Dashboard

# caller(url, body) -> {"ok": bool, "status": int|None, "summary"?: str, "error"?: str, ...}
TargetCaller = Callable[[str, Dict[str, Any]], Dict[str, Any]]


def run_dashboard(
    dashboard: Dashboard,
    tick_timestamp: str,
    caller: TargetCaller,
    *,
    run_id: Optional[str] = None,
    schedule_id: Optional[str] = None,
) -> Dict[str, Any]:
    # Defense in depth: never run OKX/Hyperliquid live, even if a dashboard slipped through.
    dashboard.validate_policy()

    results = []
    overall_ok = True

    for target in dashboard.targets:
        body = target.to_payload(dashboard.dashboard_id, tick_timestamp)
        idempotency_key = body["idempotency_key"]
        try:
            response = caller(target.url, body)
        except Exception as exc:  # noqa: BLE001 - surface caller failures as a failed target
            response = {"ok": False, "status": None, "error": str(exc)}

        target_ok = bool(response.get("ok"))
        results.append(
            {
                "target_id": target.target_id,
                "exchange": target.exchange,
                "network": target.network,
                "market_type": target.market_type,
                "mtf_profile": target.mtf_profile,
                "dry_run": target.dry_run,
                "url": target.url,
                "idempotency_key": idempotency_key,
                "ok": target_ok,
                "status": response.get("status"),
                "summary": response.get("summary"),
                "error": response.get("error"),
            }
        )

        if not target_ok:
            overall_ok = False
            if dashboard.fail_policy == "fail_fast":
                break

    return {
        "ok": overall_ok,
        "dashboard_id": dashboard.dashboard_id,
        "run_id": run_id,
        "schedule_id": schedule_id,
        "tick_timestamp": tick_timestamp,
        "fail_policy": dashboard.fail_policy,
        "targets_total": len(dashboard.targets),
        "targets_called": len(results),
        "targets_ok": sum(1 for result in results if result["ok"]),
        "results": results,
    }
