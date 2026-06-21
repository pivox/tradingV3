"""Audit structuré, fail-safe et corrélé des runs d'orchestration (OBS-001).

Avant OBS-001, les décisions du runner (`POST /orchestrator/run`) — skips
fail-closed, court-circuits d'idempotence (SAFE-002), pose/skip des locks
(SAFE-001), garde-fous live (SAFE-003), échecs Symfony — n'étaient observables
qu'**en base**, après coup (`runs.last_json`, `run_sets`). Aucun flux d'audit
corrélé par `run_id` n'existait pour le diagnostic temps réel (logs conteneur /
agrégation).

Ce module expose un **point d'émission unique** :

    emit(event, *, run_id, level="info", **fields)

qui sérialise un événement structuré (une clé `event` + champs plats) sur le
logger nommé ``orchestrator.audit``. Le rendu JSON line déterministe est porté
par le formatteur (cf. ``app/logging_config.py``), branché au démarrage.

Invariant fondamental : **fail-safe**. Une erreur d'audit (sérialisation,
handler, logger mal configuré) ne doit JAMAIS faire échouer ni interrompre un
run. Tout est encapsulé dans un ``try/except`` interne : ``emit`` ne lève jamais
et ne fait aucune I/O bloquante hors ``logging`` stdlib.

L'audit **complète** l'historique (`last_json`/`RunSet`) — il ne le remplace pas
et n'en change pas le contenu. Les `reason`/`code` audités sont **identiques** à
ceux versés dans `RunSet.error` (source unique SAFE-003 / runner) : ce module
ne redéfinit aucune raison métier, il relaie celles déjà calculées.
"""

from __future__ import annotations

import logging
from typing import Any

# Logger d'audit dédié. Nommé (pas le root) pour pouvoir le configurer/filtrer
# indépendamment des logs applicatifs et éviter toute double émission.
AUDIT_LOGGER_NAME = "orchestrator.audit"

# Événements d'audit (noms stables, machine-readable). Un seul point d'émission
# par cause — pas de duplication de schéma entre le runner et l'audit.
RUN_STARTED = "run_started"
RUN_SHORT_CIRCUIT = "run_short_circuit"
SNAPSHOT_FETCH = "snapshot_fetch"
SET_SKIPPED = "set_skipped"
SET_DISPATCHED = "set_dispatched"
SET_RESULT = "set_result"
RUN_FINISHED = "run_finished"

# Niveaux acceptés par ``emit`` (mappés sur ``logging``). Une valeur inconnue
# retombe sur INFO (fail-safe : on n'échoue pas l'audit pour un niveau douteux).
_LEVELS = {
    "debug": logging.DEBUG,
    "info": logging.INFO,
    "warning": logging.WARNING,
    "error": logging.ERROR,
    "critical": logging.CRITICAL,
}

_logger = logging.getLogger(AUDIT_LOGGER_NAME)


def emit(event: str, *, run_id: Any, level: str = "info", **fields: Any) -> None:
    """Émet une ligne d'audit structurée corrélée par ``run_id`` (fail-safe).

    - ``event`` : nom stable de l'événement (cf. constantes ci-dessus) ;
    - ``run_id`` : clé de corrélation — le ``run_id`` **réellement persisté**
      (résolu par ``_seed_claim``/``_persist_run``), passé par l'appelant ;
    - ``level`` : niveau de log (``info`` par défaut ; ``warning`` pour les
      skips/court-circuits) ;
    - ``**fields`` : champs métier plats (ex. ``dashboard_id``, ``set_id``,
      ``code``, ``duration_ms``), sérialisés tels quels.

    Ne lève **jamais** : toute exception (champ non sérialisable au moment du
    rendu, handler en échec, etc.) est absorbée. L'audit est un effet de bord
    d'observabilité, jamais un chemin critique du run.
    """
    try:
        levelno = _LEVELS.get(str(level).lower(), logging.INFO)
        # Payload structuré : `event` + `run_id` + champs métier aplatis. Porté
        # tel quel par le formatteur JSON line. On garde les champs métier DANS
        # ce dict (et non dans `extra`) pour ne jamais entrer en collision avec
        # un attribut réservé de ``LogRecord``.
        payload = {"event": event, "run_id": run_id}
        payload.update(fields)
        # `event`/`run_id` sont aussi exposés comme attributs du record pour un
        # accès direct en test (caplog) sans parser le JSON. Aucun n'est réservé.
        _logger.log(
            levelno,
            event,
            extra={"audit": payload, "event": event, "run_id": run_id},
        )
    except Exception:  # noqa: BLE001 - fail-safe : l'audit ne casse jamais un run.
        # Dernier recours : on tente une trace minimale, elle-même protégée. On
        # n'utilise pas `raise` : un run ne doit pas échouer à cause de l'audit.
        try:  # pragma: no cover - chemin de garde extrême
            _logger.exception("audit emit failed for event %r", event)
        except Exception:  # pragma: no cover
            pass
