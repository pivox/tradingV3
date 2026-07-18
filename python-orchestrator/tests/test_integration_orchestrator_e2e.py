"""QA-002 — Tests d'intégration bout-en-bout de ``POST /orchestrator/run``.

Exerce le runner DB-backed (``app/routers/orchestrator.py``) + ``symfony_client``
contre le **faux Symfony à la frontière HTTP** (``FakeSymfony`` / fixture ``symfony``)
: un VRAI ``httpx.AsyncClient`` adossé à un ``httpx.MockTransport`` scénarisé, sans
socket ni backend Symfony/PostgreSQL applicatif. La DB est une SQLite in-memory
(fixture ``orchestrator_env`` de QA-001).

À la différence du double client ``_FakeAsyncClient`` de ``test_orchestrator_run.py``,
ce module fait transiter la vraie sérialisation/parsing JSON et les en-têtes HTTP, et
vérifie : la forme EXACTE du payload ``/api/mtf/run`` (``sync_tables=false`` /
``process_tp_sl=false``, override ``dry_run``, snapshot), le fail-closed 502 sans
écriture partielle, la propagation ``X-Run-Id``, le parallélisme borné par
``MAX_CONCURRENCY``, le mapping ``ok=false`` → ``Run.ok=false``, l'idempotence
(SAFE-002), l'audit (OBS-001) et les métriques (OBS-002).
"""

from __future__ import annotations

from typing import Any, List

import pytest

from app.routers import orchestrator as orch
from app.db.models import Run, RunSet
from app.services import run_metrics

# Réutilise les helpers de seed et l'agrégation de l'event d'audit de QA-001.
from tests.test_orchestrator_run import (
    _by_event,
    _seed_dashboard,
    _seed_set,
)


@pytest.fixture(autouse=True)
def _clean_metrics_registry():
    """Isole le registre de métriques OBS-002 entre tests (cf. test_run_metrics)."""
    run_metrics.configure(enabled=True)
    run_metrics.reset()
    yield
    run_metrics.configure(enabled=True)
    run_metrics.reset()


def _mtf_runs(fake) -> List[dict]:
    return fake.run_requests


# ===========================================================================
# Contrat du payload /api/mtf/run (forme exacte sur le fil)
# ===========================================================================


def test_mtf_run_payload_contract_on_the_wire(orchestrator_env, symfony):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",), mtf_profile="scalper_micro")
    snapshot = {"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []}
    fake = symfony(open_state=snapshot)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["ok"] is True

    assert len(fake.run_requests) == 1
    sent = fake.run_requests[0]["json"]
    assert sent["sync_tables"] is False
    assert sent["process_tp_sl"] is False
    assert sent["dry_run"] is True
    assert sent["workers"] == 1
    assert sent["symbols"] == ["BTCUSDT"]
    assert sent["mtf_profile"] == "scalper_micro"
    assert sent["open_state_snapshot"] == snapshot
    # Allow-list stricte : aucune clé hors contrat ne part sur le fil.
    assert set(sent.keys()) <= {
        "dry_run", "workers", "exchange", "market_type", "mtf_profile",
        "sync_tables", "process_tp_sl", "symbols", "config_hash",
        "open_state_snapshot",
    }


def test_dry_run_override_propagated_to_symfony(orchestrator_env, symfony, monkeypatch):
    # Set live + fetch open-state en échec : sans override il serait skippé fail-closed.
    # Avec {"dry_run": true}, il est forcé en dry-run, donc dispatché avec dry_run=true.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "live", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    fake = symfony(open_state_status=503)

    body = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "dry_run": True}
    ).json()

    assert body["ok"] is True
    assert len(fake.run_requests) == 1
    assert fake.run_requests[0]["json"]["dry_run"] is True


def test_no_snapshot_key_when_couple_uncached(orchestrator_env, symfony):
    # Dry-run sans snapshot fiable (open-state 500) : exécuté, payload sans snapshot.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "dry", dry_run=True, exchange="bitmart", symbols=("BTCUSDT",))
    fake = symfony(open_state_status=500)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["ok"] is True
    sent = fake.run_requests[0]["json"]
    assert "open_state_snapshot" not in sent
    assert sent["sync_tables"] is False


# ===========================================================================
# X-Run-Id (OBS-001)
# ===========================================================================


