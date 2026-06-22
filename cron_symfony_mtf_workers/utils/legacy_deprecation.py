"""Helpers de dépréciation du chemin legacy multi-jobs (CLEAN-001).

Le chemin legacy (``CronSymfonyMtfWorkersWorkflow`` + ``mtf_api_call`` et ses
trois scripts de schedule ``manage_mtf_workers`` / ``manage_scalper_micro`` /
``manage_exchange_profile``) reste 100 % fonctionnel pendant la transition, mais
est **déprécié** : la cible est le schedule orchestrateur unique
(``scripts/manage_orchestrator_schedule.py`` → ``POST /orchestrator/run``).

Ce module centralise le message et l'émission d'un ``DeprecationWarning`` afin
que tous les points d'entrée legacy pointent vers la même cible. Il n'effectue
aucune I/O et ne lève jamais d'exception : le legacy doit continuer de
fonctionner après l'avertissement.

Hors-scope couverture QA-003 : ce helper vit dans ``utils/`` (hors du périmètre
``source`` mesuré), au même titre que le code legacy qu'il dessert.
"""

from __future__ import annotations

import warnings

# Cible de migration unique (CLEAN-001).
TARGET_SCRIPT = "scripts/manage_orchestrator_schedule.py"

#: Message de dépréciation partagé par tous les points d'entrée legacy.
LEGACY_DEPRECATION_MESSAGE = (
    "DEPRECATED (CLEAN-001): le chemin legacy multi-jobs "
    "(CronSymfonyMtfWorkersWorkflow / mtf_api_call et les schedules "
    "manage_mtf_workers / manage_scalper_micro / manage_exchange_profile) est "
    "déprécié. Utiliser le schedule orchestrateur unique "
    f"({TARGET_SCRIPT} → POST /orchestrator/run). Le legacy reste fonctionnel "
    "pendant la transition mais sera supprimé dans un jalon ultérieur."
)


def legacy_deprecation_message(component: str) -> str:
    """Construit le message de dépréciation préfixé par le composant legacy."""
    return f"{component}: {LEGACY_DEPRECATION_MESSAGE}"


def warn_legacy_deprecation(component: str, *, stacklevel: int = 2) -> str:
    """Émet un ``DeprecationWarning`` pour un point d'entrée legacy.

    Args:
        component: nom du composant legacy invoqué (ex. le script).
        stacklevel: niveau de pile relatif à l'appelant direct du helper, afin
            que l'avertissement pointe vers le code legacy et non vers ce module.

    Returns:
        Le message émis (utile pour le journaliser également).

    Ne lève jamais d'exception : le chemin legacy reste utilisable.
    """
    message = legacy_deprecation_message(component)
    # Garantie « ne lève jamais » : sous un filtre transformant les
    # DeprecationWarning en erreurs (``PYTHONWARNINGS=error::DeprecationWarning``
    # ou ``-W error``), un ``warnings.warn`` nu *relèverait* l'avertissement comme
    # exception et casserait le CLI legacy (y compris ``--help``). On force donc
    # localement le filtre ``always`` : l'avertissement reste émis et VISIBLE,
    # mais ne peut pas être escaladé en exception, quel que soit le filtre global.
    with warnings.catch_warnings():
        warnings.simplefilter("always", DeprecationWarning)
        # ``stacklevel + 1`` pour compenser cette fonction intermédiaire.
        warnings.warn(message, DeprecationWarning, stacklevel=stacklevel + 1)
    return message
