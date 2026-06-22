"""Fixtures de test pour la couche DB (DB-001) et l'intégration HTTP (QA-002).

Les tests tournent sur **SQLite in-memory** (aucun PostgreSQL requis). Plutôt que
de neutraliser le schéma dédié ``orchestration`` (ce qui n'existe pas en
runtime), on **attache** une base in-memory portant ce nom : les modèles
tournent dans le *vrai* schéma, identique au runtime PostgreSQL. Le nom de schéma
est figé à l'import de ``app.db.base`` — on le force donc avant tout import.

QA-002 ajoute en bas de ce module un **faux Symfony à la frontière HTTP**
(``FakeSymfony`` + fixture ``symfony``) : un ``httpx.MockTransport`` scénarisé qui
rejoue ``/api/mtf/contracts``, ``/api/mtf/run`` et ``/api/exchange/open-state`` au
travers d'un VRAI ``httpx.AsyncClient`` (sérialisation/parsing JSON, en-têtes,
codes HTTP réels), sans aucune socket ni backend Symfony/PostgreSQL applicatif.
"""

from __future__ import annotations

import asyncio
import json as _json
import os
from typing import Any, Callable, Dict, List, Optional

# Doit être positionné avant tout import de app.db.* (schéma figé à l'import).
os.environ["ORCHESTRATION_DB_SCHEMA"] = "orchestration"

import httpx
import pytest
from sqlalchemy import create_engine, event
from sqlalchemy.orm import Session, sessionmaker
from sqlalchemy.pool import StaticPool

from app.db.base import SCHEMA


def _attach_schema_and_fks(engine) -> None:
    """Active les FK SQLite et attache une base in-memory portant le schéma dédié."""

    @event.listens_for(engine, "connect")
    def _prepare_sqlite(dbapi_connection, _record):  # noqa: ANN001
        cursor = dbapi_connection.cursor()
        # SQLite n'applique pas les FK par défaut : on les active pour tester les
        # ON DELETE CASCADE / SET NULL.
        cursor.execute("PRAGMA foreign_keys=ON")
        # Schéma dédié : on attache une base in-memory du même nom pour que les
        # tables `orchestration.*` soient résolues comme en PostgreSQL.
        cursor.execute(f"ATTACH DATABASE ':memory:' AS {SCHEMA}")
        cursor.close()


@pytest.fixture()
def audit_records():
    """Capture les ``LogRecord`` d'audit ``orchestrator.audit`` (OBS-001).

    Attache un handler de test **directement** au logger d'audit (sa propagation
    est coupée en prod pour éviter la double émission, donc ``caplog`` — branché
    sur le root — ne le verrait pas). Les tests lisent ``record.event`` /
    ``record.run_id`` / ``record.audit`` plutôt que le format brut.
    """
    import logging

    from app.services.run_audit import AUDIT_LOGGER_NAME

    logger = logging.getLogger(AUDIT_LOGGER_NAME)
    records: list = []

    class _Recorder(logging.Handler):
        def emit(self, record: logging.LogRecord) -> None:
            records.append(record)

    handler = _Recorder()
    handler.setLevel(logging.DEBUG)
    previous_level = logger.level
    previous_disabled = logger.disabled
    logger.setLevel(logging.DEBUG)
    # Un `fileConfig` exécuté par un test antérieur (ex. Alembic dans le smoke
    # PostgreSQL) a pu désactiver ce logger : on le réactive pour capturer.
    logger.disabled = False
    logger.addHandler(handler)
    try:
        yield records
    finally:
        logger.removeHandler(handler)
        logger.setLevel(previous_level)
        logger.disabled = previous_disabled


@pytest.fixture()
def db_session():
    """Session SQLite in-memory avec le schéma orchestration attaché."""
    from app.db.base import Base
    from app.db import models  # noqa: F401  (enregistre les tables)

    engine = create_engine("sqlite://", future=True)
    _attach_schema_and_fks(engine)

    Base.metadata.create_all(engine)
    factory = sessionmaker(bind=engine, expire_on_commit=False)
    session: Session = factory()
    try:
        yield session
    finally:
        session.close()
        engine.dispose()


