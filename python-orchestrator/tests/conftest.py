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

from app.db.base import SCHEMA


@pytest.fixture()
def db_session():
    """Session SQLite in-memory avec le schéma orchestration attaché."""
    from app.db.base import Base
    from app.db import models  # noqa: F401  (enregistre les tables)

    engine = create_engine("sqlite://", future=True)

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

    Base.metadata.create_all(engine)
    factory = sessionmaker(bind=engine, expire_on_commit=False)
    session: Session = factory()
    try:
        yield session
    finally:
        session.close()
        engine.dispose()
