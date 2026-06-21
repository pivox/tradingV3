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


# Valeurs booléennes acceptées (cassse-insensible) pour les interrupteurs d'env.
_TRUE_TOKENS = frozenset({"1", "true", "yes", "on"})
_FALSE_TOKENS = frozenset({"0", "false", "no", "off"})

# Niveaux de log d'audit acceptés (OBS-001), normalisés en MAJUSCULES — alignés
# sur les noms standard de ``logging``.
_VALID_LOG_LEVELS = frozenset({"DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"})


def _log_level_env(name: str, default: str) -> str:
    """Lit un niveau de log depuis l'environnement, ou lève si invalide (OBS-001).

    Normalisé en MAJUSCULES ; validé contre le jeu fermé des niveaux ``logging``
    standard. Une valeur inconnue lève au démarrage (comme
    ``ORCHESTRATION_LOCK_TTL_SECONDS``) plutôt que de retomber silencieusement
    sur le défaut — pas de configuration d'observabilité ambiguë.
    """
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    token = raw.strip().upper()
    if token not in _VALID_LOG_LEVELS:
        raise SettingsError(
            f"Variable {name!r} invalide : {raw!r} "
            f"(attendu {sorted(_VALID_LOG_LEVELS)})."
        )
    return token


def _bool_env(name: str, default: bool) -> bool:
    """Lit un booléen depuis l'environnement, ou lève si la valeur est invalide.

    Fail-closed : on n'interprète qu'un jeu fermé de jetons (``true/false``,
    ``1/0``, ``yes/no``, ``on/off``) ; toute autre valeur lève au démarrage plutôt
    que d'activer/désactiver silencieusement un interrupteur de sécurité (live).
    """
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    token = raw.strip().lower()
    if token in _TRUE_TOKENS:
        return True
    if token in _FALSE_TOKENS:
        return False
    raise SettingsError(
        f"Variable {name!r} invalide : {raw!r} (attendu true/false, 1/0, yes/no, on/off)."
    )


def _live_exchanges_env(name: str, default: Tuple[str, ...]) -> Tuple[str, ...]:
    """Allow-list live (CSV), normalisée en minuscules et validée au démarrage.

    Chaque entrée doit être un exchange **connu** (valeur de ``schemas.Exchange``) ;
    sinon on lève (``SettingsError``) plutôt que d'ignorer silencieusement une
    coquille qui rendrait l'allow-list inopérante. La normalisation casse/espaces
    s'aligne sur ``live_guard._normalize_exchange`` (``Bitmart`` ⇒ ``bitmart``).
    Les bannissements permanents (OKX/Hyperliquid) ne sont PAS rejetés ici : ils
    restent interdits par ``assess_live`` même listés (défense en profondeur).
    """
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    # Import différé : évite tout couplage d'import au chargement du module settings.
    from app.schemas import Exchange

    known = {e.value for e in Exchange}
    cleaned: list[str] = []
    for item in raw.split(","):
        token = item.strip().lower()
        if not token:
            continue
        if token not in known:
            raise SettingsError(
                f"Variable {name!r} invalide : exchange inconnu {token!r} "
                f"(connus : {sorted(known)})."
            )
        if token not in cleaned:
            cleaned.append(token)
    return tuple(cleaned)


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
    # Interrupteur d'activation live (SAFE-003). Par défaut **OFF** : tout set
    # `dry_run=false` est skippé fail-closed (comportement identique à avant
    # SAFE-003). Tant qu'il reste OFF, aucun ordre live ne peut partir, quelle que
    # soit l'allow-list. À ne JAMAIS livrer à True sans readiness runtime.
    live_enabled: bool = False
    # Allow-list des exchanges autorisés à passer live QUAND l'interrupteur est ON
    # (défaut **vide** = aucun). OKX/Hyperliquid restent interdits même listés
    # (bannissement permanent géré par `live_guard`). En pratique : au plus
    # `bitmart` (+ `fake` en simulation).
    live_exchanges: Tuple[str, ...] = ()
    # Niveau de log de l'audit des runs (OBS-001), piloté par
    # ``ORCHESTRATION_LOG_LEVEL`` (défaut INFO). Validé au démarrage : une valeur
    # inconnue lève (pas de repli silencieux). Cf. ``app/logging_config.py``.
    log_level: str = "INFO"
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
        if self.log_level not in _VALID_LOG_LEVELS:
            raise SettingsError(
                f"ORCHESTRATION_LOG_LEVEL invalide : {self.log_level!r} "
                f"(attendu {sorted(_VALID_LOG_LEVELS)})."
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
            live_enabled=_bool_env("ORCHESTRATION_LIVE_ENABLED", cls.live_enabled),
            live_exchanges=_live_exchanges_env("ORCHESTRATION_LIVE_EXCHANGES", cls.live_exchanges),
            log_level=_log_level_env("ORCHESTRATION_LOG_LEVEL", cls.log_level),
            cors_allow_origins=_csv_env("CORS_ALLOW_ORIGINS", cls.cors_allow_origins),
        )


def get_settings() -> Settings:
    """Retourne les paramètres courants chargés depuis l'environnement."""
    return Settings.from_env()
