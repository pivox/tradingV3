"""Schémas Pydantic de l'API Python Orchestrator.

Les schémas reflètent la cible documentée dans
``docs/handbook/technical/python-orchestrator.md``.
"""

from __future__ import annotations

from typing import List, Literal

from pydantic import BaseModel, Field

RunStatus = Literal["success", "partial_failure", "failed"]


class Health(BaseModel):
    """Réponse du healthcheck."""

    status: str = "ok"
    service: str = "python-orchestrator"
    version: str = "0.1.0"


class OrchestratorSet(BaseModel):
    """Unité fonctionnelle prête à être exécutée par l'orchestrateur.

    Schéma cible décrit dans la doc. En PY-001 les sets sont simulés en
    mémoire ; la persistance arrive avec DB-001.
    """

    set_id: str = Field(..., description="Identifiant stable du set.")
    enabled: bool = Field(default=True, description="Set actif ou non.")
    action: str = Field(default="mtf_run", description="Action: mtf_run, sync_contracts, …")
    exchange: str = Field(..., description="bitmart, okx, hyperliquid, fake, …")
    market_type: str = Field(default="perpetual", description="perpetual ou spot.")
    mtf_profile: str = Field(default="regular", description="regular, scalper, scalper_micro.")
    environment: str = Field(default="demo", description="demo, testnet, mainnet.")
    dry_run: bool = Field(default=True, description="Simulation ou exécution réelle.")
    workers: int = Field(default=1, ge=1, description="Workers côté Symfony (1 au début).")
    sync_tables: bool = Field(default=False, description="Sync des tables exchange côté Symfony.")
    symbols: List[str] = Field(default_factory=list, description="Liste optionnelle de symboles.")
    priority: int = Field(default=0, description="Ordre / priorité fonctionnelle.")


class RunSummary(BaseModel):
    """Résumé agrégé d'un run."""

    total_calls: int = 0
    success: int = 0
    failed: int = 0


class RunResponse(BaseModel):
    """Réponse minimale de ``POST /orchestrator/run``.

    Contrat important : ``ok=false`` n'est pas un succès Temporal (TM-002).
    """

    ok: bool
    run_id: str
    status: RunStatus
    summary: RunSummary
