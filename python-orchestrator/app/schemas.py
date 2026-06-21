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

from pydantic import BaseModel, ConfigDict, Field, computed_field, model_validator

from app import __version__
from app.services.live_guard import (
    PERMANENT_LIVE_FORBIDDEN_EXCHANGES,
    assess_live,
    is_permanently_forbidden,
)

# Nombre maximum de workers Symfony autorisés par set. La cible impose
# `workers=1` au début ; on relèvera cette borne dans une PR dédiée.
MAX_WORKERS_PER_SET = 1

# Un ``set_id`` est adressé en URL (``/dashboards/{id}/sets/{set_id}``,
# ``/runs/{run_id}/sets/{set_id}``) : il doit être un segment de chemin sûr pour
# rester récupérable. On le restreint aux caractères d'un identifiant usuel.
_SET_ID_PATTERN = r"^[A-Za-z0-9_.\-]+$"


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


# Exchanges interdits live **en permanence** (OKX/Hyperliquid, cf.
# exchange-schedule-policy.md). Dérivé de la source **unique**
# ``live_guard.PERMANENT_LIVE_FORBIDDEN_EXCHANGES`` (SAFE-003) : plus de liste
# dupliquée entre schemas et runner. Conservé sous forme d'enums pour les usages
# typés historiques.
LIVE_FORBIDDEN_EXCHANGES = frozenset(
    e for e in Exchange if e.value in PERMANENT_LIVE_FORBIDDEN_EXCHANGES
)

# Statuts de run centralisés (SAFE-002). ``running`` est un statut **non terminal**
# (claim « en vol » posé au démarrage du run) ; ``no_sets`` n'est jamais persisté.
# Les statuts terminaux sont dérivés par ``_resolve_status`` à la finalisation.
RUN_STATUS_SUCCESS = "success"
RUN_STATUS_PARTIAL_FAILURE = "partial_failure"
RUN_STATUS_FAILED = "failed"
RUN_STATUS_NO_SETS = "no_sets"
RUN_STATUS_RUNNING = "running"

# Statuts terminaux d'un run réellement exécuté (au moins un set). Sert au
# court-circuit d'idempotence (SAFE-002) : un run terminal success est rejoué
# (replay), un terminal non-ok est repris (reprise des sets non réussis).
TERMINAL_RUN_STATUSES = frozenset(
    {RUN_STATUS_SUCCESS, RUN_STATUS_PARTIAL_FAILURE, RUN_STATUS_FAILED}
)

RunStatus = Literal["success", "partial_failure", "failed", "no_sets", "running"]


def assert_live_allowed(exchange: Exchange, dry_run: bool) -> None:
    """Garde-fou live des ``OrchestratorSet`` en mémoire : OKX/Hyperliquid interdits.

    Utilisé par ``OrchestratorSet`` (sets simulés en mémoire, non persistés). Délègue
    la connaissance des exchanges bannis à ``live_guard.is_permanently_forbidden``
    (source **unique** SAFE-003) : seuls les bannissements **permanents** s'appliquent
    ici. Le verrou d'activation live (interrupteur + allow-list) est appliqué à la
    persistance (``assert_set_persistable``) et au runtime (runner), pas sur l'objet
    en mémoire. Lève ``ValueError`` (mappé en 422 côté API).
    """
    if dry_run is False and is_permanently_forbidden(exchange):
        raise ValueError(
            f"{exchange.value} live est interdit : dry_run doit rester true."
        )


