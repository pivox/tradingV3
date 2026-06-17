"""Configuration de l'orchestrateur, chargée depuis l'environnement.

On suit la convention du sous-projet ``cron_symfony_mtf_workers`` :
lecture via ``os.getenv`` avec valeurs par défaut, pas de dépendance
supplémentaire. Les valeurs invalides lèvent une erreur explicite au
démarrage plutôt que d'être masquées par un défaut silencieux.
"""

from __future__ import annotations

import os
from dataclasses import dataclass


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

    def __post_init__(self) -> None:
        if not 1 <= self.port <= 65535:
            raise SettingsError(f"ORCHESTRATOR_PORT hors plage : {self.port} (attendu 1..65535).")
        if self.max_concurrency < 1:
            raise SettingsError(f"MAX_CONCURRENCY doit être >= 1 (reçu {self.max_concurrency}).")

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            symfony_base_url=os.getenv("SYMFONY_BASE_URL", cls.symfony_base_url),
            port=_int_env("ORCHESTRATOR_PORT", cls.port),
            max_concurrency=_int_env("MAX_CONCURRENCY", cls.max_concurrency),
        )


def get_settings() -> Settings:
    """Retourne les paramètres courants chargés depuis l'environnement."""
    return Settings.from_env()
