"""Identifiant de corrélation canonique run d'orchestration ↔ trades (OBS-003).

Un run d'orchestration porte un ``run_id`` d'origine (``runs.run_id``, jusqu'à 255
caractères, éventuellement haché et préfixé ``run_``). Côté Symfony, les trades sont
rattachés via ``trade_lifecycle_event.run_id`` qui est un ``VARCHAR(64)``. Pour relier
les deux **sans collision** (deux identifiants longs partageant les mêmes 64 premiers
caractères ne doivent pas se confondre), on dérive un *identifiant de corrélation
canonique* déterministe, et **identique** à l'implémentation PHP
(``App\\Trading\\Service\\RunCorrelationId``).

Règle (cf. ``tests/fixtures/run_correlation_vectors.json``, partagé Python ↔ PHP) :

1. une chaîne vide est refusée (``ValueError``) — un run d'orchestration a toujours
   un ``run_id`` ;
2. si ``run_id`` respecte ``^[A-Za-z0-9._:-]+$`` ET ``len <= 64`` : il est conservé
   tel quel (lisible, déjà compatible avec la colonne) ;
3. sinon : ``sha256(run_id)`` en hexadécimal minuscule (exactement 64 caractères).

Interdits explicites : aucune troncature silencieuse (``run_id[:64]``), aucun UUID
aléatoire pour un run issu de l'orchestrateur, aucune divergence d'algorithme entre
Python et PHP.
"""

from __future__ import annotations

import hashlib
import re

# Caractères « sûrs » d'un identifiant conservé tel quel (miroir exact du PHP).
_SAFE_RUN_ID = re.compile(r"^[A-Za-z0-9._:-]+$")

# Largeur de ``trade_lifecycle_event.run_id`` (== ``position_trade_analysis.run_id``).
CORRELATION_ID_MAX_LEN = 64


def canonical_correlation_id(run_id: str) -> str:
    """Dérive l'identifiant de corrélation canonique (≤ 64 caractères) d'un run.

    Déterministe et sans collision : deux ``run_id`` distincts produisent toujours
    deux identifiants distincts (les longs/non-sûrs passent par ``sha256``, jamais
    par une troncature). Voir le module pour la règle complète.

    :raises ValueError: si ``run_id`` est vide ou n'est pas une chaîne.
    """
    if not isinstance(run_id, str) or run_id == "":
        raise ValueError("run_id must be a non-empty string")

    if len(run_id) <= CORRELATION_ID_MAX_LEN and _SAFE_RUN_ID.match(run_id):
        return run_id

    return hashlib.sha256(run_id.encode("utf-8")).hexdigest()
