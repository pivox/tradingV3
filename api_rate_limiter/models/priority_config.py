# -*- coding: utf-8 -*-
"""
priority_config.py
Définition des priorités pour ApiRateLimiterClient.

Format d'entrée unique côté workflow :
    { "<bucket>": [ {...item...}, {...} ], ... }
où <bucket> ∈ order (valeurs identiques à l'enum PHP PrioInTemporal->value).

Ordre validé (du plus prioritaire au moins prioritaire) :
    position_prior
    > position
    > balance
    > 4h-cron
    > 1h-cron
    > 15m-cron
    > 5m-cron
    > 1m-cron
    > 1m
    > 5m
    > 15m
    > 1h
    > 4h
"""

from __future__ import annotations
from typing import Dict, List, Optional, Set


class PriorityConfig:
    """
    Encapsule l'ordre des priorités et quelques helpers utiles
    au workflow (validation, comparaison, sélection du prochain bucket).
    """

    # ⚠️ Garder ces chaînes strictement égales aux valeurs envoyées par PHP.
    _ORDER: List[str] = [
        "position_prior",
        "position",
        "balance",
        "4h-cron",
        "1h-cron",
        "15m-cron",
        "5m-cron",
        "1m-cron",
        "1m",
        "5m",
        "15m",
        "1h",
        "4h",
        "regular",
    ]

    def __init__(self) -> None:
        # Expose l'ordre sous forme de liste (utilisé directement par le workflow).
        self.order: List[str] = list(self._ORDER)
        # Accès O(1) pour la validation/lookup.
        self._index: Dict[str, int] = {b: i for i, b in enumerate(self.order)}
        self._known: Set[str] = set(self.order)

    # ---------- Validation & infos ----------
    def known_buckets(self) -> Set[str]:
        """Retourne l'ensemble des buckets connus."""
        return set(self._known)

    def is_known(self, bucket: str) -> bool:
        """True si le bucket est connu (valide)."""
        return bucket in self._known

    def index_of(self, bucket: str) -> int:
        """Indice de priorité (0 = plus prioritaire). Lève KeyError si inconnu."""
        return self._index[bucket]

    def compare(self, a: str, b: str) -> int:
        """
        Compare deux buckets :
            < 0 si a est plus prioritaire que b
            = 0 si même priorité
            > 0 si a est moins prioritaire que b
        """
        return self.index_of(a) - self.index_of(b)

    # ---------- Sélection utilitaire ----------
    def next_non_empty_bucket(
        self,
        queues: Dict[str, List[object]],
        paused: Optional[Set[str]] = None,
    ) -> Optional[str]:
        """
        Renvoie le prochain bucket non vide selon l'ordre et l'éventuelle liste 'paused'.
        Si tous sont vides (ou pausés), renvoie None.
        """
        paused = paused or set()
        for b in self.order:
            if b in paused:
                continue
            q = queues.get(b)
            if q:
                # Non vide ?
                if len(q) > 0:
                    return b
        return None

    # ---------- Outils pratiques ----------
    def with_paused(self, paused: Set[str]) -> List[str]:
        """
        Retourne une vue d'ordre où les buckets 'paused' sont simplement ignorés.
        (Utile si vous voulez itérer extérieurement en ignorant des buckets.)
        """
        return [b for b in self.order if b not in paused]

    def __repr__(self) -> str:
        return f"PriorityConfig(order={self.order})"
