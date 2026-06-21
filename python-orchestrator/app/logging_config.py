"""Configuration centralisée du logging d'audit (OBS-001).

Branche, **au démarrage** (cf. ``app/main.py``), un handler ``stdout`` sur le
logger ``orchestrator.audit`` avec un formatteur **JSON line déterministe** :

    {"timestamp": "<ISO UTC>", "level": "INFO", "event": "run_started",
     "run_id": "run_…", <champs métier plats>}

Décisions produit OBS-001 (figées) :

- **sink = logs structurés JSON sur stdout** (aucune migration, container /
  aggregator-friendly) ;
- **format = JSON line strict** (une clé ``event`` + champs plats).

Le niveau est piloté par ``ORCHESTRATION_LOG_LEVEL`` (défaut ``INFO``, validé
au démarrage par ``app/settings.py`` comme ``ORCHESTRATION_LOCK_TTL_SECONDS``).

Idempotent : ``configure_audit_logging`` ne pose qu'**un seul** handler d'audit
même appelée plusieurs fois (pas de double émission), et coupe la propagation
vers le root pour ne pas dupliquer la ligne via les handlers uvicorn/applicatifs.
"""

from __future__ import annotations

import json
import logging
import sys
from datetime import datetime, timezone

from app.services.run_audit import AUDIT_LOGGER_NAME

# Marqueur posé sur notre handler pour garantir l'idempotence (un seul handler
# d'audit, quel que soit le nombre d'appels à ``configure_audit_logging``).
_AUDIT_HANDLER_FLAG = "_orchestrator_audit_handler"


class JsonLineFormatter(logging.Formatter):
    """Rend chaque ``LogRecord`` d'audit en **une ligne JSON déterministe**.

    Ordre de clés stable : ``timestamp`` (ISO UTC), ``level``, puis le payload
    d'audit (``event``, ``run_id``, champs métier) tel que construit par
    ``run_audit.emit``. ``default=str`` garantit qu'un champ non nativement
    sérialisable (ex. ``datetime``) ne fait jamais lever le rendu (fail-safe).
    """

    def format(self, record: logging.LogRecord) -> str:
        # `created` est un timestamp epoch ; on le rend en ISO 8601 UTC.
        timestamp = datetime.fromtimestamp(record.created, timezone.utc).isoformat()
        payload = {"timestamp": timestamp, "level": record.levelname}
        audit = getattr(record, "audit", None)
        if isinstance(audit, dict):
            # `audit` contient déjà `event` + `run_id` + champs métier aplatis.
            payload.update(audit)
        else:
            # Ligne non-audit éventuelle (ex. trace de garde interne) : on reste
            # JSON line en repli sur le message brut.
            payload["event"] = record.name
            payload["message"] = record.getMessage()
        if record.exc_info:
            payload["exc"] = self.formatException(record.exc_info)
        return json.dumps(payload, default=str)


def configure_audit_logging(level: str = "INFO") -> logging.Logger:
    """Configure (idempotemment) le logger d'audit ``orchestrator.audit``.

    - pose un unique ``StreamHandler(stdout)`` muni du ``JsonLineFormatter`` ;
    - règle le niveau sur ``level`` (déjà validé par ``settings``) ;
    - coupe ``propagate`` (pas de double émission via le root/uvicorn).

    Réappelée (rechargement, tests), elle ne duplique pas le handler.
    """
    logger = logging.getLogger(AUDIT_LOGGER_NAME)
    logger.setLevel(level)
    # Un `logging.config.fileConfig(...)` exécuté ailleurs (ex. Alembic) avec son
    # défaut `disable_existing_loggers=True` désactiverait ce logger : on le
    # réactive explicitement pour que l'audit reste émis quoi qu'il arrive.
    logger.disabled = False
    # Pas de double émission : la ligne ne remonte pas au root (qui peut porter
    # ses propres handlers, ex. uvicorn) — notre handler dédié suffit.
    logger.propagate = False

    for handler in logger.handlers:
        if getattr(handler, _AUDIT_HANDLER_FLAG, False):
            # Handler déjà posé : on rafraîchit seulement le niveau effectif.
            return logger

    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonLineFormatter())
    setattr(handler, _AUDIT_HANDLER_FLAG, True)
    logger.addHandler(handler)
    return logger