@pytest.fixture()
def api_client():
    """``TestClient`` câblé sur une DB SQLite in-memory partagée (PY-002).

    ``StaticPool`` + une unique connexion partagée garantissent que toutes les
    requêtes (potentiellement servies dans un thread du pool anyio) voient la
    même base in-memory. La dépendance ``get_session`` est surchargée : aucun
    PostgreSQL n'est requis.
    """
    from fastapi.testclient import TestClient

    from app.db.base import Base
    from app.db import models  # noqa: F401  (enregistre les tables)
    from app.db.engine import get_session
    from app.main import app

    engine = create_engine(
        "sqlite://",
        future=True,
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    _attach_schema_and_fks(engine)
    Base.metadata.create_all(engine)
    factory = sessionmaker(bind=engine, expire_on_commit=False)

    def _override_get_session():
        session = factory()
        try:
            yield session
        finally:
            session.close()

    app.dependency_overrides[get_session] = _override_get_session
    try:
        yield TestClient(app)
    finally:
        app.dependency_overrides.pop(get_session, None)
        engine.dispose()


@pytest.fixture()
def orchestrator_env():
    """``(TestClient, Session)`` sur un **même engine** SQLite in-memory (PY-005).

    Permet de seeder des dashboards/sets en direct ORM puis de relire les ``Run``/
    ``RunSet`` écrits par ``/orchestrator/run``. ``StaticPool`` + connexion unique
    partagée garantissent que la session de seed et celles des requêtes voient la
    même base. ``get_session`` est surchargée : aucun PostgreSQL requis.
    """
    from fastapi.testclient import TestClient

    from app.db.base import Base
    from app.db import models  # noqa: F401  (enregistre les tables)
    from app.db.engine import get_session
    from app.main import app

    engine = create_engine(
        "sqlite://",
        future=True,
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    _attach_schema_and_fks(engine)
    Base.metadata.create_all(engine)
    factory = sessionmaker(bind=engine, expire_on_commit=False)

    def _override_get_session():
        session = factory()
        try:
            yield session
        finally:
            session.close()

    app.dependency_overrides[get_session] = _override_get_session
    seed_session = factory()
    try:
        yield TestClient(app), seed_session
    finally:
        seed_session.close()
        app.dependency_overrides.pop(get_session, None)
        engine.dispose()


# ===========================================================================
# QA-002 — Faux Symfony à la frontière HTTP (httpx.MockTransport scénarisé)
# ===========================================================================


def _parse_json(content: bytes) -> Any:
    """Décode le corps d'une requête sortante en JSON, ou ``None`` si vide/illisible."""
    if not content:
        return None
    try:
        return _json.loads(content)
    except ValueError:
        return None


class FakeSymfony:
    """Simule Symfony à la frontière HTTP via ``httpx.MockTransport`` (QA-002).

    Rejoue les trois endpoints réellement appelés par l'orchestrateur —
    ``GET /api/mtf/contracts`` (PY-003), ``POST /api/mtf/run`` (PY-005) et
    ``GET /api/exchange/open-state`` (SF-002b) — en passant par un **vrai**
    ``httpx.AsyncClient`` (sérialisation/parsing JSON, en-têtes, codes HTTP réels)
    plutôt qu'un double du client. Aucune socket, aucun backend applicatif : tout
    transite par un transport en mémoire.

    Chaque requête sortante est **capturée** (méthode, chemin, params, corps JSON,
    en-têtes) dans ``requests`` et dans la liste dédiée à l'endpoint, pour asserter
    le contrat EXACT (forme du payload, ``X-Run-Id``...).

    Réponses configurables par endpoint, nominales ET dégradées :

    - ``open_state`` / ``open_state_status`` : corps & code HTTP de
      ``/api/exchange/open-state`` ;
    - ``run_response`` / ``run_status`` : corps & code HTTP de ``/api/mtf/run`` ;
    - ``contracts`` / ``contracts_status`` : corps & code HTTP de
      ``/api/mtf/contracts`` ;
    - ``*_raise=True`` : lève une ``httpx.HTTPError`` (timeout/connexion) AVANT toute
      réponse, pour exercer les branches d'erreur transport du client ;
    - ``*_raw`` (bytes) : renvoie un corps **non-JSON** annoncé ``application/json``
      (payload malformé) ;
    - ``run_handler`` : callable ``(request, body_json) -> (status, body)`` pour des
      réponses par requête (ex. échec ciblé par symbole).

    ``max_in_flight`` enregistre le **parallélisme maximal observé** sur
    ``/api/mtf/run`` : chaque handler cède plusieurs fois la main (``asyncio.sleep(0)``)
    pendant qu'il est « en vol », ce qui rend le bornage par ``MAX_CONCURRENCY``
    observable de façon déterministe (sans horloge réelle).
    """

    DEFAULT_OPEN_STATE: Dict[str, Any] = {"open_positions": [], "open_orders": []}
    DEFAULT_RUN: Dict[str, Any] = {"status": "success"}
    DEFAULT_CONTRACTS: Dict[str, Any] = {
        "ok": True,
        "profile": "scalper_micro",
        "exchange": "bitmart",
        "market_type": "perpetual",
        "count": 2,
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "filters": {"quote_currency": "USDT", "top_n": 140},
    }

    def __init__(
        self,
        *,
        open_state: Optional[Dict[str, Any]] = None,
        open_state_status: int = 200,
        open_state_raise: bool = False,
        open_state_raw: Optional[bytes] = None,
        run_response: Optional[Dict[str, Any]] = None,
        run_status: int = 200,
        run_raise: bool = False,
        run_raw: Optional[bytes] = None,
        run_handler: Optional[Callable[[httpx.Request, Any], Any]] = None,
        contracts: Optional[Dict[str, Any]] = None,
        contracts_status: int = 200,
        contracts_raise: bool = False,
        contracts_raw: Optional[bytes] = None,
    ) -> None:
        self._open_state = open_state if open_state is not None else dict(self.DEFAULT_OPEN_STATE)
        self._open_state_status = open_state_status
        self._open_state_raise = open_state_raise
        self._open_state_raw = open_state_raw
        self._run_response = run_response if run_response is not None else dict(self.DEFAULT_RUN)
        self._run_status = run_status
        self._run_raise = run_raise
        self._run_raw = run_raw
        self._run_handler = run_handler
        self._contracts = contracts if contracts is not None else dict(self.DEFAULT_CONTRACTS)
        self._contracts_status = contracts_status
        self._contracts_raise = contracts_raise
        self._contracts_raw = contracts_raw

        self.requests: List[Dict[str, Any]] = []
        self.open_state_requests: List[Dict[str, Any]] = []
        self.run_requests: List[Dict[str, Any]] = []
        self.contracts_requests: List[Dict[str, Any]] = []
        self._in_flight = 0
        self.max_in_flight = 0
        # Classe ``AsyncClient`` GÉNUINE. Le runner importe ``httpx`` comme module et
        # la fixture patche ``httpx.AsyncClient`` dessus ; sans cette capture, rappeler
        # ``httpx.AsyncClient`` ici rebouclerait sur le patch (récursion infinie).
        self._async_client_cls = httpx.AsyncClient

    # --- câblage client -----------------------------------------------------

    def new_client(self, **_: Any) -> httpx.AsyncClient:
        """Construit un ``httpx.AsyncClient`` adossé au transport scénarisé.

        Les kwargs réels (``timeout=...``) passés par l'orchestrateur sont ignorés :
        seul le transport en mémoire compte. Un client neuf par run convient (le
        journal de requêtes et le compteur de parallélisme vivent sur l'instance).
        """
        return self._async_client_cls(transport=httpx.MockTransport(self._handle))

    # --- handler ------------------------------------------------------------

    @staticmethod
    def _record(request: httpx.Request) -> Dict[str, Any]:
        return {
            "method": request.method,
            "path": request.url.path,
            "params": dict(request.url.params),
            "headers": dict(request.headers),
            "json": _parse_json(request.content),
        }

    async def _handle(self, request: httpx.Request) -> httpx.Response:
        record = self._record(request)
        self.requests.append(record)
        path = request.url.path
        if path == "/api/exchange/open-state":
            return self._handle_open_state(request, record)
        if path == "/api/mtf/contracts":
            return self._handle_contracts(request, record)
        if path == "/api/mtf/run":
            return await self._handle_run(request, record)
        raise AssertionError(f"FakeSymfony: endpoint inattendu {request.method} {path}")

    def _handle_open_state(self, request: httpx.Request, record: Dict[str, Any]) -> httpx.Response:
        self.open_state_requests.append(record)
        if self._open_state_raise:
            raise httpx.ReadTimeout("simulated open-state timeout", request=request)
        if self._open_state_raw is not None:
            return httpx.Response(
                self._open_state_status,
                content=self._open_state_raw,
                headers={"content-type": "application/json"},
            )
        return httpx.Response(self._open_state_status, json=self._open_state)

    def _handle_contracts(self, request: httpx.Request, record: Dict[str, Any]) -> httpx.Response:
        self.contracts_requests.append(record)
        if self._contracts_raise:
            raise httpx.ConnectError("simulated contracts connect error", request=request)
        if self._contracts_raw is not None:
            return httpx.Response(
                self._contracts_status,
                content=self._contracts_raw,
                headers={"content-type": "application/json"},
            )
        return httpx.Response(self._contracts_status, json=self._contracts)

    async def _handle_run(self, request: httpx.Request, record: Dict[str, Any]) -> httpx.Response:
        self.run_requests.append(record)
        # Marque le set « en vol » et cède la main plusieurs fois : si le runner
        # dispatche en parallèle (jusqu'à MAX_CONCURRENCY), plusieurs handlers se
        # chevauchent ici et `max_in_flight` capte le parallélisme réel.
        self._in_flight += 1
        self.max_in_flight = max(self.max_in_flight, self._in_flight)
        try:
            for _ in range(5):
                await asyncio.sleep(0)
            if self._run_raise:
                raise httpx.ReadTimeout("simulated mtf/run timeout", request=request)
            if self._run_handler is not None:
                status, body = self._run_handler(request, record["json"])
                return httpx.Response(status, json=body)
            if self._run_raw is not None:
                return httpx.Response(
                    self._run_status,
                    content=self._run_raw,
                    headers={"content-type": "application/json"},
                )
            return httpx.Response(self._run_status, json=self._run_response)
        finally:
            self._in_flight -= 1


@pytest.fixture()
def symfony(monkeypatch):
    """Fabrique + câble un ``FakeSymfony`` à la frontière HTTP du runner (QA-002).

    Retourne une fonction ``build(**config) -> FakeSymfony`` qui construit le stub
    ET le branche comme ``httpx.AsyncClient`` de l'orchestrateur
    (``orch.httpx.AsyncClient``). Le runner exerce alors un VRAI client httpx via
    ``MockTransport`` : aucune dépendance réseau, mais sérialisation/parsing/headers
    réels. Plusieurs runs (idempotence) réutilisent le même stub (journal partagé).
    """
    from app.routers import orchestrator as orch

    built: Dict[str, FakeSymfony] = {}

    def _build(**config: Any) -> FakeSymfony:
        fake = FakeSymfony(**config)
        monkeypatch.setattr(orch.httpx, "AsyncClient", lambda **kw: fake.new_client(**kw))
        built["fake"] = fake
        return fake

    return _build
