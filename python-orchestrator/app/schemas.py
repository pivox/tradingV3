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

    Utilisé par ``OrchestratorSet`` (sets simulés en mémoire, non persistés). La
    configuration persistante (PY-002) applique une règle **plus stricte** via
    ``assert_set_persistable``. Lève ``ValueError`` (mappé en 422 côté API).
    """
    if dry_run is False and exchange in LIVE_FORBIDDEN_EXCHANGES:
        raise ValueError(
            f"{exchange.value} live est interdit : dry_run doit rester true."
        )


def assert_set_persistable(*, dry_run: bool, symbols: list, contracts_limit: Optional[int]) -> None:
    """Invariants d'un set **persisté** via l'API de configuration (PY-002).

    1. Aucun live persistable tant que la readiness live n'est pas livrée : un
       set stocké pourra être consommé tel quel par PY-005 sans nouvelle
       validation humaine, donc on refuse tout ``dry_run=false`` (tous exchanges,
       tous environnements) à ce stade.
    2. Pas de set ambigu : il faut une sélection exploitable — soit ``symbols``
       non vide, soit ``contracts_limit`` renseigné — sinon PY-005 devrait
       deviner quoi exécuter.

    Lève ``ValueError`` (mappé en 422 côté API).
    """
    if dry_run is False:
        raise ValueError(
            "persister un set live (dry_run=false) est interdit tant que la "
            "readiness live n'est pas livrée : PY-002 ne stocke que des sets dry-run."
        )
    if not symbols and contracts_limit is None:
        raise ValueError(
            "set ambigu : fournir une sélection exploitable "
            "('symbols' non vide ou 'contracts_limit')."
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

# Champs (par modèle de mise à jour) adossés à une colonne NULLABLE et donc
# autorisés à recevoir un ``null`` explicite en PATCH (les autres colonnes sont
# NOT NULL : un null explicite y est refusé pour éviter un 500/409 trompeur).
_DASHBOARD_NULLABLE_UPDATE_FIELDS = frozenset({"description"})


def _reject_explicit_nulls(data: object, *, fields, nullable) -> object:
    """Rejette un ``null`` explicite sur un champ adossé à une colonne NOT NULL.

    Pydantic accepte un ``null`` pour tout champ ``Optional`` ; combiné à
    ``model_dump(exclude_unset=True)``, la clé est conservée et écraserait une
    colonne NOT NULL (caught en 409 trompeur, voire 500). On le bloque en amont
    (`422`). Les colonnes NULLABLE (``description``, ``contracts_limit``) restent
    effaçables par un ``null`` explicite.
    """
    if isinstance(data, dict):
        for key, value in data.items():
            if value is None and key in fields and key not in nullable:
                raise ValueError(f"champ '{key}' ne peut pas être null.")
    return data


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

    @model_validator(mode="before")
    @classmethod
    def _forbid_null_overrides(cls, data: object) -> object:
        return _reject_explicit_nulls(
            data, fields=cls.model_fields, nullable=_DASHBOARD_NULLABLE_UPDATE_FIELDS
        )


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
    (unique via ``uq_orchestration_sets_dashboard_set``).

    ``payload`` n'est **pas** accepté ici : c'est un artefact produit côté serveur
    (PY-004) à partir des champs typés ; un client REST ne doit pas pouvoir
    persister un payload incohérent avec ``exchange`` / ``mtf_profile`` /
    ``symbols``. Il reste exposé en lecture seule via ``SetRead``.
    """

    set_id: str = Field(..., min_length=1, max_length=255, description="Identifiant stable du set.")
    enabled: bool = Field(default=True, description="Set actif ou non.")
    action: Action = Field(default=Action.MTF_RUN, description="Action à exécuter.")
    exchange: Exchange = Field(..., description="Exchange cible.")
    market_type: MarketType = Field(default=MarketType.PERPETUAL, description="perpetual ou spot.")
    mtf_profile: MtfProfile = Field(default=MtfProfile.REGULAR, description="Profil MTF.")
    environment: Environment = Field(default=Environment.DEMO, description="demo, testnet, mainnet.")
    dry_run: bool = Field(default=True, description="Simulation (true). Le live n'est pas persistable en PY-002.")
    workers: int = Field(default=1, ge=1, le=MAX_WORKERS_PER_SET, description="Workers Symfony (borné).")
    sync_tables: bool = Field(default=False, description="Sync des tables exchange côté Symfony.")
    symbols: List[str] = Field(default_factory=list, description="Sélection explicite de symboles.")
    contracts_limit: Optional[int] = Field(default=None, ge=1, description="Sélection dynamique bornée (PY-004).")
    priority: int = Field(default=0, description="Ordre / priorité fonctionnelle.")

    @model_validator(mode="after")
    def _enforce_persistable_invariants(self) -> "SetCreate":
        assert_set_persistable(
            dry_run=self.dry_run, symbols=self.symbols, contracts_limit=self.contracts_limit
        )
        return self


# Seuls ces champs de ``SetUpdate`` correspondent à une colonne NULLABLE et
# acceptent donc un ``null`` explicite en PATCH (ex. effacer la limite).
_SET_NULLABLE_UPDATE_FIELDS = frozenset({"contracts_limit"})


class SetUpdate(BaseModel):
    """Mise à jour partielle d'un set.

    ``set_id`` est immuable (renommer = supprimer puis recréer) et ``payload``
    n'est pas modifiable par un client (cf. ``SetCreate``). Les invariants
    (interdiction live, sélection exploitable) sont revalidés sur l'état résultant
    côté router, car les champs ne sont que partiellement fournis.

    Un ``null`` explicite sur un champ adossé à une colonne NOT NULL est rejeté :
    sans cela ``{"exchange": null}`` casserait la fusion (et écraserait une
    colonne NOT NULL via un PATCH).
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

    @model_validator(mode="before")
    @classmethod
    def _forbid_null_overrides(cls, data: object) -> object:
        return _reject_explicit_nulls(
            data, fields=cls.model_fields, nullable=_SET_NULLABLE_UPDATE_FIELDS
        )


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
