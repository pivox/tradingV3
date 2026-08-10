"""persist canonical trading identity on orchestration sets

Revision ID: 0004_set_trading_identity
Revises: 0003_run_claim_expires_at
Create Date: 2026-08-08

Adds one nullable JSONB snapshot to persisted orchestration sets. Existing rows
remain explicitly legacy: this migration performs no inference or backfill.
"""

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

from app.db.base import SCHEMA

revision: str = "0004_set_trading_identity"
down_revision: Union[str, None] = "0003_run_claim_expires_at"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

SCHEMA_KW = {"schema": SCHEMA} if SCHEMA else {}


def upgrade() -> None:
    op.add_column(
        "orchestration_sets",
        sa.Column("trading_identity", postgresql.JSONB(), nullable=True),
        **SCHEMA_KW,
    )


def downgrade() -> None:
    op.drop_column(
        "orchestration_sets",
        "trading_identity",
        **SCHEMA_KW,
    )