def assert_set_persistable(
    *,
    dry_run: bool,
    symbols: list,
    contracts_limit: Optional[int],
    exchange: object,
    market_type: object = None,
    environment: object = None,
) -> None:
    """Invariants d'un set **persisté** via l'API de configuration (PY-002).

    1. Cohérence persistance ↔ runtime (SAFE-003) : un set ``dry_run=false`` n'est
       persistable que si ``live_guard.assess_live`` l'autorise — **exactement** les
       gardes appliquées par le runner au dispatch. Une ligne stockée ne peut donc
       jamais déclencher un live que le runner refuserait (et inversement). Par
       défaut (interrupteur OFF), tout ``dry_run=false`` reste refusé, comme avant
       SAFE-003.
    2. Pas de set ambigu : il faut une sélection exploitable — soit ``symbols``
       non vide, soit ``contracts_limit`` renseigné — sinon PY-005 devrait
       deviner quoi exécuter.

    Lève ``ValueError`` (mappé en 422 côté API).
    """
    if dry_run is False:
        # Import différé de `get_settings` : la politique live lit l'environnement
        # courant (interrupteur + allow-list) sans coupler le module au runtime.
        from app.settings import get_settings

        decision = assess_live(
            exchange=exchange,
            market_type=market_type,
            environment=environment,
            dry_run=False,
            settings=get_settings(),
        )
        if not decision.allowed:
            raise ValueError(
                decision.reason
                or "live non autorisé pour la persistance (fail-closed)."
            )
    # On normalise les symboles (trim + écarte les vides/blancs) comme le fait
    # `generate_set_payload` au dispatch : une sélection `symbols=[" "]` se réduit à
    # vide côté exécution (Symfony trim/filtre, ce qui vaudrait « tout l'univers »).
    # Sans ce nettoyage ici, un tel set passe la validation API mais reste
    # « not materialized » à chaque run au lieu d'être rejeté à la création.
    cleaned_symbols = [s.strip() for s in (symbols or []) if isinstance(s, str) and s.strip()]
    if not cleaned_symbols and contracts_limit is None:
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
# Lecture de l'historique des runs (PY-006)
#
# Schémas de sortie des endpoints GET en lecture seule. L'écriture de cet
# historique est faite par PY-005 ; ici on n'expose que le « dernier JSON »
# global d'un run (``last_json``) et par set (``payload_sent`` /
# ``response_json``) pour le cockpit.
# ---------------------------------------------------------------------------


class RunSetRead(BaseModel):
    """Dernier JSON par set : payload envoyé, réponse Symfony brute, erreur."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    run_id: str
    set_id: str
    # Lien optionnel vers le set persistant (rompu si le set a été supprimé).
    set_ref_id: Optional[int] = None
    ok: bool
    error: Optional[str] = None
    duration_ms: Optional[int] = None
    payload_sent: Optional[dict] = None
    response_json: Optional[dict] = None
    created_at: datetime


class RunSummaryRead(BaseModel):
    """Vue allégée d'un run pour les listes (sans ``last_json`` ni détail par set)."""

    model_config = ConfigDict(from_attributes=True)

    run_id: str
    # Nullable : ON DELETE SET NULL conserve le run même si le dashboard disparaît.
    dashboard_id: Optional[int] = None
    ok: bool
    # `status` persisté (success / partial_failure / failed) ; `no_sets` n'est jamais persisté.
    status: str
    idempotency_key: Optional[str] = None
    total_calls: int
    success_count: int
    failed_count: int
    started_at: Optional[datetime] = None
    finished_at: Optional[datetime] = None
    created_at: datetime


