"""Environnement Alembic de l'orchestrateur (DB-001).

L'URL et le schéma cible proviennent d'``app.settings`` : une seule source de
configuration (variables ``DATABASE_URL`` / ``ORCHESTRATION_DB_SCHEMA``). Les
migrations gèrent uniquement le schéma dédié ``orchestration`` ; la table de
version Alembic y est également stockée pour ne pas polluer ``public``.
"""

from __future__ import annotations

from logging.config import fileConfig

from alembic import context
from sqlalchemy import engine_from_config, pool, text

from app.db.base import SCHEMA, Base
from app.db import models  # noqa: F401  (enregistre les tables sur Base.metadata)
from app.settings import get_settings

config = context.config

if config.config_file_name is not None:
    fileConfig(config.config_file_name)

settings = get_settings()
config.set_main_option("sqlalchemy.url", settings.database_url)

target_metadata = Base.metadata

# Schéma dédié : aussi utilisé pour stocker la table de version Alembic.
_version_table_schema = SCHEMA


def _include_object(obj, name, type_, reflected, compare_to):  # noqa: ANN001
    """Ne gère que les objets du schéma orchestration (ignore public/Doctrine)."""
    if type_ == "table" and _version_table_schema is not None:
        return getattr(obj, "schema", None) == _version_table_schema
    return True


def _create_schema_sql() -> str:
    return f'CREATE SCHEMA IF NOT EXISTS "{_version_table_schema}"'


def run_migrations_offline() -> None:
    context.configure(
        url=config.get_main_option("sqlalchemy.url"),
        target_metadata=target_metadata,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
        include_schemas=True,
        version_table_schema=_version_table_schema,
        include_object=_include_object,
    )
    with context.begin_transaction():
        # Le schéma doit exister avant la table de version Alembic.
        if _version_table_schema:
            context.execute(_create_schema_sql())
        context.run_migrations()


def run_migrations_online() -> None:
    connectable = engine_from_config(
        config.get_section(config.config_ini_section, {}),
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )

    with connectable.connect() as connection:
        # Crée le schéma dédié avant qu'Alembic n'y matérialise sa table de
        # version : sans cela, ``alembic upgrade`` échoue au premier run.
        if _version_table_schema:
            connection.execute(text(_create_schema_sql()))
            connection.commit()
        context.configure(
            connection=connection,
            target_metadata=target_metadata,
            include_schemas=True,
            version_table_schema=_version_table_schema,
            include_object=_include_object,
        )
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