def test_x_run_id_propagated_to_every_mtf_run(orchestrator_env, symfony):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "b", symbols=("ETHUSDT",))
    fake = symfony()

    body = client.post(
        "/orchestrator/run",
        json={"dashboard_id": str(dash.id), "idempotency_key": "trace_e2e"},
    ).json()

    assert len(fake.run_requests) == 2
    for req in fake.run_requests:
        assert req["headers"].get("x-run-id") == body["run_id"]


# ===========================================================================
# Fail-closed 502 sans écriture partielle
# ===========================================================================


def test_run_endpoint_502_maps_set_failed_and_run_not_ok(orchestrator_env, symfony):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    fake = symfony(run_status=502, run_response={"status": "error", "message": "upstream boom"})

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["status"] == "failed"
    assert body["summary"] == {"total_calls": 1, "success": 0, "failed": 1}

    # Historique persisté : Run.ok=false + RunSet en échec porteur du message.
    session.expire_all()
    run = session.get(Run, body["run_id"])
    assert run is not None and run.ok is False
    run_sets = list(session.query(RunSet).filter_by(run_id=body["run_id"]))
    assert len(run_sets) == 1
    assert run_sets[0].ok is False
    assert "upstream boom" in (run_sets[0].error or "")


def test_live_set_fail_closed_on_snapshot_502_without_dispatch(
    orchestrator_env, symfony, monkeypatch
):
    # Fail-closed live SANS écriture partielle : le snapshot d'état ouvert revient en
    # 502 ; un set live (live activé + bitmart allow-listé) ne doit déclencher AUCUN
    # POST /api/mtf/run (on ne trade pas à l'aveugle), être compté en échec, et le run
    # global rester ok=false.
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", "true")
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", "bitmart")
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "live", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    fake = symfony(open_state_status=502)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 1, "success": 0, "failed": 1}
    # Aucune écriture partielle : zéro dispatch /api/mtf/run.
    assert fake.run_requests == []
    # Le couple a bien été interrogé une fois (puis fail-closed).
    assert len(fake.open_state_requests) == 1


def test_run_endpoint_timeout_maps_set_failed(orchestrator_env, symfony):
    # Le POST /api/mtf/run lève une httpx.HTTPError (timeout) : capturée par le runner
    # en échec de set, run global ok=false, sans interrompre les autres sets.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    fake = symfony(run_raise=True)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["ok"] is False
    assert body["summary"]["failed"] == 1
    assert len(fake.run_requests) == 1  # tentative effectuée, échec transport


# ===========================================================================
# ok=false -> Run.ok=false (mapping métier)
# ===========================================================================


@pytest.mark.parametrize(
    "run_body",
    [
        {"status": "rejected"},
        {"status": "partial_success", "data": {"errors": ["boom"]}},
        {"status": "completed_with_errors"},
    ],
)
def test_business_failure_maps_run_ok_false(orchestrator_env, symfony, run_body):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    symfony(run_response=run_body)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["ok"] is False
    assert body["status"] == "failed"

    session.expire_all()
    assert session.get(Run, body["run_id"]).ok is False


def test_partial_failure_when_one_set_business_fails(orchestrator_env, symfony):
    # Un set échoue (par symbole), l'autre réussit => partial_failure, Run.ok=false.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "good", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "bad", symbols=("ETHUSDT",))

    def _handler(_request, body_json):
        symbols = (body_json or {}).get("symbols") or []
        if "ETHUSDT" in symbols:
            return 200, {"status": "partial_success", "data": {"errors": ["ETHUSDT: boom"]}}
        return 200, {"status": "success"}

    symfony(run_handler=_handler)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["ok"] is False
    assert body["status"] == "partial_failure"
    assert body["summary"] == {"total_calls": 2, "success": 1, "failed": 1}


# ===========================================================================
# Parallélisme borné par MAX_CONCURRENCY
# ===========================================================================


