"""Fixtures de test pour la couche DB (DB-001).

Les tests tournent sur **SQLite in-memory** (aucun PostgreSQL requis). Plutôt que
de neutraliser le schéma dédié ``orchestration`` (ce qui n'existe pas en
runtime), on **attache** une base in-memory portant ce nom : les modèles
tournent dans le *vrai* schéma, identique au runtime PostgreSQL. Le nom de schéma
est figé à l'import de ``app.db.base`` — on le force donc avant tout import.
"""

from __future__ import annotations

import os

# Doit être positionné avant tout import de app.db.* (schéma figé à l'import).
os.environ["ORCHESTRATION_DB_SCHEMA"] = "orchestration"

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