class RunDetailRead(RunSummaryRead):
    """Détail complet d'un run : dernier JSON global + dernier JSON par set."""

    last_json: Optional[dict] = None
    sets: List[RunSetRead] = Field(default_factory=list)

    @classmethod
    def from_run(cls, run: object, run_sets: object) -> "RunDetailRead":
        """Assemble le détail depuis un ``Run`` ORM et ses ``RunSet``.

        Construction explicite (plutôt que ``model_validate(run)``) car le champ
        ``sets`` ne correspond pas à l'attribut ORM ``run_sets`` : on injecte la
        liste fournie par l'appelant (ordre déterministe via le repository).
        """
        return cls(
            run_id=run.run_id,
            dashboard_id=run.dashboard_id,
            ok=run.ok,
            status=run.status,
            idempotency_key=run.idempotency_key,
            total_calls=run.total_calls,
            success_count=run.success_count,
            failed_count=run.failed_count,
            started_at=run.started_at,
            finished_at=run.finished_at,
            created_at=run.created_at,
            last_json=run.last_json,
            sets=[RunSetRead.model_validate(rs) for rs in run_sets],
        )


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
    (unique via ``uq_orchestration_sets_dashboard_set``). Il est recopié tel quel
    dans ``RunSet.set_id`` et adressé en URL (``GET /dashboards/{id}/sets/{set_id}``,
    ``GET /runs/{run_id}/sets/{set_id}`` — PY-006), donc restreint à un **segment
    de chemin sûr** (alphanumérique + ``_``/``.``/``-``) : un ``set_id`` porteur de
    ``/`` serait stocké mais non récupérable (les routes à segment simple ne
    matchent pas les slashes). On rejette plutôt que de sanitiser, car c'est un
    identifiant choisi par l'utilisateur, immuable et référencé dans sa config.

    ``payload`` n'est **pas** accepté ici : c'est un artefact produit côté serveur
    (PY-004) à partir des champs typés ; un client REST ne doit pas pouvoir
    persister un payload incohérent avec ``exchange`` / ``mtf_profile`` /
    ``symbols``. Il reste exposé en lecture seule via ``SetRead``.
    """

    set_id: str = Field(
        ...,
        min_length=1,
        max_length=255,
        pattern=_SET_ID_PATTERN,
        description="Identifiant stable du set (segment d'URL : alphanumérique, '_', '.', '-').",
    )
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
            dry_run=self.dry_run,
            symbols=self.symbols,
            contracts_limit=self.contracts_limit,
            exchange=self.exchange,
            market_type=self.market_type,
            environment=self.environment,
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

    @computed_field  # type: ignore[prop-decorator]
    @property
    def effective_payload(self) -> Optional[dict]:
        """Payload ``/api/mtf/run`` réellement envoyé pour ce set (PY-007).

        Champ calculé en lecture seule, exposé pour que le cockpit (preview UI-002)
        affiche LE payload effectif plutôt que de le reconstruire côté front. Délègue
        à la fonction canonique ``effective_set_payload`` — exactement celle qu'utilise
        ``run_persisted_set`` au dispatch (forme + clamp ``workers``), **hors** couche
        runtime (``open_state_snapshot`` et override ``dry_run`` run-level). Garantit
        donc l'identité preview ⇆ envoi réel, sans second chemin de construction.

        ``null`` quand la sélection n'est pas matérialisée (symbols vide/blanc),
        comme ``generate_set_payload`` : le front en déduit « set non matérialisé ».

        Le champ persisté ``payload`` (PY-004) reste exposé tel quel ; ``effective_
        payload`` y ajoute le clamp ``workers`` appliqué au dispatch et tolère les
        enums du schéma comme les chaînes ORM.

        Import différé pour éviter le cycle ``schemas`` ⇆ ``services.symfony_client``
        (ce dernier importe déjà ``MAX_WORKERS_PER_SET``/``OrchestratorSet`` d'ici).
        """
        from app.services.symfony_client import effective_set_payload

        return effective_set_payload(self)


# ---------------------------------------------------------------------------
# Refresh explicite des contrats (PY-003)
#
# Schémas de la réponse de ``POST /dashboards/{id}/refresh-contracts`` : pour
# chaque set actif `mtf_run`, on renvoie un aperçu (combien de symboles ont été
# persistés, sous quels filtres). La génération des payloads /api/mtf/run reste
# l'objet de PY-004 ; ici on ne touche qu'aux ``symbols``.
# ---------------------------------------------------------------------------


class ContractRefreshSetPreview(BaseModel):
    """Aperçu du refresh pour un set : combien de symboles, sous quels filtres."""

    set_id: str
    mtf_profile: MtfProfile
    exchange: Exchange
    market_type: MarketType
    symbol_count: int
    # Cap appliqué (None = sélection complète du profil).
    contracts_limit: Optional[int] = None
    # Filtres `mtf_contracts` renvoyés par Symfony (informatif pour le front).
    filters: dict = Field(default_factory=dict)


class ContractRefreshResponse(BaseModel):
    """Réponse de ``POST /dashboards/{id}/refresh-contracts``."""

    dashboard_id: int
    # Nombre de sets rafraîchis (sets actifs `mtf_run` du dashboard).
    count: int
    sets: List[ContractRefreshSetPreview]