@pytest.mark.parametrize("concurrency,n_sets,expected_max", [(2, 6, 2), (3, 6, 3), (4, 2, 2)])
def test_bounded_parallelism_respects_max_concurrency(
    orchestrator_env, symfony, monkeypatch, concurrency, n_sets, expected_max
):
    monkeypatch.setenv("MAX_CONCURRENCY", str(concurrency))
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    # Sets dry-run, symboles distincts (aucun conflit de lock / live), même couple
    # (un seul snapshot) => ils ne diffèrent que par leur sélection.
    for i in range(n_sets):
        _seed_set(session, dash.id, f"s{i}", symbols=(f"SYM{i}USDT",))
    fake = symfony()

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["summary"]["total_calls"] == n_sets
    assert len(fake.run_requests) == n_sets
    # Jamais au-delà de la borne, et la borne est effectivement atteinte.
    assert fake.max_in_flight <= concurrency
    assert fake.max_in_flight == expected_max


def test_same_symbol_fake_profiles_coexist_with_distinct_lineage_hashes_and_bounded_parallelism(
    orchestrator_env, symfony, monkeypatch
):
    monkeypatch.setenv("MAX_CONCURRENCY", "2")
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    for profile in ("regular", "scalper", "scalper_micro"):
        _seed_set(
            session,
            dash.id,
            f"multi-{profile}",
            exchange="fake",
            dry_run=True,
            mtf_profile=profile,
            symbols=("BTCUSDT",),
        )
    fake = symfony()

    response = client.post(
        "/orchestrator/run",
        json={
            "dashboard_id": str(dash.id),
            "idempotency_key": "golden20-same-symbol",
            "dry_run": True,
        },
    ).json()

    assert response["summary"] == {"total_calls": 3, "success": 3, "failed": 0}
    assert fake.max_in_flight == 2
    assert {request["json"]["exchange"] for request in fake.run_requests} == {"fake"}
    assert {tuple(request["json"]["symbols"]) for request in fake.run_requests} == {
        ("BTCUSDT",)
    }
    assert len({request["json"]["config_hash"] for request in fake.run_requests}) == 3


# ===========================================================================
# Idempotence (SAFE-002) : replay sans re-dispatch
# ===========================================================================


def test_idempotent_replay_does_not_redispatch(orchestrator_env, symfony):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    fake = symfony()

    payload = {"dashboard_id": str(dash.id), "idempotency_key": "idem_e2e"}
    first = client.post("/orchestrator/run", json=payload).json()
    assert first["ok"] is True
    assert len(fake.run_requests) == 1

    # Second appel à clé identique : replay du succès persisté, AUCUN nouvel appel.
    second = client.post("/orchestrator/run", json=payload).json()
    assert second["run_id"] == first["run_id"]
    assert second["ok"] is True
    assert second["summary"] == first["summary"]
    assert len(fake.run_requests) == 1  # pas de re-dispatch


# ===========================================================================
# Audit (OBS-001) + métriques (OBS-002) cohérents avec les appels simulés
# ===========================================================================


def test_audit_and_metrics_consistent_with_simulated_calls(
    orchestrator_env, symfony, audit_records
):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",), mtf_profile="scalper_micro")
    _seed_set(session, dash.id, "b", symbols=("ETHUSDT",), mtf_profile="scalper_micro")
    fake = symfony()

    body = client.post(
        "/orchestrator/run",
        json={"dashboard_id": str(dash.id), "idempotency_key": "obs_e2e"},
    ).json()
    assert body["summary"] == {"total_calls": 2, "success": 2, "failed": 0}

    # OBS-001 : flux d'audit corrélé par le run_id réellement persisté.
    started = _by_event(audit_records, "run_started")
    assert started and all(r.audit["run_id"] == body["run_id"] for r in started)
    dispatched = _by_event(audit_records, "set_dispatched")
    assert sorted(r.audit["set_id"] for r in dispatched) == ["a", "b"]
    results = _by_event(audit_records, "set_result")
    assert len(results) == 2 and all(r.audit["ok"] is True for r in results)
    finished = _by_event(audit_records, "run_finished")
    assert len(finished) == 1
    assert finished[0].audit["status"] == "success"
    assert finished[0].audit["success"] == 2

    # OBS-002 : compteurs réconciliés avec le summary et les appels simulés.
    snap = run_metrics.snapshot()
    dispatched_total = sum(e["value"] for e in snap["sets"]["dispatched"])
    assert dispatched_total == body["summary"]["total_calls"] == len(fake.run_requests)
    ok_results = [e for e in snap["sets"]["results"] if e["ok"] == "true"]
    assert sum(e["value"] for e in ok_results) == body["summary"]["success"]
    assert any(e["status"] == "success" for e in snap["runs"])
