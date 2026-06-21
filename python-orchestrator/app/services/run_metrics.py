"""Métriques d'exécution agrégées des runs d'orchestration (OBS-002).

OBS-001 a posé un **flux d'événements** (audit JSON line corrélé par ``run_id``)
et un **historique DB** (``runs`` / ``run_sets``). Il manquait la couche
**quantitative** : compteurs et distribution de durées, prêts à grapher/alerter
(taux de skip par cause, succès par exchange/profil, débit de runs, lenteur de
dispatch) sans agréger des logs ou requêter la base à la main.

Ce module expose un **registre in-process** alimenté aux **mêmes points
d'instrumentation** que ``run_audit`` (``set_dispatched`` / ``set_result`` /
``set_skipped`` / ``snapshot_fetch`` / ``run_finished``). Il ne redéfinit aucune
cause métier : il réutilise les ``code``/``status`` STABLES d'OBS-001 / SAFE-003
comme labels. Le sink (décision produit OBS-002) est un **endpoint JSON dérivé**
du registre (``GET /metrics``, cf. ``app/routers/metrics.py``) : aucune migration,
aucune écriture DB, aucune dépendance supplémentaire.

Invariants :

- **Fail-safe** : une erreur de métrique (clé non hashable, état corrompu) ne
  fait JAMAIS échouer ni ralentir un run. Chaque ``observe_*`` est encapsulé dans
  un ``try/except`` interne et ne lève jamais.
- **Cardinalité bornée** (décision produit OBS-002) : labels limités à
  ``exchange`` / ``market_type`` / ``mtf_profile`` (+ ``code`` de skip,
  ``business_status``, ``status`` de run). Ni ``set_id`` ni ``dashboard_id`` (qui
  feraient exploser le nombre de séries).
- **Cohérence** : les compteurs complètent l'audit et l'historique ; sur un run
  nominal (sans skip ni reprise), ``sets_dispatched`` réconcilie avec
  ``summary.total_calls`` et ``sets_result`` (ok=true / false) avec
  ``summary.success`` / ``summary.failed``.

Le module est désactivable via ``configure(enabled=False)`` (piloté par
``ORCHESTRATION_METRICS_ENABLED`` au démarrage) : les ``observe_*`` deviennent
alors des no-op et le snapshot reste vide.
"""

from __future__ import annotations

import threading
from typing import Any, Dict, List, Optional, Tuple

# Bornes (ms) de l'histogramme de durée de dispatch (``set_result.duration_ms``).
# La durée mesure tout l'appel Symfony (jusqu'à ``_HTTP_TIMEOUT`` = 900 s), donc
# les bornes montent jusqu'à 900 000 ms. Convention Prometheus « le » (cumulatif) :
# chaque borne compte les observations <= borne ; ``+Inf`` = total.
DISPATCH_DURATION_BUCKETS_MS: Tuple[int, ...] = (
    100,
    250,
    500,
    1000,
    2500,
    5000,
    10000,
    30000,
    60000,
    120000,
    300000,
    600000,
    900000,
)

# Valeur de repli pour un label absent/None (ex. ``business_status`` non renvoyé
# par Symfony sur erreur HTTP) — on ne laisse jamais ``None`` polluer une clé.
_UNKNOWN = "unknown"


def _label(value: Any) -> str:
    """Normalise une valeur de label en chaîne stable (None → ``unknown``)."""
    if value is None:
        return _UNKNOWN
    return str(value)


