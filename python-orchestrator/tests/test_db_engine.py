"""Tests unitaires du moteur SQLAlchemy paresseux (QA-001, cf. DB-001).

Aucun PostgreSQL : on force ``database_url`` sur SQLite in-memory via un
``Settings`` injecté, de sorte que ``get_engine`` / ``get_sessionmaker`` /
``get_session`` / ``reset_engine`` soient exercés sans I/O réseau. Les globals du
module étant partagés, chaque test repart d'un état propre (``reset_engine``).
"""

from __future__ import annotations

import pytest
from sqlalchemy import Engine
from sqlalchemy.orm import Session, sessionmaker

from app.db import engine as engine_module
from app.settings import Settings


@pytest.fixture()
def sqlite_settings() -> Settings:
    """Paramètres pointant sur une base SQLite in-memory (aucun PostgreSQL)."""
    return Settings(database_url="sqlite://")


@pytest.fixture(autouse=True)
def _reset_engine():
    """Garantit l'absence de moteur résiduel avant/après chaque test."""
    engine_module.reset_engine()
    yield
    engine_module.reset_engine()


def test_get_engine_is_lazy_and_singleton(sqlite_settings: Settings) -> None:
    # Aucun moteur tant qu'on n'appelle pas get_engine (création paresseuse).
    assert engine_module._engine is None

    first = engine_module.get_engine(sqlite_settings)
    assert isinstance(first, Engine)

    # Deuxième appel : même instance (singleton de module), settings ignorés.
    second = engine_module.get_engine(Settings(database_url="sqlite://"))
    assert first is second


def test_get_engine_defaults_to_get_settings(monkeypatch) -> None:
    # settings=None → repli sur get_settings(), qu'on stubbe pour rester offline.
    monkeypatch.setattr(
        engine_module, "get_settings", lambda: Settings(database_url="sqlite://")
    )
    eng = engine_module.get_engine()
    assert isinstance(eng, Engine)


def test_get_sessionmaker_is_singleton(sqlite_settings: Settings) -> None:
    first = engine_module.get_sessionmaker(sqlite_settings)
    assert isinstance(first, sessionmaker)

    second = engine_module.get_sessionmaker(sqlite_settings)
    assert first is second
    # La fabrique est bien liée au moteur partagé.
    assert engine_module.get_sessionmaker(sqlite_settings).kw["bind"] is engine_module.get_engine(
        sqlite_settings
    )


def test_get_session_yields_and_closes(sqlite_settings: Settings) -> None:
    # Pré-amorce le moteur SQLite pour que get_session (sans settings) ne tente
    # jamais d'ouvrir PostgreSQL.
    engine_module.get_engine(sqlite_settings)

    gen = engine_module.get_session()
    session = next(gen)
    assert isinstance(session, Session)
    assert session.is_active

    # Épuiser le générateur déclenche le finally → session.close().
    with pytest.raises(StopIteration):
        next(gen)


def test_reset_engine_disposes_and_clears(sqlite_settings: Settings) -> None:
    eng = engine_module.get_engine(sqlite_settings)
    engine_module.get_sessionmaker(sqlite_settings)
    assert engine_module._engine is eng
    assert engine_module._session_factory is not None

    engine_module.reset_engine()
    assert engine_module._engine is None
    assert engine_module._session_factory is None

    # Un nouvel appel recrée un moteur distinct (l'ancien a été disposé).
    new_engine = engine_module.get_engine(sqlite_settings)
    assert new_engine is not eng


def test_reset_engine_noop_when_unset() -> None:
    # Aucun moteur posé : reset ne doit pas lever (branche _engine is None).
    engine_module.reset_engine()
    assert engine_module._engine is None
    assert engine_module._session_factory is None
