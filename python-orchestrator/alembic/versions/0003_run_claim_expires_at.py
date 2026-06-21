"""run claim TTL column (SAFE-002)

Revision ID: 0003_run_claim_expires_at
Revises: 0002_orchestration_locks
Create Date: 2026-06-21

Ajoute la colonne ``orchestration.runs.expires_at`` : le TTL du claim « en vol »
(statut ``running``) posé au démarrage d'un run idempotent. Passé cette date, un
run resté ``running`` (process tué avant la finalisation) est reclaimable par un
nouveau déclenchement partageant le même ancrage d'idempotence. Reste dans le
schéma dédié ``orchestration`` (aucun impact sur ``public``/Doctrine).
"""

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

from app.db.base import SCHEMA

# revision identifiers, used by Alembic.
revision: str = "0003_run_claim_expires_at"
down_revision: Union[str, None] = "0002_orchestration_locks"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

SCHEMA_KW = {"schema": SCHEMA} if SCHEMA else {}


def upgrade() -> None:
    op.add_column(
        "runs",
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=True),
        **SCHEMA_KW,
    )


def downgrade() -> None:
    op.drop_column("runs", "expires_at", **SCHEMA_KW)
