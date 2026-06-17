"""Couche de persistance de l'orchestrateur (DB-001).

Schéma SQLAlchemy + accès DB pour les dashboards, sets et runs d'orchestration.
La couche est volontairement découplée des routers/services existants : le
branchement (CRUD, lecture des sets depuis la DB) est l'objet de PY-002.
"""

from app.db.base import SCHEMA, Base

__all__ = ["Base", "SCHEMA"]
