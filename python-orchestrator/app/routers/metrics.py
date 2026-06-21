"""Exposition des métriques d'exécution des runs d'orchestration (OBS-002).

Sink retenu (décision produit OBS-002) : un **endpoint JSON dérivé** du registre
in-process (``app/services/run_metrics.py``), alimenté aux mêmes points
d'instrumentation qu'OBS-001. Aucune migration, aucune écriture DB, aucune I/O
bloquante : l'endpoint sérialise simplement le snapshot courant des compteurs et
de l'histogramme de durée de dispatch.

Lecture seule, fail-safe : un échec de sérialisation du registre ne lève pas (le
snapshot retombe sur ``{"enabled": false, "error": …}``). Cardinalité bornée :
labels limités à ``exchange`` / ``market_type`` / ``mtf_profile`` (+ ``code`` de
skip, ``business_status``, ``status`` de run) — ni ``set_id`` ni ``dashboard_id``.
"""

from __future__ import annotations

from typing import Any, Dict

from fastapi import APIRouter

from app.services import run_metrics

router = APIRouter(tags=["metrics"])


@router.get("/metrics")
def get_metrics() -> Dict[str, Any]:
    """Snapshot JSON des métriques agrégées d'exécution par set (OBS-002).

    Compteurs : runs par ``status`` ; sets ``dispatched`` / ``results``
    (ok/échec + ``business_status``) / ``skipped`` (par ``code``) ; ``snapshots``
    (ok/indispo). Histogramme : durée de dispatch (``set_result.duration_ms``,
    bornes ms cumulées « le » + ``+Inf``).
    """
    return run_metrics.snapshot()
