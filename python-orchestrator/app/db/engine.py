"""Moteur SQLAlchemy et fabrique de sessions (DB-001).

Création **paresseuse** : importer ce module n'ouvre aucune connexion. Le moteur
n'est instancié qu'au premier appel à ``get_engine()`` / ``get_sessionmaker()``,
si bien que l'application, le ``/healthcheck`` et les tests démarrent sans
PostgreSQL. Le câblage FastAPI réel (dépendance ``get_session``) sera consommé
par PY-002.
"""

from __future__ import annotations

from typing import Iterator, Optional

from sqlalchemy import Engine, create_engine
from sqlalchemy.orm import Session, sessionmaker

from app.settings import Settings, get_settings

_engine: Optional[Engine] = None
_session_factory: Optional[sessionmaker[Session]] = None


def get_engine(settings: Optional[Settings] = None) -> Engine:
    """Retourne le moteur partagé, en le créant au premier appel."""
    global _engine
    if _engine is None:
        settings = settings or get_settings()
        # pool_pre_ping : évite les connexions mortes après un idle long.
        _engine = create_engine(settings.database_url, pool_pre_ping=True, future=True)
    return _engine


def get_sessionmaker(settings: Optional[Settings] = None) -> sessionmaker[Session]:
    """Retourne la fabrique de sessions partagée."""
    global _session_factory
    if _session_factory is None:
        _session_factory = sessionmaker(
            bind=get_engine(settings), autoflush=False, expire_on_commit=False
        )
    return _session_factory


def get_session() -> Iterator[Session]:
    """Dépendance FastAPI : ouvre une session et la ferme en fin de requête (PY-002)."""
    factory = get_sessionmaker()
    session = factory()
    try:
        yield session
    finally:
        session.close()


def reset_engine() -> None:
    """Réinitialise le moteur/fabrique (utile pour les tests)."""
    global _engine, _session_factory
    if _engine is not None:
        _engine.dispose()
    _engine = None
    _session_factory = None
