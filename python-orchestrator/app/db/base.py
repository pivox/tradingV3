"""Base déclarative SQLAlchemy et schéma des tables d'orchestration.

Le schéma PostgreSQL est dédié (``orchestration`` par défaut) pour isoler ces
tables de celles gérées par Doctrine côté Symfony (qui n'introspecte que
``public``). Le nom est lu depuis l'environnement et **strictement validé** : il
n'existe aucun chemin runtime qui retombe sur le schéma par défaut/``public``.
Les tests SQLite conservent le schéma et attachent une base in-memory du même
nom (cf. ``tests/conftest.py``) plutôt que de le neutraliser.
"""

from __future__ import annotations

import os
import re

from sqlalchemy import MetaData
from sqlalchemy.orm import DeclarativeBase

DEFAULT_SCHEMA = "orchestration"
# Identifiant SQL simple : évite toute injection DDL via le nom de schéma.
_SCHEMA_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")


class SchemaError(ValueError):
    """Nom de schéma d'orchestration invalide."""


def _resolve_schema() -> str:
    """Résout et valide le schéma cible (jamais ``None``, jamais ``public`` implicite)."""
    raw = os.getenv("ORCHESTRATION_DB_SCHEMA", DEFAULT_SCHEMA).strip()
    if not _SCHEMA_RE.match(raw):
        raise SchemaError(
            f"ORCHESTRATION_DB_SCHEMA invalide : {raw!r}. "
            "Attendu un identifiant SQL simple (^[A-Za-z_][A-Za-z0-9_]*$)."
        )
    return raw


# Schéma figé à l'import : les tables le portent via ``MetaData(schema=...)``.
SCHEMA: str = _resolve_schema()

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
