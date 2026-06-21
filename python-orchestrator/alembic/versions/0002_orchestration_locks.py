"""orchestration locks (SAFE-001)

Revision ID: 0002_orchestration_locks
Revises: 0001_orchestration
Create Date: 2026-06-21

Ajoute la table ``orchestration.orchestration_locks`` qui sérialise deux runs
concurrents sur le même ``(mtf_profile, exchange, market_type, symbol)``.
L'exclusion mutuelle est portée par la contrainte UNIQUE sur ``lock_key``. Reste
dans le schéma dédié ``orchestration`` (aucun impact sur ``public``/Doctrine).
"""

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

from app.db.base import SCHEMA

# revision identifiers, used by Alembic.
revision: str = "0002_orchestration_locks"
down_revision: Union[str, None] = "0001_orchestration"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

SCHEMA_KW = {"schema": SCHEMA} if SCHEMA else {}


def upgrade() -> None:
    op.create_table(
        "orchestration_locks",
        sa.Column("id", sa.BigInteger(), autoincrement=True, nullable=False),
        sa.Column("lock_key", sa.String(length=512), nullable=False),
        sa.Column("mtf_profile", sa.String(length=32), nullable=False),
        sa.Column("exchange", sa.String(length=32), nullable=False),
        sa.Column("market_type", sa.String(length=32), nullable=False),
        sa.Column("symbol", sa.String(length=64), nullable=False),
        sa.Column("run_id", sa.String(length=255), nullable=False),
        sa.Column("acquired_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.PrimaryKeyConstraint("id", name="pk_orchestration_locks"),
        # Contrainte d'exclusion mutuelle : un seul titulaire par clé.
        sa.UniqueConstraint("lock_key", name="uq_orchestration_locks_lock_key"),
        **SCHEMA_KW,
    )
    op.create_index(
        "ix_orchestration_locks_expires_at",
        "orchestration_locks",
        ["expires_at"],
        **SCHEMA_KW,
    )


def downgrade() -> None:
    op.drop_index(
        "ix_orchestration_locks_expires_at",
        table_name="orchestration_locks",
        **SCHEMA_KW,
    )
    op.drop_table("orchestration_locks", **SCHEMA_KW)
