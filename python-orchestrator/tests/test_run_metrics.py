"""Tests des métriques d'exécution par set (OBS-002).

Couvre :
- le **registre in-process** ``run_metrics`` : incréments des compteurs, histogramme
  de durée de dispatch, normalisation des labels, désactivation, **fail-safe** ;
- le **branchement** sur les points d'instrumentation OBS-001 via
  ``POST /orchestrator/run`` : un run nominal incrémente runs/sets et observe la
  durée ; un set skippé incrémente le bon compteur de skip sans dispatch ; un
  replay/in-flight n'incrémente aucun dispatch ; réconciliation avec ``summary`` ;
  une erreur interne de métrique ne fait jamais échouer un run ;
- l'**endpoint** ``GET /metrics`` (sink JSON dérivé du registre).

Les tests lisent le registre via ``run_metrics.snapshot()`` (et non un format
brut), conformément à la consigne OBS-002.
"""

from __future__ import annotations

from datetime import timedelta, timezone
from typing import Any, Dict, List

import pytest
from sqlalchemy import select

from app.db.models import Run
from app.routers import orchestrator as orch
from app.services import run_metrics

# Réutilise les helpers de seed/mocks du test du runner (DB SQLite + faux httpx).
from tests.test_orchestrator_run import (
    _FakeAsyncClient,
    _install_fake_client,
    _mtf_posts,
    _seed_dashboard,
    _seed_set,
)


@pytest.fixture(autouse=True)
def _clean_registry():
    """Isole chaque test : registre vide et activé avant, réinitialisé après."""
    run_metrics.configure(enabled=True)
    run_metrics.reset()
    yield
    run_metrics.configure(enabled=True)
    run_metrics.reset()


def _sum_values(series: List[Dict[str, Any]]) -> int:
    return sum(entry["value"] for entry in series)


def _find(series: List[Dict[str, Any]], **labels: Any) -> Dict[str, Any]:
    for entry in series:
        if all(entry.get(k) == v for k, v in labels.items()):
            return entry
    raise AssertionError(f"no series matching {labels} in {series}")


# ===========================================================================
# Registre in-process (unitaire)
# ===========================================================================


def test_counters_increment_and_snapshot_shape():
    run_metrics.observe_run_finished(status="success", total_calls=2, success=2, failed=0)
    run_metrics.observe_set_dispatched(
        exchange="bitmart", market_type="perpetual", mtf_profile="scalper_micro"
    )
    run_metrics.observe_set_result(
        exchange="bitmart",
        market_type="perpetual",
        mtf_profile="scalper_micro",
        ok=True,
        business_status="success",
        duration_ms=120,
    )
    run_metrics.observe_set_skipped(
        code="locked", exchange="bitmart", market_type="perpetual", mtf_profile="scalper_micro"
    )
    run_metrics.observe_snapshot_fetch(exchange="bitmart", market_type="perpetual", ok=True)

    snap = run_metrics.snapshot()
    assert snap["enabled"] is True
    assert set(snap.keys()) == {"enabled", "runs", "sets", "snapshots", "dispatch_duration_ms"}
    assert _find(snap["runs"], status="success")["value"] == 1
    assert _find(snap["sets"]["dispatched"], mtf_profile="scalper_micro")["value"] == 1
    assert _find(snap["sets"]["results"], ok="true", business_status="success")["value"] == 1
    assert _find(snap["sets"]["skipped"], code="locked")["value"] == 1
    assert _find(snap["snapshots"], exchange="bitmart", ok="true")["value"] == 1


def test_set_result_records_duration_histogram_with_le_buckets():
    run_metrics.observe_set_result(
        exchange="bitmart",
        market_type="perpetual",
        mtf_profile="scalper_micro",
        ok=True,
        business_status="success",
        duration_ms=300,
    )
    hist = run_metrics.snapshot()["dispatch_duration_ms"]
    assert hist["buckets"] == list(run_metrics.DISPATCH_DURATION_BUCKETS_MS)
    series = hist["series"]
    assert len(series) == 1
    entry = series[0]
    assert entry["count"] == 1
    assert entry["sum_ms"] == 300
    # Convention « le » cumulée : 300 ms tombe dans les bornes >= 500.
    assert entry["buckets"]["100"] == 0
    assert entry["buckets"]["250"] == 0
    assert entry["buckets"]["500"] == 1
    assert entry["buckets"]["+Inf"] == 1


def test_missing_business_status_label_normalized_to_unknown():
    run_metrics.observe_set_result(
        exchange="bitmart",
        market_type="perpetual",
        mtf_profile="scalper_micro",
        ok=False,
        business_status=None,
        duration_ms=None,
    )
    results = run_metrics.snapshot()["sets"]["results"]
    assert _find(results, ok="false")["business_status"] == "unknown"


def test_disabled_registry_is_noop():
    run_metrics.configure(enabled=False)
    run_metrics.observe_set_dispatched(
        exchange="bitmart", market_type="perpetual", mtf_profile="scalper_micro"
    )
    run_metrics.observe_run_finished(status="success", total_calls=1, success=1, failed=0)
    snap = run_metrics.snapshot()
    assert snap["enabled"] is False
    assert snap["runs"] == []
    assert snap["sets"]["dispatched"] == []


