"""Fournisseur de sets d'orchestration.

PY-001 : sets simulés en mémoire (lecture seule), sans persistance.
La persistance réelle (tables orchestration) arrivera avec DB-001, et la
préparation/refresh des sets avec PY-003/PY-004.
"""

from __future__ import annotations

from typing import List

from app.schemas import OrchestratorSet

# Sets de démonstration. Tous en dry-run et sur l'exchange "fake" pour rester
# dans le périmètre sûr de PY-001 (aucune exécution live).
_SIMULATED_SETS: List[OrchestratorSet] = [
    OrchestratorSet(
        set_id="fake_regular_demo_btc_eth",
        enabled=True,
        action="mtf_run",
        exchange="fake",
        market_type="perpetual",
        mtf_profile="regular",
        environment="demo",
        dry_run=True,
        workers=1,
        sync_tables=False,
        symbols=["BTCUSDT", "ETHUSDT"],
        priority=10,
    ),
    OrchestratorSet(
        set_id="fake_scalper_micro_demo_btc",
        enabled=True,
        action="mtf_run",
        exchange="fake",
        market_type="perpetual",
        mtf_profile="scalper_micro",
        environment="demo",
        dry_run=True,
        workers=1,
        sync_tables=False,
        symbols=["BTCUSDT"],
        priority=5,
    ),
    OrchestratorSet(
        set_id="fake_regular_demo_disabled",
        enabled=False,
        action="mtf_run",
        exchange="fake",
        market_type="perpetual",
        mtf_profile="regular",
        environment="demo",
        dry_run=True,
        workers=1,
        sync_tables=False,
        symbols=["SOLUSDT"],
        priority=1,
    ),
]


def list_sets() -> List[OrchestratorSet]:
    """Retourne tous les sets connus (actifs et inactifs)."""
    return list(_SIMULATED_SETS)


def list_active_sets() -> List[OrchestratorSet]:
    """Retourne les sets actifs, triés par priorité décroissante."""
    active = [s for s in _SIMULATED_SETS if s.enabled]
    return sorted(active, key=lambda s: s.priority, reverse=True)
