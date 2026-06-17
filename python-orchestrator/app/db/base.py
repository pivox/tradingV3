"""Base déclarative SQLAlchemy et schéma des tables d'orchestration.

Le schéma PostgreSQL est dédié (``orchestration`` par défaut) pour isoler ces
tables de celles gérées par Doctrine côté Symfony (qui n'introspecte que
``public``). Le nom est lu depuis l'environnement pour pouvoir être neutralisé
dans les tests SQLite — qui ne supportent pas les schémas — via
``ORCHESTRATION_DB_SCHEMA=none`` (ou vide).
"""

from __future__ import annotations

import os
from typing import Optional

from sqlalchemy import MetaData
from sqlalchemy.orm import DeclarativeBase


def _resolve_schema() -> Optional[str]:
    """Résout le schéma cible ; ``None`` désactive le préfixe (tests SQLite)."""
    raw = os.getenv("ORCHESTRATION_DB_SCHEMA", "orchestration").strip()
    if raw.lower() in {"", "none"}:
        return None
    return raw


# Schéma figé à l'import : les tables le portent via ``MetaData(schema=...)``.
SCHEMA: Optional[str] = _resolve_schema()

# Convention de nommage explicite des contraintes/index : noms stables et
# déterministes, indispensables pour des migrations Alembic propres.
NAMING_CONVENTION = {
    "ix": "ix_%(column_0_label)s",
    "uq": "uq_%(table_name)s_%(column_0_name)s",
    "fk": "fk_%(table_name)s_%(column_0_name)s_%(referred_table_name)s",
    "pk": "pk_%(table_name)s",
}


class Base(DeclarativeBase):
    """Base déclarative commune à tous les modèles d'orchestration."""

    metadata = MetaData(schema=SCHEMA, naming_convention=NAMING_CONVENTION)
