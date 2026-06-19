"""Tests des endpoints de lecture de l'historique des runs (PY-006).

Lecture seule : on seede des ``Run``/``RunSet`` en direct ORM (l'écriture est
faite par PY-005) puis on relit le dernier JSON global et par set via les
endpoints GET. La DB est une SQLite in-memory partagée (fixture
``orchestrator_env``) ; aucun PostgreSQL ni Symfony requis.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

from app.db.models import Dashboard, Run, RunSet

_BASE = datetime(2026, 6, 17, 12, 0, 0, tzinfo=timezone.utc)


def _seed_dashboard(session, name: str = "dash") -> Dashboard:
    dashboard = Dashboard(name=name, enabled=True)
    session.add(dashboard)
    session.commit()
    return dashboard


def _seed_run(
    session,
    run_id: str,
    *,
    dashboard_id=None,
    ok: bool = True,
    status: str = "success",
    total: int = 1,
    success: int = 1,
    failed: int = 0,
    created_at: datetime = _BASE,
    last_json=None,
) -> Run:
    run = Run(
        run_id=run_id,
        dashboard_id=dashboard_id,
        ok=ok,
        status=status,
        total_calls=total,
        success_count=success,
        failed_count=failed,
        started_at=created_at,
        finished_at=created_at,
        created_at=created_at,
        last_json=last_json if last_json is not None else {"run_id": run_id, "sets": []},
    )
    session.add(run)
    session.commit()
    return run


def _seed_run_set(
    session,
    run_id: str,
    set_id: str,
    *,
    ok: bool = True,
    error=None,
    payload_sent=None,
    response_json=None,
    duration_ms: int = 12,
) -> RunSet:
    run_set = RunSet(
        run_id=run_id,
        set_id=set_id,
        ok=ok,
        error=error,
        payload_sent=payload_sent if payload_sent is not None else {"symbols": ["BTCUSDT"]},
        response_json=response_json if response_json is not None else {"status": "success"},
        duration_ms=duration_ms,
    )
    session.add(run_set)
    session.commit()
    return run_set


# --------------------------------------------------------------------------
# GET /runs
# --------------------------------------------------------------------------


def test_list_runs_orders_most_recent_first(orchestrator_env):
    client, session = orchestrator_env
    _seed_run(session, "run_old", created_at=_BASE)
    _seed_run(session, "run_new", created_at=_BASE + timedelta(minutes=5))

    body = client.get("/runs").json()
    assert [r["run_id"] for r in body] == ["run_new", "run_old"]
    # Vue allégée : pas de last_json ni de détail par set.
    assert "last_json" not in body[0]
    assert "sets" not in body[0]


def test_list_runs_filters_by_dashboard(orchestrator_env):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    other = _seed_dashboard(session, name="other")
    _seed_run(session, "run_a", dashboard_id=dash.id, created_at=_BASE)
    _seed_run(session, "run_b", dashboard_id=other.id, created_at=_BASE + timedelta(minutes=1))

    body = client.get("/runs", params={"dashboard_id": dash.id}).json()
    assert [r["run_id"] for r in body] == ["run_a"]


def test_list_runs_pagination(orchestrator_env):
    client, session = orchestrator_env
    for i in range(5):
        _seed_run(session, f"run_{i}", created_at=_BASE + timedelta(minutes=i))

    page = client.get("/runs", params={"limit": 2, "offset": 1}).json()
    # Tri décroissant : run_4 (offset 0 sauté), run_3, run_2.
    assert [r["run_id"] for r in page] == ["run_3", "run_2"]


def test_list_runs_rejects_out_of_range_limit(orchestrator_env):
    client, _session = orchestrator_env
    assert client.get("/runs", params={"limit": 0}).status_code == 422
    assert client.get("/runs", params={"limit": 101}).status_code == 422


# --------------------------------------------------------------------------
# GET /runs/{run_id}
# --------------------------------------------------------------------------


def test_get_run_returns_global_json_and_sets(orchestrator_env):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_run(
        session,
        "run_1",
        dashboard_id=dash.id,
        total=2,
        success=2,
        last_json={"run_id": "run_1", "summary": {"total_calls": 2}},
    )
    _seed_run_set(session, "run_1", "b")
    _seed_run_set(session, "run_1", "a")

    body = client.get("/runs/run_1").json()
    assert body["run_id"] == "run_1"
    assert body["dashboard_id"] == dash.id
    assert body["last_json"] == {"run_id": "run_1", "summary": {"total_calls": 2}}
    # Sets triés par set_id, avec le dernier JSON par set (payload + réponse brute).
    assert [s["set_id"] for s in body["sets"]] == ["a", "b"]
    assert body["sets"][0]["payload_sent"] == {"symbols": ["BTCUSDT"]}
    assert body["sets"][0]["response_json"] == {"status": "success"}


def test_get_run_not_found(orchestrator_env):
    client, _session = orchestrator_env
    assert client.get("/runs/nope").status_code == 404


# --------------------------------------------------------------------------
# GET /runs/{run_id}/sets/{set_id}
# --------------------------------------------------------------------------


def test_get_run_set_returns_raw_payload_and_response(orchestrator_env):
    client, session = orchestrator_env
    _seed_run(session, "run_1")
    _seed_run_set(
        session,
        "run_1",
        "a",
        ok=False,
        error="boom",
        payload_sent={"symbols": ["ETHUSDT"], "sync_tables": False},
        response_json={"status": "partial_success", "data": {"errors": ["boom"]}},
    )

    body = client.get("/runs/run_1/sets/a").json()
    assert body["set_id"] == "a"
    assert body["ok"] is False
    assert body["error"] == "boom"
    assert body["payload_sent"] == {"symbols": ["ETHUSDT"], "sync_tables": False}
    assert body["response_json"] == {"status": "partial_success", "data": {"errors": ["boom"]}}


def test_get_run_set_not_found(orchestrator_env):
    client, session = orchestrator_env
    _seed_run(session, "run_1")
    assert client.get("/runs/run_1/sets/missing").status_code == 404


# --------------------------------------------------------------------------
# GET /dashboards/{id}/runs et /runs/latest
# --------------------------------------------------------------------------


def test_dashboard_latest_run_returns_most_recent(orchestrator_env):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_run(session, "run_old", dashboard_id=dash.id, created_at=_BASE)
    _seed_run(session, "run_new", dashboard_id=dash.id, created_at=_BASE + timedelta(minutes=5))
    _seed_run_set(session, "run_new", "a")

    body = client.get(f"/dashboards/{dash.id}/runs/latest").json()
    assert body["run_id"] == "run_new"
    assert [s["set_id"] for s in body["sets"]] == ["a"]


def test_dashboard_latest_run_404_when_no_run(orchestrator_env):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    assert client.get(f"/dashboards/{dash.id}/runs/latest").status_code == 404


def test_dashboard_latest_run_404_when_dashboard_missing(orchestrator_env):
    client, _session = orchestrator_env
    assert client.get("/dashboards/999/runs/latest").status_code == 404


def test_dashboard_runs_list_scoped_to_dashboard(orchestrator_env):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    other = _seed_dashboard(session, name="other")
    _seed_run(session, "run_a", dashboard_id=dash.id, created_at=_BASE)
    _seed_run(session, "run_b", dashboard_id=other.id, created_at=_BASE + timedelta(minutes=1))

    body = client.get(f"/dashboards/{dash.id}/runs").json()
    assert [r["run_id"] for r in body] == ["run_a"]


def test_dashboard_runs_list_404_when_dashboard_missing(orchestrator_env):
    client, _session = orchestrator_env
    assert client.get("/dashboards/999/runs").status_code == 404


# --------------------------------------------------------------------------
# Bout-en-bout : un run exécuté est relisible via les endpoints PY-006
# --------------------------------------------------------------------------


def test_executed_run_is_readable_end_to_end(orchestrator_env, monkeypatch):
    from app.db.models import OrchestrationSet
    from app.routers import orchestrator as orch
    from tests.test_orchestrator_run import _FakeAsyncClient, _install_fake_client

    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    session.add(
        OrchestrationSet(
            dashboard_id=dash.id,
            set_id="a",
            exchange="fake",
            market_type="perpetual",
            dry_run=True,
            symbols=["BTCUSDT"],
            payload={
                "dry_run": True,
                "workers": 1,
                "exchange": "fake",
                "market_type": "perpetual",
                "mtf_profile": "regular",
                "sync_tables": False,
                "process_tp_sl": False,
                "symbols": ["BTCUSDT"],
            },
        )
    )
    session.commit()
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    run_id = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "idempotency_key": "k1"}
    ).json()["run_id"]

    # Le run apparaît dans la liste du dashboard...
    listed = client.get(f"/dashboards/{dash.id}/runs").json()
    assert [r["run_id"] for r in listed] == [run_id]

    # ...et son détail expose le dernier JSON global + par set.
    detail = client.get(f"/runs/{run_id}").json()
    assert detail["ok"] is True
    assert detail["last_json"]["summary"]["total_calls"] == 1
    assert [s["set_id"] for s in detail["sets"]] == ["a"]
    assert detail["sets"][0]["response_json"] == {"status": "success"}

    # Le dernier run du dashboard pointe le même run.
    latest = client.get(f"/dashboards/{dash.id}/runs/latest").json()
    assert latest["run_id"] == run_id
