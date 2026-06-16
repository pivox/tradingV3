"""Dashboard / target model for the Temporal -> Symfony bridge.

A *dashboard* is a named matrix of *targets*. Each target maps to a single Symfony
``POST /api/mtf/run`` call. The Symfony body produced by :meth:`DashboardTarget.to_payload`
mirrors :meth:`models.mtf_job.MtfJob.payload` exactly (same keys) plus a stable
``idempotency_key`` so the contract toward Symfony is unchanged.

The dry-run-only guardrail for OKX (PR11) and Hyperliquid (PR12) is reused from
``scripts.manage_exchange_profile_schedule`` (single source of truth, never duplicated):
a dashboard containing an OKX/Hyperliquid target with ``dry_run=false`` is rejected.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Optional

# Reuse the canonical dry-run-only gate and bool parser (PR11 OKX, PR12 Hyperliquid).
# These are pure helpers; importing the module triggers no Temporal connection.
from scripts.manage_exchange_profile_schedule import (
    assert_exchange_schedule_policy,
    parse_bool,
)

DEFAULT_SYMFONY_URL = "http://trading-app-nginx:80/api/mtf/run"
DEFAULT_CADENCE = "*/1 * * * *"
DEFAULT_FAIL_POLICY = "continue"
SUPPORTED_FAIL_POLICIES = {"continue", "fail_fast"}


def _normalize_symbols(raw: Any) -> Optional[List[str]]:
    if raw is None:
        return None
    if isinstance(raw, str):
        raw = raw.split(",")
    if isinstance(raw, Iterable):
        items = [str(item).strip() for item in raw if str(item).strip()]
        return items or None
    return None


def _normalize_fail_policy(raw: Any) -> str:
    if raw is None:
        return DEFAULT_FAIL_POLICY
    value = str(raw).strip().lower()
    if value not in SUPPORTED_FAIL_POLICIES:
        raise ValueError(
            f"unsupported fail_policy '{raw}' (expected one of {sorted(SUPPORTED_FAIL_POLICIES)})"
        )
    return value


@dataclass(frozen=True)
class DashboardTarget:
    """One row of a dashboard: a single Symfony MTF run.

    ``network`` (e.g. ``demo``, ``testnet``, ``mainnet``) is purely informational/audit:
    it is NEVER sent as a Symfony trading field, and ``mainnet`` is never a live
    authorization (OKX/Hyperliquid stay dry-run only).
    """

    target_id: str
    exchange: str
    market_type: str = "perpetual"
    mtf_profile: Optional[str] = None
    network: Optional[str] = None
    dry_run: bool = True
    workers: int = 4
    url: str = DEFAULT_SYMFONY_URL
    symbols: Optional[List[str]] = None
    force_run: bool = False

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "DashboardTarget":
        target_id = str(data.get("target_id") or data.get("id") or "").strip()
        if not target_id:
            raise ValueError("dashboard target requires a non-empty target_id")
        exchange = str(data.get("exchange") or "").strip()
        if not exchange:
            raise ValueError(f"dashboard target '{target_id}' requires an exchange")

        market_type = str(data.get("market_type") or "perpetual").strip() or "perpetual"
        mtf_profile = data.get("mtf_profile")
        network = data.get("network")

        return cls(
            target_id=target_id,
            exchange=exchange,
            market_type=market_type,
            mtf_profile=str(mtf_profile).strip() if mtf_profile else None,
            network=str(network).strip() if network else None,
            dry_run=parse_bool(data.get("dry_run"), True),
            workers=max(1, int(data.get("workers", 4))),
            url=str(data.get("url") or DEFAULT_SYMFONY_URL).strip() or DEFAULT_SYMFONY_URL,
            symbols=_normalize_symbols(data.get("symbols")),
            force_run=parse_bool(data.get("force_run"), False),
        )

    def idempotency_key(self, dashboard_id: str, tick_timestamp: str) -> str:
        """Stable per-target key: ``dashboard_id:target_id:tick_timestamp``.

        ``tick_timestamp`` is supplied by the deterministic Temporal tick, so retries of
        the same tick reuse the same key (downstream dedup must honor it).
        """
        return f"{dashboard_id}:{self.target_id}:{tick_timestamp}"

    def to_payload(self, dashboard_id: str, tick_timestamp: str) -> Dict[str, Any]:
        """Build the Symfony ``/api/mtf/run`` body (same keys as MtfJob.payload + idempotency_key)."""
        payload: Dict[str, Any] = {
            "workers": max(1, self.workers),
            "dry_run": self.dry_run,
            "force_run": self.force_run,
            "exchange": self.exchange,
            "market_type": self.market_type,
            "idempotency_key": self.idempotency_key(dashboard_id, tick_timestamp),
        }
        if self.mtf_profile:
            payload["mtf_profile"] = self.mtf_profile
        if self.symbols:
            payload["symbols"] = self.symbols
        return payload


@dataclass(frozen=True)
class Dashboard:
    dashboard_id: str
    targets: List[DashboardTarget]
    cadence: str = DEFAULT_CADENCE
    fail_policy: str = DEFAULT_FAIL_POLICY

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "Dashboard":
        dashboard_id = str(data.get("dashboard_id") or data.get("id") or "").strip()
        if not dashboard_id:
            raise ValueError("dashboard requires a non-empty dashboard_id")

        raw_targets = data.get("targets") or []
        if not raw_targets:
            raise ValueError(f"dashboard '{dashboard_id}' requires at least one target")

        targets = [DashboardTarget.from_dict(item) for item in raw_targets]
        seen: set[str] = set()
        for target in targets:
            if target.target_id in seen:
                raise ValueError(
                    f"dashboard '{dashboard_id}' has duplicate target_id: {target.target_id}"
                )
            seen.add(target.target_id)

        return cls(
            dashboard_id=dashboard_id,
            targets=targets,
            cadence=str(data.get("cadence") or DEFAULT_CADENCE).strip() or DEFAULT_CADENCE,
            fail_policy=_normalize_fail_policy(data.get("fail_policy")),
        )

    def validate_policy(self) -> None:
        """Refuse any OKX/Hyperliquid target running live (dry_run=false).

        Reuses the canonical, casing-proof gate from the scheduler so the dashboard path
        cannot bypass PR11/PR12. Raises ``RuntimeError`` on the first offending target.
        """
        for target in self.targets:
            assert_exchange_schedule_policy(target.exchange, target.dry_run)


def load_dashboards(raw: Dict[str, Any]) -> Dict[str, Dashboard]:
    """Build ``{dashboard_id: Dashboard}`` from an already-parsed mapping.

    The dry-run-only policy is enforced eagerly (fail-closed): a config declaring a live
    OKX/Hyperliquid target is rejected at load time.
    """
    if not isinstance(raw, dict):
        raise ValueError("dashboards config must be a mapping with a 'dashboards' list")

    entries = raw.get("dashboards") or []
    result: Dict[str, Dashboard] = {}
    for entry in entries:
        dashboard = Dashboard.from_dict(entry)
        if dashboard.dashboard_id in result:
            raise ValueError(f"duplicate dashboard_id: {dashboard.dashboard_id}")
        dashboard.validate_policy()
        result[dashboard.dashboard_id] = dashboard
    return result


def load_dashboards_file(path: str) -> Dict[str, Dashboard]:
    import yaml  # lazy import: only the Flask/CLI paths read files

    with open(path, "r", encoding="utf-8") as handle:
        raw = yaml.safe_load(handle) or {}
    return load_dashboards(raw)