def test_observe_is_failsafe_when_registry_explodes(monkeypatch):
    # Une erreur interne (état corrompu) est absorbée : l'observation ne lève jamais.
    def _boom(*_a, **_k):
        raise RuntimeError("boom")

    monkeypatch.setattr(run_metrics._REGISTRY, "inc_dispatched", _boom)
    # Ne doit pas propager.
    run_metrics.observe_set_dispatched(
        exchange="bitmart", market_type="perpetual", mtf_profile="scalper_micro"
    )


# ===========================================================================
# Branchement sur les points OBS-001 (intégration runner)
# ===========================================================================


def test_nominal_run_increments_and_reconciles_with_summary(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "b", symbols=("ETHUSDT",))
    _seed_set(session, dash.id, "c", symbols=("XRPUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["summary"] == {"total_calls": 3, "success": 3, "failed": 0}

    snap = run_metrics.snapshot()
    # Run terminé compté par status.
    assert _find(snap["runs"], status="success")["value"] == 1
    # Réconciliation avec summary (run nominal : aucun skip ni reprise).
    assert _sum_values(snap["sets"]["dispatched"]) == body["summary"]["total_calls"]
    ok_results = [r for r in snap["sets"]["results"] if r["ok"] == "true"]
    failed_results = [r for r in snap["sets"]["results"] if r["ok"] == "false"]
    assert _sum_values(ok_results) == body["summary"]["success"]
    assert _sum_values(failed_results) == body["summary"]["failed"]
    # Durée de dispatch observée pour chaque set dispatché.
    hist_count = sum(s["count"] for s in snap["dispatch_duration_ms"]["series"])
    assert hist_count == 3


def test_skipped_live_off_increments_skip_code_without_dispatch(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    # Interrupteur live OFF (défaut) : un set live est skippé fail-closed.
    _seed_set(session, dash.id, "live", exchange="bitmart", dry_run=False, symbols=("BTCUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)})

    snap = run_metrics.snapshot()
    assert _find(snap["sets"]["skipped"], code="live_not_enabled")["value"] == 1
    # Aucun dispatch ni résultat pour un set skippé.
    assert snap["sets"]["dispatched"] == []
    assert snap["sets"]["results"] == []


def test_locked_and_not_materialized_skips_use_stable_codes(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    # `not_materialized` : set valide mais sans symbole concret (aucun POST).
    _seed_set(session, dash.id, "empty", exchange="bitmart", symbols=())
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)})

    snap = run_metrics.snapshot()
    assert _find(snap["sets"]["skipped"], code="not_materialized")["value"] == 1
    assert snap["sets"]["dispatched"] == []


def test_replay_does_not_increment_dispatch_counters(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    payload = {"dashboard_id": str(dash.id), "idempotency_key": "mrk1"}
    first = client.post("/orchestrator/run", json=payload).json()
    assert first["ok"] is True

    # Deuxième appel = REPLAY (aucun dispatch). On repart d'un registre propre pour
    # n'observer que ce que le replay incrémente (rien).
    run_metrics.reset()
    second = client.post("/orchestrator/run", json=payload).json()
    assert second["run_id"] == first["run_id"]
    assert len(_mtf_posts(fake)) == 1  # pas de POST supplémentaire

    snap = run_metrics.snapshot()
    assert snap["sets"]["dispatched"] == []
    assert snap["sets"]["results"] == []
    assert snap["runs"] == []  # replay émet run_short_circuit, pas run_finished


def test_in_flight_does_not_increment_dispatch_counters(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))

    now = orch._now()
    # Un run concurrent est déjà EN VOL (claim running non périmé) sous la même clé.
    session.add(
        Run(
            run_id="run_mik",
            idempotency_key="mik",
            ok=False,
            status="running",
            total_calls=0,
            success_count=0,
            failed_count=0,
            started_at=now,
            expires_at=now + timedelta(seconds=3600),
        )
    )
    session.commit()
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    run_metrics.reset()
    body = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "idempotency_key": "mik"}
    ).json()
    assert body["status"] == "running"
    assert _mtf_posts(fake) == []

    snap = run_metrics.snapshot()
    assert snap["sets"]["dispatched"] == []
    assert snap["runs"] == []


def test_metric_internal_error_does_not_fail_run(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    # Une erreur interne de métrique (registre cassé) ne doit jamais casser un run.
    def _boom(*_a, **_k):
        raise RuntimeError("metric boom")

    monkeypatch.setattr(run_metrics._REGISTRY, "inc_dispatched", _boom)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    # Le run se termine normalement (comportement métier inchangé).
    assert body["ok"] is True
    assert body["summary"] == {"total_calls": 1, "success": 1, "failed": 0}


# ===========================================================================
# Endpoint /metrics (sink JSON)
# ===========================================================================


def test_metrics_endpoint_exposes_snapshot(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())
    client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)})

    resp = client.get("/metrics")
    assert resp.status_code == 200
    data = resp.json()
    assert set(data.keys()) == {"enabled", "runs", "sets", "snapshots", "dispatch_duration_ms"}
    assert _find(data["runs"], status="success")["value"] == 1
    assert _sum_values(data["sets"]["dispatched"]) == 1
