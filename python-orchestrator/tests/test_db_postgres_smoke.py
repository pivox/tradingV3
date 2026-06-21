"""Smoke test PostgreSQL/Alembic du schéma orchestration (DB-001).

Contrairement aux tests SQLite, ce test valide le **vrai contrat PostgreSQL** :
cycle ``alembic upgrade head`` / ``downgrade base``, présence des 5 tables +
``alembic_version`` dans le schéma dédié, schéma ``public`` intact, et absence de
drift entre les modèles ORM et la migration (``compare_metadata``).

Il est **gated** par ``ORCHESTRATOR_TEST_DATABASE_URL`` : ignoré proprement en
local sans Postgres, exécuté automatiquement en CI (cf.
``.github/workflows/python-orchestrator.yml``).
"""

from __future__ import annotations

import os
from pathlib import Path

import pytest

TEST_DB_URL = os.getenv("ORCHESTRATOR_TEST_DATABASE_URL")

pytestmark = pytest.mark.skipif(
    not TEST_DB_URL,
    reason="ORCHESTRATOR_TEST_DATABASE_URL non défini (smoke test PostgreSQL ignoré).",
)

ROOT = Path(__file__).resolve().parents[1]
SCHEMA = "orchestration"
EXPECTED_TABLES = {
    "dashboards",
    "orchestration_sets",
    "runs",
    "run_sets",
    "orchestration_locks",
}


def _alembic_config():
    from alembic.config import Config

    cfg = Config(str(ROOT / "alembic.ini"))
    cfg.set_main_option("script_location", str(ROOT / "alembic"))
    return cfg


@pytest.fixture()
def pg_engine(monkeypatch):
    """Engine PostgreSQL de test ; nettoie le schéma avant/après pour des reruns."""
    from sqlalchemy import create_engine, text

    # env.py lit l'URL/le schéma depuis l'environnement.
    monkeypatch.setenv("DATABASE_URL", TEST_DB_URL)
    monkeypatch.setenv("ORCHESTRATION_DB_SCHEMA", SCHEMA)

    engine = create_engine(TEST_DB_URL, future=True)
    with engine.begin() as conn:
        conn.execute(text(f'DROP SCHEMA IF EXISTS "{SCHEMA}" CASCADE'))
    try:
        yield engine
    finally:
        with engine.begin() as conn:
            conn.execute(text(f'DROP SCHEMA IF EXISTS "{SCHEMA}" CASCADE'))
        engine.dispose()


def _tables_in_schema(engine, schema):
    from sqlalchemy import inspect

    return set(inspect(engine).get_table_names(schema=schema))


def test_upgrade_creates_schema_and_keeps_public_intact(pg_engine):
    from alembic import command

    public_before = _tables_in_schema(pg_engine, "public")

    command.upgrade(_alembic_config(), "head")

    orch_tables = _tables_in_schema(pg_engine, SCHEMA)
    assert EXPECTED_TABLES <= orch_tables
    assert "alembic_version" in orch_tables

    # public ne doit contenir aucune table d'orchestration (isolation Symfony).
    public_after = _tables_in_schema(pg_engine, "public")
    assert public_after == public_before
    assert EXPECTED_TABLES.isdisjoint(public_after)
    assert "alembic_version" not in public_after


def test_no_autogenerate_drift(pg_engine):
    """Après upgrade, les modèles ORM ne diffèrent pas de la base (anti-drift)."""
    from alembic import command
    from alembic.autogenerate import compare_metadata
    from alembic.runtime.migration import MigrationContext

    from app.db.base import Base

    command.upgrade(_alembic_config(), "head")

    def include_object(obj, name, type_, reflected, compare_to):
        if type_ == "table":
            return getattr(obj, "schema", None) == SCHEMA
        return True

    with pg_engine.connect() as connection:
        mc = MigrationContext.configure(
            connection,
            opts={
                "include_schemas": True,
                "version_table_schema": SCHEMA,
                "include_object": include_object,
                "target_metadata": Base.metadata,
            },
        )
        diff = compare_metadata(mc, Base.metadata)

    assert diff == [], f"Drift ORM/migration détecté : {diff}"


def test_downgrade_removes_tables(pg_engine):
    from alembic import command

    command.upgrade(_alembic_config(), "head")
    command.downgrade(_alembic_config(), "base")

    orch_tables = _tables_in_schema(pg_engine, SCHEMA)
    assert EXPECTED_TABLES.isdisjoint(orch_tables)