class _Registry:
    """Registre in-process thread-safe des métriques d'exécution.

    Les ``observe_*`` sont appelés depuis la boucle asyncio du runner ; le snapshot
    JSON est lu depuis le threadpool FastAPI (endpoint sync). Un ``Lock`` protège
    donc l'état partagé. Les compteurs sont des ``dict`` indexés par tuple de
    valeurs de labels ; l'histogramme stocke, par tuple de labels, le total, la
    somme et les compteurs cumulés par borne.
    """

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._enabled = True
        self._reset_locked()

    def _reset_locked(self) -> None:
        self._runs: Dict[Tuple[str, ...], int] = {}            # (status,)
        self._dispatched: Dict[Tuple[str, ...], int] = {}      # (exchange, market_type, mtf_profile)
        self._results: Dict[Tuple[str, ...], int] = {}         # (exchange, market_type, mtf_profile, ok, business_status)
        self._skipped: Dict[Tuple[str, ...], int] = {}         # (code, exchange, market_type, mtf_profile)
        self._snapshots: Dict[Tuple[str, ...], int] = {}       # (exchange, market_type, ok)
        # (exchange, market_type, mtf_profile) -> {"count", "sum", "le": [..]}
        self._duration: Dict[Tuple[str, ...], Dict[str, Any]] = {}

    # --- configuration ------------------------------------------------------

    def configure(self, *, enabled: bool) -> None:
        with self._lock:
            self._enabled = enabled

    def reset(self) -> None:
        """Remet le registre à zéro (tests) sans toucher au flag ``enabled``."""
        with self._lock:
            self._reset_locked()

    # --- observations (fail-safe via les wrappers module) -------------------

    def inc_run(self, status: Any) -> None:
        with self._lock:
            if not self._enabled:
                return
            key = (_label(status),)
            self._runs[key] = self._runs.get(key, 0) + 1

    def inc_dispatched(self, exchange: Any, market_type: Any, mtf_profile: Any) -> None:
        with self._lock:
            if not self._enabled:
                return
            key = (_label(exchange), _label(market_type), _label(mtf_profile))
            self._dispatched[key] = self._dispatched.get(key, 0) + 1

    def inc_result(
        self,
        exchange: Any,
        market_type: Any,
        mtf_profile: Any,
        ok: bool,
        business_status: Any,
        duration_ms: Optional[int],
    ) -> None:
        with self._lock:
            if not self._enabled:
                return
            key = (
                _label(exchange),
                _label(market_type),
                _label(mtf_profile),
                "true" if ok else "false",
                _label(business_status),
            )
            self._results[key] = self._results.get(key, 0) + 1
            if duration_ms is not None:
                self._observe_duration_locked(exchange, market_type, mtf_profile, duration_ms)

    def _observe_duration_locked(
        self, exchange: Any, market_type: Any, mtf_profile: Any, duration_ms: int
    ) -> None:
        key = (_label(exchange), _label(market_type), _label(mtf_profile))
        data = self._duration.get(key)
        if data is None:
            data = {"count": 0, "sum": 0, "le": [0] * len(DISPATCH_DURATION_BUCKETS_MS)}
            self._duration[key] = data
        data["count"] += 1
        data["sum"] += int(duration_ms)
        for i, bound in enumerate(DISPATCH_DURATION_BUCKETS_MS):
            if duration_ms <= bound:
                data["le"][i] += 1

    def inc_skipped(
        self, code: Any, exchange: Any, market_type: Any, mtf_profile: Any
    ) -> None:
        with self._lock:
            if not self._enabled:
                return
            key = (_label(code), _label(exchange), _label(market_type), _label(mtf_profile))
            self._skipped[key] = self._skipped.get(key, 0) + 1

    def inc_snapshot(self, exchange: Any, market_type: Any, ok: bool) -> None:
        with self._lock:
            if not self._enabled:
                return
            key = (_label(exchange), _label(market_type), "true" if ok else "false")
            self._snapshots[key] = self._snapshots.get(key, 0) + 1

    # --- exposition ---------------------------------------------------------

    def snapshot(self) -> Dict[str, Any]:
        """Sérialise le registre en dict JSON déterministe (trié)."""
        with self._lock:
            return {
                "enabled": self._enabled,
                "runs": _series(self._runs, ("status",)),
                "sets": {
                    "dispatched": _series(
                        self._dispatched, ("exchange", "market_type", "mtf_profile")
                    ),
                    "results": _series(
                        self._results,
                        ("exchange", "market_type", "mtf_profile", "ok", "business_status"),
                    ),
                    "skipped": _series(
                        self._skipped, ("code", "exchange", "market_type", "mtf_profile")
                    ),
                },
                "snapshots": _series(self._snapshots, ("exchange", "market_type", "ok")),
                "dispatch_duration_ms": {
                    "buckets": list(DISPATCH_DURATION_BUCKETS_MS),
                    "series": _hist_series(
                        self._duration, ("exchange", "market_type", "mtf_profile")
                    ),
                },
            }


