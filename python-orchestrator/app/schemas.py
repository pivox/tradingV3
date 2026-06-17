"""Schémas Pydantic de l'API Python Orchestrator.

Les schémas reflètent la cible documentée dans
``docs/handbook/technical/python-orchestrator.md`` et verrouillent dès le
squelette les valeurs autorisées et les invariants produit (garde-fous live,
borne ``workers``).
"""

from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import Literal, Optional, Tuple

from pydantic import BaseModel, ConfigDict, Field, model_validator

from app import __version__

# Nombre maximum de workers Symfony autorisés par set. La cible impose
# `workers=1` au début ; on relèvera cette borne dans une PR dédiée.
MAX_WORKERS_PER_SET = 1


class Exchange(str, Enum):
    BITMART = "bitmart"
    OKX = "okx"
    HYPERLIQUID = "hyperliquid"
    FAKE = "fake"


class MarketType(str, Enum):
    PERPETUAL = "perpetual"
    SPOT = "spot"


class MtfProfile(str, Enum):
    REGULAR = "regular"
    SCALPER = "scalper"
    SCALPER_MICRO = "scalper_micro"


class Environment(str, Enum):
    DEMO = "demo"
    TESTNET = "testnet"
    MAINNET = "mainnet"


class Action(str, Enum):
    MTF_RUN = "mtf_run"
    SYNC_CONTRACTS = "sync_contracts"
    REPORTING = "reporting"


# Exchanges verrouillés en dry-run uniquement tant qu'une PR de readiness live
# dédiée n'a pas été validée (cf. exchange-schedule-policy.md).
LIVE_FORBIDDEN_EXCHANGES = frozenset({Exchange.OKX, Exchange.HYPERLIQUID})

RunStatus = Literal["success", "partial_failure", "failed", "no_sets"]


class Health(BaseModel):
    """Réponse du healthcheck. La version provient de ``app.__version__``."""

    status: str
    service: str
    version: str = __version__


class OrchestratorSet(BaseModel):
    """Unité fonctionnelle prête à être exécutée par l'orchestrateur.

    Schéma cible décrit dans la doc. Le modèle est immuable (``frozen``) pour
    éviter toute mutation de l'état partagé entre runs. En PY-001 les sets sont
    simulés en mémoire ; la persistance arrive avec DB-001.
    """

    model_config = ConfigDict(frozen=True)

    set_id: str = Field(..., description="Identifiant stable du set.")
    enabled: bool = Field(default=True, description="Set actif ou non.")
    action: Action = Field(default=Action.MTF_RUN, description="Action à exécuter.")
    exchange: Exchange = Field(..., description="Exchange cible.")
    market_type: MarketType = Field(default=MarketType.PERPETUAL, description="perpetual ou spot.")
    mtf_profile: MtfProfile = Field(default=MtfProfile.REGULAR, description="Profil MTF.")
    environment: Environment = Field(default=Environment.DEMO, description="demo, testnet, mainnet.")
    dry_run: bool = Field(default=True, description="Simulation ou exécution réelle.")
    workers: int = Field(
        default=1,
        ge=1,
        le=MAX_WORKERS_PER_SET,
        description="Workers côté Symfony (borné à 1 au début).",
    )
    sync_tables: bool = Field(default=False, description="Sync des tables exchange côté Symfony.")
    # Tuple (et non list) pour une immuabilité réelle : `frozen=True` n'empêche
    # pas la mutation d'une liste interne (ex. symbols.append(...)).
    symbols: Tuple[str, ...] = Field(default_factory=tuple, description="Liste optionnelle de symboles.")
    priority: int = Field(default=0, description="Ordre / priorité fonctionnelle.")

    @model_validator(mode="after")
    def _forbid_live_on_restricted_exchanges(self) -> "OrchestratorSet":
        """Interdit ``dry_run=false`` sur les exchanges verrouillés (OKX/Hyperliquid)."""
        if self.dry_run is False and self.exchange in LIVE_FORBIDDEN_EXCHANGES:
            raise ValueError(
                f"{self.exchange.value} live est interdit : dry_run doit rester true."
            )
        return self


class RunRequest(BaseModel):
    """Contexte optionnel d'un déclenchement de run.

    Permet à Temporal (ou au front) de transmettre un identifiant stable pour
    l'idempotence et de tracer l'origine du tick. Tous les champs sont
    optionnels en PY-001 ; le contrat est figé dès maintenant pour PY-002/TM-001.
    """

    dashboard_id: Optional[str] = Field(default=None, description="Dashboard d'origine.")
    schedule_id: Optional[str] = Field(default=None, description="Schedule Temporal d'origine.")
    tick_timestamp: Optional[datetime] = Field(default=None, description="Horodatage du tick.")
    idempotency_key: Optional[str] = Field(default=None, description="Clé d'idempotence explicite.")
    dry_run: Optional[bool] = Field(default=None, description="Forçage dry-run demandé par l'appelant.")


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
