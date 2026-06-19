"""Gestion des dashboards et des sets d'orchestration (PY-002).

CRUD REST câblé sur la couche DB (DB-001) :

- ``/dashboards``                          : création / liste ;
- ``/dashboards/{id}``                     : lecture / mise à jour / suppression ;
- ``/dashboards/{id}/sets``                : création / liste des sets ;
- ``/dashboards/{id}/sets/{set_id}``       : lecture / mise à jour / suppression ;
- ``/dashboards/{id}/refresh-contracts``   : refresh explicite des symboles (PY-003).

Le câblage de ces sets dans l'exécution parallèle de ``/orchestrator/run`` est
l'objet de PY-005 ; cette PR ne livre que la configuration (sets « prêts »).
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Dict, Iterator, Tuple

import httpx
from fastapi import APIRouter, Depends, HTTPException, Response, status
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.db import repositories as repo
from app.db.engine import get_session
from app.db.models import Dashboard, OrchestrationSet
from app.schemas import (
    Action,
    ContractRefreshResponse,
    ContractRefreshSetPreview,
    DashboardCreate,
    DashboardRead,
    DashboardUpdate,
    RunDetailRead,
    RunSummaryRead,
    SetCreate,
    SetRead,
    SetUpdate,
    assert_set_persistable,
)
from app.services import symfony_client
from app.settings import get_settings

router = APIRouter(prefix="/dashboards", tags=["dashboards"])


def _require_dashboard(session: Session, dashboard_id: int) -> Dashboard:
    dashboard = repo.get_dashboard(session, dashboard_id)
    if dashboard is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="dashboard not found")
    return dashboard


def _require_set(session: Session, dashboard_id: int, set_id: str) -> OrchestrationSet:
    a_set = repo.get_set(session, dashboard_id, set_id)
    if a_set is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="set not found")
    return a_set


@contextmanager
def _conflict_guard(session: Session, detail: str) -> Iterator[None]:
    """Transforme toute violation d'unicité en ``409 Conflict``.

    La violation peut survenir au ``flush()`` (dans le repo) **ou** au commit :
    on englobe donc la mutation et le commit, puis on rollback proprement.
    """
    try:
        yield
        session.commit()
    except IntegrityError:
        session.rollback()
        raise HTTPException(status.HTTP_409_CONFLICT, detail=detail)


# --- Dashboards -------------------------------------------------------------


@router.get("", response_model=list[DashboardRead])
def list_dashboards(session: Session = Depends(get_session)) -> list[Dashboard]:
    return list(repo.list_dashboards(session))


@router.post("", response_model=DashboardRead, status_code=status.HTTP_201_CREATED)
def create_dashboard(body: DashboardCreate, session: Session = Depends(get_session)) -> Dashboard:
    with _conflict_guard(session, detail=f"dashboard name '{body.name}' already exists"):
        dashboard = repo.create_dashboard(
            session, name=body.name, enabled=body.enabled, description=body.description
        )
    session.refresh(dashboard)
    return dashboard


@router.get("/{dashboard_id}", response_model=DashboardRead)
def get_dashboard(dashboard_id: int, session: Session = Depends(get_session)) -> Dashboard:
    return _require_dashboard(session, dashboard_id)


@router.patch("/{dashboard_id}", response_model=DashboardRead)
def update_dashboard(
    dashboard_id: int, body: DashboardUpdate, session: Session = Depends(get_session)
) -> Dashboard:
    dashboard = _require_dashboard(session, dashboard_id)
    with _conflict_guard(session, detail=f"dashboard name '{body.name}' already exists"):
        repo.update_dashboard(session, dashboard, fields=body.model_dump(exclude_unset=True))
    session.refresh(dashboard)
    return dashboard


@router.delete("/{dashboard_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_dashboard(dashboard_id: int, session: Session = Depends(get_session)) -> Response:
    dashboard = _require_dashboard(session, dashboard_id)
    repo.delete_dashboard(session, dashboard)
    session.commit()
    return Response(status_code=status.HTTP_204_NO_CONTENT)


# --- Sets -------------------------------------------------------------------


@router.get("/{dashboard_id}/sets", response_model=list[SetRead])
def list_sets(
    dashboard_id: int,
    enabled_only: bool = False,
    session: Session = Depends(get_session),
) -> list[OrchestrationSet]:
    _require_dashboard(session, dashboard_id)
    return list(repo.list_sets(session, dashboard_id, enabled_only=enabled_only))


@router.post(
    "/{dashboard_id}/sets", response_model=SetRead, status_code=status.HTTP_201_CREATED
)
def create_set(
    dashboard_id: int, body: SetCreate, session: Session = Depends(get_session)
) -> OrchestrationSet:
    _require_dashboard(session, dashboard_id)
    detail = f"set_id '{body.set_id}' already exists in dashboard {dashboard_id}"
    with _conflict_guard(session, detail=detail):
        a_set = repo.create_set(session, dashboard_id, fields=body.model_dump(mode="json"))
        # PY-004 : le payload /api/mtf/run est un artefact serveur dérivé des champs
        # typés ; on le (re)génère à chaque écriture pour qu'il ne soit jamais périmé.
        a_set.payload = symfony_client.generate_set_payload(a_set)
    session.refresh(a_set)
    return a_set


@router.get("/{dashboard_id}/sets/{set_id}", response_model=SetRead)
def get_set(
    dashboard_id: int, set_id: str, session: Session = Depends(get_session)
) -> OrchestrationSet:
    _require_dashboard(session, dashboard_id)
    return _require_set(session, dashboard_id, set_id)


@router.patch("/{dashboard_id}/sets/{set_id}", response_model=SetRead)
def update_set(
    dashboard_id: int, set_id: str, body: SetUpdate, session: Session = Depends(get_session)
) -> OrchestrationSet:
    _require_dashboard(session, dashboard_id)
    a_set = _require_set(session, dashboard_id, set_id)
    updates = body.model_dump(mode="json", exclude_unset=True)

    # Les invariants (interdiction live, sélection exploitable) s'appliquent à
    # l'état résultant : un PATCH partiel est fusionné avec la ligne persistée.
    effective_dry_run = updates.get("dry_run", a_set.dry_run)
    effective_symbols = updates.get("symbols", a_set.symbols)
    effective_limit = updates.get("contracts_limit", a_set.contracts_limit)
    try:
        assert_set_persistable(
            dry_run=effective_dry_run,
            symbols=effective_symbols,
            contracts_limit=effective_limit,
        )
    except ValueError as exc:
        # 422 littéral : la constante `status.HTTP_422_*` a été renommée selon
        # les versions de Starlette ; l'entier reste stable et non déprécié.
        raise HTTPException(422, detail=str(exc))

    repo.update_set(session, a_set, fields=updates)
    # PY-004 : régénère le payload depuis l'état résultant du set.
    a_set.payload = symfony_client.generate_set_payload(a_set)
    session.commit()
    session.refresh(a_set)
    return a_set


@router.delete(
    "/{dashboard_id}/sets/{set_id}", status_code=status.HTTP_204_NO_CONTENT
)
def delete_set(
    dashboard_id: int, set_id: str, session: Session = Depends(get_session)
) -> Response:
    _require_dashboard(session, dashboard_id)
    a_set = _require_set(session, dashboard_id, set_id)
    repo.delete_set(session, a_set)
    session.commit()
    return Response(status_code=status.HTTP_204_NO_CONTENT)


# --- Historique des runs du dashboard (PY-006) ------------------------------

# Borne la taille de page (alignée sur le router `runs`).
_MAX_RUNS_PAGE_SIZE = 100


@router.get("/{dashboard_id}/runs", response_model=list[RunSummaryRead])
def list_dashboard_runs(
    dashboard_id: int,
    limit: int = 20,
    offset: int = 0,
    session: Session = Depends(get_session),
) -> list:
    """Liste les runs d'un dashboard, du plus récent au plus ancien (vue allégée)."""
    _require_dashboard(session, dashboard_id)
    limit = max(1, min(limit, _MAX_RUNS_PAGE_SIZE))
    offset = max(0, offset)
    return list(
        repo.list_runs(session, dashboard_id=dashboard_id, limit=limit, offset=offset)
    )


