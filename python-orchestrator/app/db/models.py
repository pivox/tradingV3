"""Modèles ORM des tables d'orchestration (DB-001).

Quatre tables alignées sur la cible documentée :

- ``dashboards``           : configurations d'orchestration ;
- ``orchestration_sets``   : sets prêts à exécuter (cf. ``OrchestratorSet``) ;
- ``runs``                 : runs déclenchés + dernier JSON global ;
- ``run_sets``             : résultat par set + dernier JSON par set.

Les colonnes JSON utilisent ``JSONB`` sur PostgreSQL et retombent sur ``JSON``
sur SQLite (tests). Aucun branchement avec les routers/services existants :
ces modèles ne servent que la persistance, le câblage applicatif est PY-002.
"""

from __future__ import annotations

from datetime import datetime
from typing import Optional

from sqlalchemy import (
    JSON,
    BigInteger,
    Boolean,
    DateTime,
    ForeignKey,
    Integer,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base

# JSONB côté PostgreSQL, JSON générique côté SQLite (tests).
JSONVariant = JSONB().with_variant(JSON(), "sqlite")

# BIGINT côté PostgreSQL ; INTEGER côté SQLite, car SQLite n'auto-incrémente
# que les colonnes ``INTEGER PRIMARY KEY`` (pas ``BIGINT``).
BigIntPK = BigInteger().with_variant(Integer(), "sqlite")


class Dashboard(Base):
    """Configuration d'orchestration regroupant des sets."""

    __tablename__ = "dashboards"

    id: Mapped[int] = mapped_column(BigIntPK, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False, unique=True)
    enabled: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True, server_default="true")
    description: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now(), onupdate=func.now()
    )

    sets: Mapped[list["OrchestrationSet"]] = relationship(
        back_populates="dashboard", cascade="all, delete-orphan", passive_deletes=True
    )


class OrchestrationSet(Base):
    """Unité fonctionnelle prête à exécuter (miroir persistant d'``OrchestratorSet``)."""

    __tablename__ = "orchestration_sets"
    __table_args__ = (
        UniqueConstraint("dashboard_id", "set_id", name="uq_orchestration_sets_dashboard_set"),
    )

    id: Mapped[int] = mapped_column(BigIntPK, primary_key=True, autoincrement=True)
    dashboard_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("dashboards.id", ondelete="CASCADE"), nullable=False, index=True
    )
    set_id: Mapped[str] = mapped_column(String(255), nullable=False)
    enabled: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True, server_default="true")
    action: Mapped[str] = mapped_column(String(32), nullable=False, default="mtf_run", server_default="mtf_run")
    exchange: Mapped[str] = mapped_column(String(32), nullable=False)
    market_type: Mapped[str] = mapped_column(String(32), nullable=False, default="perpetual", server_default="perpetual")
    mtf_profile: Mapped[str] = mapped_column(String(32), nullable=False, default="regular", server_default="regular")
    environment: Mapped[str] = mapped_column(String(32), nullable=False, default="demo", server_default="demo")
    dry_run: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True, server_default="true")
    workers: Mapped[int] = mapped_column(Integer, nullable=False, default=1, server_default="1")
    sync_tables: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False, server_default="false")
    symbols: Mapped[list] = mapped_column(JSONVariant, nullable=False, default=list)
    contracts_limit: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    priority: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    # Payload prêt envoyé à Symfony (préparé par PY-004). Null tant que non préparé.
    payload: Mapped[Optional[dict]] = mapped_column(JSONVariant, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now(), onupdate=func.now()
    )

    dashboard: Mapped[Dashboard] = relationship(back_populates="sets")


class Run(Base):
    """Run d'orchestration déclenché + dernier JSON global."""

    __tablename__ = "runs"

    # run_id = identifiant dérivé par l'orchestrateur (cf. _resolve_run_id).
    run_id: Mapped[str] = mapped_column(String(255), primary_key=True)
    # Nullable + SET NULL : on conserve l'historique des runs même si le
    # dashboard d'origine est supprimé.
    dashboard_id: Mapped[Optional[int]] = mapped_column(
        BigInteger, ForeignKey("dashboards.id", ondelete="SET NULL"), nullable=True, index=True
    )
    ok: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False, server_default="false")
    status: Mapped[str] = mapped_column(String(32), nullable=False)
    # Clé d'idempotence (SAFE-002) : unique quand fournie.
    idempotency_key: Mapped[Optional[str]] = mapped_column(String(255), nullable=True, unique=True)
    total_calls: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    success_count: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    failed_count: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    started_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    finished_at: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    # Dernier JSON global du run.
    last_json: Mapped[Optional[dict]] = mapped_column(JSONVariant, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )

    run_sets: Mapped[list["RunSet"]] = relationship(
        back_populates="run", cascade="all, delete-orphan", passive_deletes=True
    )


class RunSet(Base):
    """Résultat d'un set dans un run + dernier JSON par set."""

    __tablename__ = "run_sets"
    __table_args__ = (
        UniqueConstraint("run_id", "set_id", name="uq_run_sets_run_set"),
    )

    id: Mapped[int] = mapped_column(BigIntPK, primary_key=True, autoincrement=True)
    run_id: Mapped[str] = mapped_column(
        String(255), ForeignKey("runs.run_id", ondelete="CASCADE"), nullable=False, index=True
    )
    # Snapshot textuel du set_id (le set peut être supprimé après le run).
    set_id: Mapped[str] = mapped_column(String(255), nullable=False)
    # Lien optionnel vers le set persistant (rompu sans casser le run).
    set_ref_id: Mapped[Optional[int]] = mapped_column(
        BigInteger, ForeignKey("orchestration_sets.id", ondelete="SET NULL"), nullable=True
    )
    payload_sent: Mapped[Optional[dict]] = mapped_column(JSONVariant, nullable=True)
    response_json: Mapped[Optional[dict]] = mapped_column(JSONVariant, nullable=True)
    ok: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False, server_default="false")
    error: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    duration_ms: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )

    run: Mapped[Run] = relationship(back_populates="run_sets")
