"""Fixtures de test pour la couche DB (DB-001).

Les tests tournent sur **SQLite in-memory** (aucun PostgreSQL requis). Le schéma
dédié ``orchestration`` n'existe pas sous SQLite : on le neutralise en
positionnant ``ORCHESTRATION_DB_SCHEMA=none`` **avant** l'import des modèles,
puisque le schéma est figé à l'import de ``app.db.base``.
"""

from __future__ import annotations

import os

# Doit être positionné avant tout import de app.db.* (schéma figé à l'import).
# Forcé (et non setdefault) pour rester sur SQLite même si la CI exporte un schéma.
os.environ["ORCHESTRATION_DB_SCHEMA"] = "none"

import pytest
from sqlalchemy import create_engine, event
from sqlalchemy.orm import Session, sessionmaker


@pytest.fixture()
def db_session():
    """Session SQLite in-memory avec le schéma orchestration créé à la volée."""
    from app.db.base import Base
    from app.db import models  # noqa: F401  (enregistre les tables)

    engine = create_engine("sqlite://", future=True)

    # SQLite n'applique pas les FK par défaut : on les active pour tester les
    # ON DELETE CASCADE / SET NULL définis sur les tables.
    @event.listens_for(engine, "connect")
    def _enable_sqlite_fk(dbapi_connection, _record):  # noqa: ANN001
        cursor = dbapi_connection.cursor()
        cursor.execute("PRAGMA foreign_keys=ON")
        cursor.close()

    Base.metadata.create_all(engine)
    factory = sessionmaker(bind=engine, expire_on_commit=False)
    session: Session = factory()
    try:
        yield session
    finally:
        session.close()
        Base.metadata.drop_all(engine)
        engine.dispose()
