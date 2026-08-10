"""Focused migration contract tests for orchestration set lineage."""

from __future__ import annotations

import importlib.util
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "alembic/versions/0004_orchestration_set_trading_identity.py"


def _load_migration():
    assert MIGRATION.exists(), "migration 0004 must add the nullable identity column"
    spec = importlib.util.spec_from_file_location("migration_0004", MIGRATION)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_0004_adds_nullable_jsonb_identity_without_backfill():
    migration = _load_migration()
    calls = []

    class RecordingOp:
        def add_column(self, table_name, column, **kwargs):
            calls.append(("add", table_name, column, kwargs))

        def execute(self, *_args, **_kwargs):
            raise AssertionError("migration 0004 must not infer or backfill identity")

    migration.op = RecordingOp()
    migration.upgrade()

    operation, table_name, column, kwargs = calls.pop()
    assert operation == "add"
    assert table_name == "orchestration_sets"
    assert column.name == "trading_identity"
    assert column.nullable is True
    assert column.type.__class__.__name__ == "JSONB"
    assert kwargs == migration.SCHEMA_KW
    assert calls == []


def test_0004_downgrade_drops_only_identity_column():
    migration = _load_migration()
    calls = []

    class RecordingOp:
        def drop_column(self, table_name, column_name, **kwargs):
            calls.append((table_name, column_name, kwargs))

    migration.op = RecordingOp()
    migration.downgrade()

    assert calls == [("orchestration_sets", "trading_identity", migration.SCHEMA_KW)]