@router.get("/{dashboard_id}/runs/latest", response_model=RunDetailRead)
def get_dashboard_latest_run(
    dashboard_id: int, session: Session = Depends(get_session)
) -> RunDetailRead:
    """Dernier run d'un dashboard : dernier JSON global + dernier JSON par set.

    C'est le retour que le cockpit affiche par défaut (« dernier run »). ``404``
    si le dashboard n'a encore aucun run persisté.
    """
    _require_dashboard(session, dashboard_id)
    run = repo.get_latest_run(session, dashboard_id=dashboard_id)
    if run is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="no run for this dashboard")
    return RunDetailRead.from_run(run, repo.list_run_sets(session, run.run_id))


# --- Refresh des contrats (PY-003) ------------------------------------------

# Clé de regroupement d'un fetch contrats : un appel Symfony par couple distinct
# (profil, exchange, market_type) — inutile de refetcher pour des sets identiques.
_ContractsKey = Tuple[str, str, str]

# Timeout (s) des appels Symfony pour le refresh, aligné sur l'orchestrateur.
_HTTP_TIMEOUT = 900.0


@router.post("/{dashboard_id}/refresh-contracts", response_model=ContractRefreshResponse)
async def refresh_contracts(
    dashboard_id: int, session: Session = Depends(get_session)
) -> ContractRefreshResponse:
    """Refresh explicite des symboles des sets `mtf_run` actifs d'un dashboard.

    Pour chaque couple distinct (profil, exchange, market_type), interroge UNE
    fois ``GET /api/mtf/contracts`` (Symfony = source de vérité de la sélection),
    puis écrit ``symbols`` sur chaque set — tronqué à ``contracts_limit`` si défini.

    **Fail-closed** : si le fetch d'un seul groupe échoue, on renvoie ``502`` sans
    AUCUNE écriture partielle (les sets restent dans leur état précédent). De même,
    si la sélection rafraîchie rendrait un set non exécutable (set non capé dont les
    ``symbols`` deviendraient vides), on renvoie ``409`` sans rien écrire. Toutes les
    écritures sont committées en une seule transaction à la fin.

    Hors scope (PY-004/PY-005) : génération du ``payload`` /api/mtf/run et exécution.
    """
    _require_dashboard(session, dashboard_id)
    settings = get_settings()

    mtf_sets = [
        s
        for s in repo.list_active_sets(session, dashboard_id)
        if s.action == Action.MTF_RUN.value
    ]

    # 1) Un seul fetch par couple distinct (profil, exchange, market_type).
    #    Tout échec interrompt AVANT la moindre écriture (fail-closed).
    cache: Dict[_ContractsKey, dict] = {}
    async with httpx.AsyncClient(timeout=_HTTP_TIMEOUT) as client:
        for a_set in mtf_sets:
            key: _ContractsKey = (a_set.mtf_profile, a_set.exchange, a_set.market_type)
            if key in cache:
                continue
            try:
                cache[key] = await symfony_client.fetch_selected_contracts(
                    client,
                    settings.symfony_base_url,
                    a_set.mtf_profile,
                    a_set.exchange,
                    a_set.market_type,
                )
            except symfony_client.ContractsUnavailableError as exc:
                raise HTTPException(
                    status.HTTP_502_BAD_GATEWAY,
                    detail=f"refresh contrats indisponible: {exc}",
                )

    # 2) Validation préalable (AUCUNE écriture) : un set non capé
    #    (`contracts_limit is None`) dont la sélection rafraîchie serait vide
    #    deviendrait ambigu/non exécutable — `assert_set_persistable` le refuse. On
    #    échoue tout le refresh (fail-closed, atomique) plutôt que de persister cet
    #    état. Un set capé tolère `[]` : sa `contracts_limit` le garde persistable et
    #    `generate_set_payload` renvoie alors `null` (pas de payload « run-all »
    #    trompeur tant que la sélection n'est pas matérialisée).
    planned: list[tuple[OrchestrationSet, list, dict]] = []
    for a_set in mtf_sets:
        fetched = cache[(a_set.mtf_profile, a_set.exchange, a_set.market_type)]
        # `[:None]` = liste complète ; `[:N]` cape la sélection dynamique.
        symbols = list(fetched["symbols"][: a_set.contracts_limit])
        try:
            assert_set_persistable(
                dry_run=a_set.dry_run,
                symbols=symbols,
                contracts_limit=a_set.contracts_limit,
            )
        except ValueError as exc:
            raise HTTPException(
                status.HTTP_409_CONFLICT,
                detail=f"refresh produirait un set non exécutable ({a_set.set_id}): {exc}",
            )
        planned.append((a_set, symbols, fetched))

    # 3) Écriture + commit en une seule transaction (tous les sets validés).
    previews: list[ContractRefreshSetPreview] = []
    for a_set, symbols, fetched in planned:
        repo.update_set(session, a_set, fields={"symbols": symbols})
        # PY-004 : le payload reflète la sélection rafraîchie.
        a_set.payload = symfony_client.generate_set_payload(a_set)
        previews.append(
            ContractRefreshSetPreview(
                set_id=a_set.set_id,
                mtf_profile=a_set.mtf_profile,
                exchange=a_set.exchange,
                market_type=a_set.market_type,
                symbol_count=len(symbols),
                contracts_limit=a_set.contracts_limit,
                filters=fetched["filters"],
            )
        )

    session.commit()

    return ContractRefreshResponse(
        dashboard_id=dashboard_id, count=len(previews), sets=previews
    )
