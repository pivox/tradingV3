"""Schémas Pydantic de l'API Python Orchestrator.

Les schémas reflètent la cible documentée dans
``docs/handbook/technical/python-orchestrator.md`` et verrouillent dès le
squelette les valeurs autorisées et les invariants produit (garde-fous live,
borne ``workers``).
"""

from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import List, Literal, Optional, Tuple

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


def assert_live_allowed(exchange: Exchange, dry_run: bool) -> None:
    """Garde-fou live : interdit ``dry_run=false`` sur OKX/Hyperliquid.

    Factorisé pour être réutilisé par ``OrchestratorSet`` (sets simulés) et par
    la gestion DB des sets (PY-002), afin que la règle soit appliquée partout
    de façon identique. Lève ``ValueError`` (mappé en 422 côté API).
    """
    if dry_run is False and exchange in LIVE_FORBIDDEN_EXCHANGES:
        raise ValueError(
            f"{exchange.value} live est interdit : dry_run doit rester true."
        )


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
        assert_live_allowed(self.exchange, self.dry_run)
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


# ---------------------------------------------------------------------------
# Gestion des dashboards et des sets (PY-002)
#
# Schémas d'entrée/sortie de l'API de configuration : création, lecture, mise à
# jour partielle et suppression. Ils s'appuient sur les mêmes enums et garde-fous
# que ``OrchestratorSet`` mais sont distincts car ils portent les colonnes
# persistées (id, dashboard_id, horodatages, ``payload`` préparé).
# ---------------------------------------------------------------------------


class DashboardCreate(BaseModel):
    """Payload de création d'un dashboard d'orchestration."""

    name: str = Field(..., min_length=1, max_length=255, description="Nom unique du dashboard.")
    enabled: bool = Field(default=True, description="Dashboard actif ou non.")
    description: Optional[str] = Field(default=None, description="Description libre.")


class DashboardUpdate(BaseModel):
    """Mise à jour partielle d'un dashboard (seuls les champs fournis changent)."""

    name: Optional[str] = Field(default=None, min_length=1, max_length=255)
    enabled: Optional[bool] = None
    description: Optional[str] = None


class DashboardRead(BaseModel):
    """Représentation d'un dashboard renvoyée par l'API."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    name: str
    enabled: bool
    description: Optional[str] = None
    created_at: datetime
    updated_at: datetime


class SetCreate(BaseModel):
    """Payload de création d'un set rattaché à un dashboard.

    ``set_id`` est l'identifiant fonctionnel stable du set au sein du dashboard
    (unique via ``uq_orchestration_sets_dashboard_set``). Le garde-fou live
    OKX/Hyperliquid est appliqué dès la validation.
    """

    set_id: str = Field(..., min_length=1, max_length=255, description="Identifiant stable du set.")
    enabled: bool = Field(default=True, description="Set actif ou non.")
    action: Action = Field(default=Action.MTF_RUN, description="Action à exécuter.")
    exchange: Exchange = Field(..., description="Exchange cible.")
    market_type: MarketType = Field(default=MarketType.PERPETUAL, description="perpetual ou spot.")
    mtf_profile: MtfProfile = Field(default=MtfProfile.REGULAR, description="Profil MTF.")
    environment: Environment = Field(default=Environment.DEMO, description="demo, testnet, mainnet.")
    dry_run: bool = Field(default=True, description="Simulation ou exécution réelle.")
    workers: int = Field(default=1, ge=1, le=MAX_WORKERS_PER_SET, description="Workers Symfony (borné).")
    sync_tables: bool = Field(default=False, description="Sync des tables exchange côté Symfony.")
    symbols: List[str] = Field(default_factory=list, description="Liste optionnelle de symboles.")
    contracts_limit: Optional[int] = Field(default=None, ge=1, description="Limite de contrats (PY-004).")
    priority: int = Field(default=0, description="Ordre / priorité fonctionnelle.")
    payload: Optional[dict] = Field(default=None, description="Payload Symfony préparé (PY-004).")

    @model_validator(mode="after")
    def _forbid_live_on_restricted_exchanges(self) -> "SetCreate":
        assert_live_allowed(self.exchange, self.dry_run)
        return self


class SetUpdate(BaseModel):
    """Mise à jour partielle d'un set.

    ``set_id`` est immuable (renommer = supprimer puis recréer). Le garde-fou
    live est revalidé sur l'état résultant côté router, car ``exchange`` et
    ``dry_run`` peuvent n'être que partiellement fournis.
    """

    enabled: Optional[bool] = None
    action: Optional[Action] = None
    exchange: Optional[Exchange] = None
    market_type: Optional[MarketType] = None
    mtf_profile: Optional[MtfProfile] = None
    environment: Optional[Environment] = None
    dry_run: Optional[bool] = None
    workers: Optional[int] = Field(default=None, ge=1, le=MAX_WORKERS_PER_SET)
    sync_tables: Optional[bool] = None
    symbols: Optional[List[str]] = None
    contracts_limit: Optional[int] = Field(default=None, ge=1)
    priority: Optional[int] = None
    payload: Optional[dict] = None


class SetRead(BaseModel):
    """Représentation d'un set persistant renvoyée par l'API."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    dashboard_id: int
    set_id: str
    enabled: bool
    action: Action
    exchange: Exchange
    market_type: MarketType
    mtf_profile: MtfProfile
    environment: Environment
    dry_run: bool
    workers: int
    sync_tables: bool
    symbols: List[str]
    contracts_limit: Optional[int] = None
    priority: int
    payload: Optional[dict] = None
    created_at: datetime
    updated_at: datetime
