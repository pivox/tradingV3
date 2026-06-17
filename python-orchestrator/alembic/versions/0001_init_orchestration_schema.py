"""init orchestration schema (dashboards, sets, runs, run_sets)

Revision ID: 0001_orchestration
Revises:
Create Date: 2026-06-17

Crée le schéma PostgreSQL dédié ``orchestration`` et ses quatre tables. Isolé de
``public`` pour ne pas interférer avec les migrations Doctrine de Symfony.
"""

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

from app.db.base import SCHEMA

# revision identifiers, used by Alembic.
revision: str = "0001_orchestration"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

SCHEMA_KW = {"schema": SCHEMA} if SCHEMA else {}


def upgrade() -> None:
    # Le schéma dédié est créé par env.py avant la table de version Alembic.
    op.create_table(
        "dashboards",
        sa.Column("id", sa.BigInteger(), autoincrement=True, nullable=False),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("enabled", sa.Boolean(), server_default=sa.text("true"), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.PrimaryKeyConstraint("id", name="pk_dashboards"),
        sa.UniqueConstraint("name", name="uq_dashboards_name"),
        **SCHEMA_KW,
    )

    op.create_table(
        "orchestration_sets",
        sa.Column("id", sa.BigInteger(), autoincrement=True, nullable=False),
        sa.Column("dashboard_id", sa.BigInteger(), nullable=False),
        sa.Column("set_id", sa.String(length=255), nullable=False),
        sa.Column("enabled", sa.Boolean(), server_default=sa.text("true"), nullable=False),
        sa.Column("action", sa.String(length=32), server_default="mtf_run", nullable=False),
        sa.Column("exchange", sa.String(length=32), nullable=False),
        sa.Column("market_type", sa.String(length=32), server_default="perpetual", nullable=False),
        sa.Column("mtf_profile", sa.String(length=32), server_default="regular", nullable=False),
        sa.Column("environment", sa.String(length=32), server_default="demo", nullable=False),
        sa.Column("dry_run", sa.Boolean(), server_default=sa.text("true"), nullable=False),
        sa.Column("workers", sa.Integer(), server_default=sa.text("1"), nullable=False),
        sa.Column("sync_tables", sa.Boolean(), server_default=sa.text("false"), nullable=False),
        sa.Column("symbols", postgresql.JSONB(), server_default=sa.text("'[]'::jsonb"), nullable=False),
        sa.Column("contracts_limit", sa.Integer(), nullable=True),
        sa.Column("priority", sa.Integer(), server_default=sa.text("0"), nullable=False),
        sa.Column("payload", postgresql.JSONB(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.PrimaryKeyConstraint("id", name="pk_orchestration_sets"),
        sa.ForeignKeyConstraint(
            ["dashboard_id"],
            [f"{SCHEMA + '.' if SCHEMA else ''}dashboards.id"],
            name="fk_orchestration_sets_dashboard_id_dashboards",
            ondelete="CASCADE",
        ),
        sa.UniqueConstraint("dashboard_id", "set_id", name="uq_orchestration_sets_dashboard_set"),
        **SCHEMA_KW,
    )
    op.create_index(
        "ix_orchestration_sets_dashboard_enabled_priority",
        "orchestration_sets",
        ["dashboard_id", "enabled", "priority"],
        **SCHEMA_KW,
    )

    op.create_table(
        "runs",
        sa.Column("run_id", sa.String(length=255), nullable=False),
        sa.Column("dashboard_id", sa.BigInteger(), nullable=True),
        sa.Column("ok", sa.Boolean(), server_default=sa.text("false"), nullable=False),
        sa.Column("status", sa.String(length=32), nullable=False),
        sa.Column("idempotency_key", sa.String(length=255), nullable=True),
        sa.Column("total_calls", sa.Integer(), server_default=sa.text("0"), nullable=False),
        sa.Column("success_count", sa.Integer(), server_default=sa.text("0"), nullable=False),
        sa.Column("failed_count", sa.Integer(), server_default=sa.text("0"), nullable=False),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_json", postgresql.JSONB(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.PrimaryKeyConstraint("run_id", name="pk_runs"),
        sa.ForeignKeyConstraint(
            ["dashboard_id"],
            [f"{SCHEMA + '.' if SCHEMA else ''}dashboards.id"],
            name="fk_runs_dashboard_id_dashboards",
            ondelete="SET NULL",
        ),
        sa.UniqueConstraint("idempotency_key", name="uq_runs_idempotency_key"),
        **SCHEMA_KW,
    )
    op.create_index(
        "ix_runs_dashboard_created_at",
        "runs",
        ["dashboard_id", "created_at"],
        **SCHEMA_KW,
    )

    op.create_table(
        "run_sets",
        sa.Column("id", sa.BigInteger(), autoincrement=True, nullable=False),
        sa.Column("run_id", sa.String(length=255), nullable=False),
        sa.Column("set_id", sa.String(length=255), nullable=False),
        sa.Column("set_ref_id", sa.BigInteger(), nullable=True),
        sa.Column("payload_sent", postgresql.JSONB(), nullable=True),
        sa.Column("response_json", postgresql.JSONB(), nullable=True),
        sa.Column("ok", sa.Boolean(), server_default=sa.text("false"), nullable=False),
        sa.Column("error", sa.Text(), nullable=True),
        sa.Column("duration_ms", sa.Integer(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.PrimaryKeyConstraint("id", name="pk_run_sets"),
        sa.ForeignKeyConstraint(
            ["run_id"],
            [f"{SCHEMA + '.' if SCHEMA else ''}runs.run_id"],
            name="fk_run_sets_run_id_runs",
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["set_ref_id"],
            [f"{SCHEMA + '.' if SCHEMA else ''}orchestration_sets.id"],
            name="fk_run_sets_set_ref_id_orchestration_sets",
            ondelete="SET NULL",
        ),
        sa.UniqueConstraint("run_id", "set_id", name="uq_run_sets_run_set"),
        **SCHEMA_KW,
    )
    op.create_index("ix_run_sets_run_id", "run_sets", ["run_id"], **SCHEMA_KW)


def downgrade() -> None:
    op.drop_index("ix_run_sets_run_id", table_name="run_sets", **SCHEMA_KW)
    op.drop_table("run_sets", **SCHEMA_KW)
    op.drop_index("ix_runs_dashboard_created_at", table_name="runs", **SCHEMA_KW)
    op.drop_table("runs", **SCHEMA_KW)
    op.drop_index(
        "ix_orchestration_sets_dashboard_enabled_priority",
        table_name="orchestration_sets",
        **SCHEMA_KW,
    )
    op.drop_table("orchestration_sets", **SCHEMA_KW)
    op.drop_table("dashboards", **SCHEMA_KW)
    # On ne supprime PAS le schéma : la table de version Alembic
    # (``orchestration.alembic_version``) y réside. Un teardown complet relève
    # de l'opérateur : DROP SCHEMA "orchestration" CASCADE.
