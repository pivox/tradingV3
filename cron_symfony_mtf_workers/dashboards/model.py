"""Dashboard / target model (parsing, dry-run-only gate, snapshot, fingerprint).

A *dashboard* is a named matrix of *targets*; each target maps to one Symfony
``POST /api/mtf/run``. The Symfony endpoint is a single source of truth
(``MTF_WORKERS_URL`` resolved in the activity), so targets do NOT carry a free-form URL
(no SSRF surface).

The dry-run-only guardrail for OKX (PR11) and Hyperliquid (PR12) is reused from
``scripts.manage_exchange_profile_schedule`` (single source of truth, never duplicated).

This module imports ``scripts`` (which pulls ``subprocess`` etc.) so it must only be used from
**activities** / scripts / tests — never imported inside the Temporal workflow sandbox.
"""
from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Optional

from scripts.manage_exchange_profile_schedule import (
    assert_exchange_schedule_policy,
    parse_bool,
)

DEFAULT_CADENCE = "*/1 * * * *"
DEFAULT_FAIL_POLICY = "continue"
DEFAULT_MAX_CONCURRENCY = 4
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
    """One row of a dashboard.

    ``environment`` (``demo`` / ``testnet`` / ``mainnet``) is a real runtime dimension: it is part
    of the target fingerprint and is forwarded to Symfony (consumed by the effective trading config
    resolver). It is NOT a live authorization — OKX/Hyperliquid stay dry-run only.
    """

    target_id: str
    exchange: str
    market_type: str = "perpetual"
    mtf_profile: Optional[str] = None
    environment: Optional[str] = None
    dry_run: bool = True
    workers: int = 4
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

        mtf_profile = data.get("mtf_profile")
        # Accept legacy alias "network" for "environment".
        environment = data.get("environment", data.get("network"))

        return cls(
            target_id=target_id,
            exchange=exchange,
            market_type=str(data.get("market_type") or "perpetual").strip() or "perpetual",
            mtf_profile=str(mtf_profile).strip() if mtf_profile else None,
            environment=str(environment).strip() if environment else None,
            dry_run=parse_bool(data.get("dry_run"), True),
            workers=max(1, int(data.get("workers", 4))),
            symbols=_normalize_symbols(data.get("symbols")),
            force_run=parse_bool(data.get("force_run"), False),
        )

    def fingerprint(self) -> str:
        """Stable short hash of the *effective* target config.

        Included in the idempotency key so a config change (same ``target_id``) yields a different
        key, preventing a stale-payload collision across retries/replays.
        """
        payload = {
            "exchange": self.exchange,
            "market_type": self.market_type,
            "mtf_profile": self.mtf_profile,
            "environment": self.environment,
            "dry_run": self.dry_run,
            "workers": self.workers,
            "symbols": self.symbols,
            "force_run": self.force_run,
        }
        blob = json.dumps(payload, sort_keys=True, separators=(",", ":"))
        return hashlib.sha256(blob.encode("utf-8")).hexdigest()[:12]

    def to_snapshot(self) -> Dict[str, Any]:
        return {
            "target_id": self.target_id,
            "exchange": self.exchange,
            "market_type": self.market_type,
            "mtf_profile": self.mtf_profile,
            "environment": self.environment,
            "dry_run": self.dry_run,
            "workers": self.workers,
            "symbols": self.symbols,
            "force_run": self.force_run,
            "fingerprint": self.fingerprint(),
        }


@dataclass(frozen=True)
class Dashboard:
    dashboard_id: str
    targets: List[DashboardTarget]
    cadence: str = DEFAULT_CADENCE
    fail_policy: str = DEFAULT_FAIL_POLICY
    max_concurrency: int = DEFAULT_MAX_CONCURRENCY

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
            max_concurrency=max(1, int(data.get("max_concurrency", DEFAULT_MAX_CONCURRENCY))),
        )

    def validate_policy(self) -> None:
        """Refuse any OKX/Hyperliquid target running live (dry_run=false), casing-proof."""
        for target in self.targets:
            assert_exchange_schedule_policy(target.exchange, target.dry_run)

    def to_snapshot(self) -> Dict[str, Any]:
        return {
            "dashboard_id": self.dashboard_id,
            "cadence": self.cadence,
            "fail_policy": self.fail_policy,
            "max_concurrency": self.max_concurrency,
            "targets": [target.to_snapshot() for target in self.targets],
        }


def load_dashboards(raw: Dict[str, Any]) -> Dict[str, Dashboard]:
    """Build ``{dashboard_id: Dashboard}`` from a parsed mapping (fail-closed on live OKX/HL)."""
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
    import yaml  # lazy import: only the activity/CLI paths read files

    with open(path, "r", encoding="utf-8") as handle:
        raw = yaml.safe_load(handle) or {}
    return load_dashboards(raw)
