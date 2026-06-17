"""Tests niveau métadonnées du schéma orchestration (sans DB).

Vérifie la structure (tables, colonnes, FKs, contraintes, defaults) et la
résolution du schéma PostgreSQL, indépendamment de toute connexion.
"""

from __future__ import annotations

import pytest

from app.db.base import Base
from app.db import models  # noqa: F401  (enregistre les tables sur Base.metadata)


def _table(name: str):
    """Retourne la table par son nom court, quel que soit le schéma résolu."""
    for table in Base.metadata.tables.values():
        if table.name == name:
            return table
    raise AssertionError(f"table {name!r} absente des métadonnées")


def test_four_tables_registered():
    names = {t.name for t in Base.metadata.tables.values()}
    assert {"dashboards", "orchestration_sets", "runs", "run_sets"} <= names


def test_orchestration_sets_columns_and_constraints():
    table = _table("orchestration_sets")
    expected = {
        "id", "dashboard_id", "set_id", "enabled", "action", "exchange",
        "market_type", "mtf_profile", "environment", "dry_run", "workers",
        "sync_tables", "symbols", "contracts_limit", "priority", "payload",
        "created_at", "updated_at",
    }
    assert expected <= set(table.columns.keys())

    # FK vers dashboards avec ON DELETE CASCADE.
    fk = next(iter(table.c.dashboard_id.foreign_keys))
    assert fk.column.table.name == "dashboards"
    assert fk.ondelete == "CASCADE"

    # Unicité (dashboard_id, set_id).
    uniques = {
        tuple(sorted(c.name for c in con.columns))
        for con in table.constraints
        if con.__class__.__name__ == "UniqueConstraint"
    }
    assert ("dashboard_id", "set_id") in uniques


def test_runs_columns_and_dashboard_fk_set_null():
    table = _table("runs")
    expected = {
        "run_id", "dashboard_id", "ok", "status", "idempotency_key",
        "total_calls", "success_count", "failed_count", "started_at",
        "finished_at", "last_json", "created_at",
    }
    assert expected <= set(table.columns.keys())
    assert table.c.run_id.primary_key
    assert table.c.dashboard_id.nullable is True

    fk = next(iter(table.c.dashboard_id.foreign_keys))
    assert fk.ondelete == "SET NULL"


def test_run_sets_fks_and_unique():
    table = _table("run_sets")
    expected = {
        "id", "run_id", "set_id", "set_ref_id", "payload_sent",
        "response_json", "ok", "error", "duration_ms", "created_at",
    }
    assert expected <= set(table.columns.keys())

    run_fk = next(iter(table.c.run_id.foreign_keys))
    assert run_fk.column.table.name == "runs"
    assert run_fk.ondelete == "CASCADE"

    set_fk = next(iter(table.c.set_ref_id.foreign_keys))
    assert set_fk.column.table.name == "orchestration_sets"
    assert set_fk.ondelete == "SET NULL"

    uniques = {
        tuple(sorted(c.name for c in con.columns))
        for con in table.constraints
        if con.__class__.__name__ == "UniqueConstraint"
    }
    assert ("run_id", "set_id") in uniques


def test_index_names_match_migration():
    """Les index ORM portent exactement les noms créés par la migration 0001.

    Évite le drift autogenerate (index mono-colonne fantômes via la convention).
    """
    all_indexes = {idx.name for t in Base.metadata.tables.values() for idx in t.indexes}
    assert {
        "ix_orchestration_sets_dashboard_enabled_priority",
        "ix_runs_dashboard_created_at",
        "ix_run_sets_run_id",
    } <= all_indexes
    # Aucun index mono-colonne fantôme issu de `index=True`.
    assert "ix_orchestration_sets_dashboard_id" not in all_indexes
    assert "ix_runs_dashboard_id" not in all_indexes


def test_schema_resolution_default_and_custom(monkeypatch):
    """Défaut ``orchestration`` ; un identifiant SQL valide est accepté tel quel."""
    import app.db.base as base_module

    monkeypatch.delenv("ORCHESTRATION_DB_SCHEMA", raising=False)
    assert base_module._resolve_schema() == "orchestration"

    monkeypatch.setenv("ORCHESTRATION_DB_SCHEMA", "custom_schema")
    assert base_module._resolve_schema() == "custom_schema"


@pytest.mark.parametrize("bad", ["", "  ", "public schema", "orch;DROP", 'a"b', "1abc", "a-b"])
def test_schema_resolution_rejects_invalid(monkeypatch, bad):
    """Tout nom qui n'est pas un identifiant SQL simple est refusé (anti-injection)."""
    import app.db.base as base_module

    monkeypatch.setenv("ORCHESTRATION_DB_SCHEMA", bad)
    with pytest.raises(base_module.SchemaError):
        base_module._resolve_schema()
