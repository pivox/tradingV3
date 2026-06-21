"""Configuration de l'orchestrateur, chargée depuis l'environnement.

On suit la convention du sous-projet ``cron_symfony_mtf_workers`` :
lecture via ``os.getenv`` avec valeurs par défaut, pas de dépendance
supplémentaire. Les valeurs invalides lèvent une erreur explicite au
démarrage plutôt que d'être masquées par un défaut silencieux.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Tuple


class SettingsError(ValueError):
    """Configuration runtime invalide."""


def _int_env(name: str, default: int) -> int:
    """Lit un entier depuis l'environnement, ou lève si la valeur est invalide."""
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    try:
        return int(raw)
    except ValueError as exc:
        raise SettingsError(f"Variable {name!r} invalide : {raw!r} n'est pas un entier.") from exc


def _csv_env(name: str, default: Tuple[str, ...]) -> Tuple[str, ...]:
    """Lit une liste CSV depuis l'environnement (vides ignorés), ou le défaut.

    Utilisé pour ``CORS_ALLOW_ORIGINS`` : ``"http://a, http://b"`` -> ``("http://a", "http://b")``.
    Une valeur vide/absente retombe sur le défaut ; ``"*"`` reste possible (autorise
    toute origine, acceptable car le service n'utilise pas de cookies/credentials).
    """
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    return tuple(item.strip() for item in raw.split(",") if item.strip())


@dataclass(frozen=True)
class Settings:
    """Paramètres runtime de l'API Python Orchestrator."""

    service_name: str = "python-orchestrator"
    # URL de base de Symfony (le moteur métier MTF). Utilisée par PY-002+.
    symfony_base_url: str = "http://trading-app-nginx:80"
    # Port d'écoute HTTP du service.
    port: int = 8099
    # Concurrence globale bornée des appels Symfony (utilisée par PY-002+).
    max_concurrency: int = 2
    # URL SQLAlchemy de la base orchestration (DB-001). On réutilise la base
    # `trading_app` mais dans un schéma dédié `orchestration` (cf. db_schema)
    # pour ne pas interférer avec les migrations Doctrine de Symfony.
    database_url: str = (
        "postgresql+psycopg://postgres:password@trading-app-db:5432/trading_app"
    )
    # Schéma PostgreSQL dédié aux tables d'orchestration.
    db_schema: str = "orchestration"
    # Marge (s) anti-deadlock des locks d'orchestration par (profil, symbole)
    # (SAFE-001). Le TTL effectif d'un lock = pire temps de paroi du run (vagues de
    # `max_concurrency` * timeout Symfony) + cette marge, afin qu'un set resté en file
    # n'expire jamais avant son dispatch ; passé ce délai, un lock dont le titulaire a
    # été tué avant la libération est reclaimable. Défaut 1800s.
    lock_ttl_seconds: int = 1800
    # Origines autorisées par CORS pour les appels navigateur du cockpit (UI-001).
    # Défauts = front servi par CRA (:3000) ou nginx (:8082). Surchargeable via
    # CORS_ALLOW_ORIGINS (CSV) ; ``"*"`` autorise toute origine.
    cors_allow_origins: Tuple[str, ...] = (
        "http://localhost:3000",
        "http://127.0.0.1:3000",
        "http://localhost:8082",
        "http://127.0.0.1:8082",
    )

    def __post_init__(self) -> None:
        if not 1 <= self.port <= 65535:
            raise SettingsError(f"ORCHESTRATOR_PORT hors plage : {self.port} (attendu 1..65535).")
        if self.max_concurrency < 1:
            raise SettingsError(f"MAX_CONCURRENCY doit être >= 1 (reçu {self.max_concurrency}).")
        if not self.database_url.strip():
            raise SettingsError("DATABASE_URL ne doit pas être vide.")
        if not self.db_schema.strip():
            raise SettingsError("ORCHESTRATION_DB_SCHEMA ne doit pas être vide.")
        if self.lock_ttl_seconds < 1:
            raise SettingsError(
                f"ORCHESTRATION_LOCK_TTL_SECONDS doit être >= 1 (reçu {self.lock_ttl_seconds})."
            )

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            symfony_base_url=os.getenv("SYMFONY_BASE_URL", cls.symfony_base_url),
            port=_int_env("ORCHESTRATOR_PORT", cls.port),
            max_concurrency=_int_env("MAX_CONCURRENCY", cls.max_concurrency),
            database_url=os.getenv("DATABASE_URL", cls.database_url),
            db_schema=os.getenv("ORCHESTRATION_DB_SCHEMA", cls.db_schema),
            lock_ttl_seconds=_int_env("ORCHESTRATION_LOCK_TTL_SECONDS", cls.lock_ttl_seconds),
            cors_allow_origins=_csv_env("CORS_ALLOW_ORIGINS", cls.cors_allow_origins),
        )


def get_settings() -> Settings:
    """Retourne les paramètres courants chargés depuis l'environnement."""
    return Settings.from_env()