def _series(counter: Dict[Tuple[str, ...], int], label_names: Tuple[str, ...]) -> List[Dict[str, Any]]:
    """Sérialise un compteur indexé par tuple en liste triée de séries labellées."""
    out: List[Dict[str, Any]] = []
    for key in sorted(counter):
        entry: Dict[str, Any] = dict(zip(label_names, key))
        entry["value"] = counter[key]
        out.append(entry)
    return out


def _hist_series(
    hist: Dict[Tuple[str, ...], Dict[str, Any]], label_names: Tuple[str, ...]
) -> List[Dict[str, Any]]:
    """Sérialise l'histogramme : bornes cumulées « le » + ``+Inf`` (= count) + somme."""
    out: List[Dict[str, Any]] = []
    for key in sorted(hist):
        data = hist[key]
        entry: Dict[str, Any] = dict(zip(label_names, key))
        buckets = {str(bound): data["le"][i] for i, bound in enumerate(DISPATCH_DURATION_BUCKETS_MS)}
        buckets["+Inf"] = data["count"]
        entry["count"] = data["count"]
        entry["sum_ms"] = data["sum"]
        entry["buckets"] = buckets
        out.append(entry)
    return out


# Singleton module : un seul registre partagé par le runner et l'endpoint.
_REGISTRY = _Registry()


def configure(*, enabled: bool) -> None:
    """Active/désactive le registre au démarrage (``ORCHESTRATION_METRICS_ENABLED``)."""
    _REGISTRY.configure(enabled=enabled)


def reset() -> None:
    """Remet le registre à zéro (tests)."""
    _REGISTRY.reset()


def snapshot() -> Dict[str, Any]:
    """Snapshot JSON déterministe du registre (consommé par ``GET /metrics``)."""
    try:
        return _REGISTRY.snapshot()
    except Exception:  # noqa: BLE001 - fail-safe : l'exposition ne casse jamais.
        return {"enabled": False, "error": "metrics snapshot failed"}


# --- API d'observation, alignée 1:1 sur les points d'audit OBS-001 ----------
#
# Chaque fonction est fail-safe : une erreur interne (clé non hashable, état
# corrompu) est absorbée et ne fait jamais échouer ni ralentir un run.


def observe_run_finished(*, status: Any, total_calls: int, success: int, failed: int) -> None:
    """Compteur de runs par ``status`` (point ``run_finished``).

    ``total_calls`` / ``success`` / ``failed`` ne sont pas re-comptés ici (ils
    découlent des observations par set : ``sets_dispatched`` / ``sets_result``) ;
    ils restent dans la signature pour rappeler la réconciliation avec ``summary``.
    """
    try:
        _REGISTRY.inc_run(status)
    except Exception:  # noqa: BLE001
        pass


def observe_set_dispatched(*, exchange: Any, market_type: Any, mtf_profile: Any) -> None:
    """Compteur de sets effectivement dispatchés (point ``set_dispatched``)."""
    try:
        _REGISTRY.inc_dispatched(exchange, market_type, mtf_profile)
    except Exception:  # noqa: BLE001
        pass


def observe_set_result(
    *,
    exchange: Any,
    market_type: Any,
    mtf_profile: Any,
    ok: bool,
    business_status: Any,
    duration_ms: Optional[int],
) -> None:
    """Compteur de résultats (ok/échec + ``business_status``) et histogramme de durée
    (point ``set_result``)."""
    try:
        _REGISTRY.inc_result(exchange, market_type, mtf_profile, bool(ok), business_status, duration_ms)
    except Exception:  # noqa: BLE001
        pass


def observe_set_skipped(
    *, code: Any, exchange: Any, market_type: Any, mtf_profile: Any
) -> None:
    """Compteur de skips par ``code`` STABLE (OBS-001/SAFE-003) (point ``set_skipped``)."""
    try:
        _REGISTRY.inc_skipped(code, exchange, market_type, mtf_profile)
    except Exception:  # noqa: BLE001
        pass


def observe_snapshot_fetch(*, exchange: Any, market_type: Any, ok: bool) -> None:
    """Compteur de fetch d'état ouvert (ok/indispo) (point ``snapshot_fetch``)."""
    try:
        _REGISTRY.inc_snapshot(exchange, market_type, bool(ok))
    except Exception:  # noqa: BLE001
        pass
