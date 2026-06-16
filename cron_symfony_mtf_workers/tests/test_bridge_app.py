"""Tests for the Flask bridge adapter (Flask test client + injected caller stub)."""

import pytest

pytest.importorskip("flask")
pytest.importorskip("httpx")

from bridge.app import create_app  # noqa: E402
from bridge.dashboard import Dashboard, DashboardTarget  # noqa: E402


def _dashboards():
    return {
        "d1": Dashboard(
            dashboard_id="d1",
            targets=[
                DashboardTarget(target_id="okx", exchange="okx", mtf_profile="scalper", dry_run=True),
                DashboardTarget(target_id="hl", exchange="hyperliquid", mtf_profile="regular", dry_run=True),
            ],
        )
    }


def _ok_caller(url, payload):
    return {"ok": True, "status": 200, "summary": "ok"}


def _fail_okx_caller(url, payload):
    target = payload["idempotency_key"].split(":")[1]
    ok = target != "okx"
    return {"ok": ok, "status": 200 if ok else 500}


def _client(dashboards=None, caller=_ok_caller):
    app = create_app(dashboards=dashboards if dashboards is not None else _dashboards(), caller=caller)
    return app.test_client()


def test_health_lists_dashboards():
    resp = _client().get("/health")

    assert resp.status_code == 200
    assert resp.get_json()["dashboards"] == ["d1"]


def test_run_requires_dashboard_id():
    resp = _client().post("/bridge/run", json={})

    assert resp.status_code == 400
    assert resp.get_json()["error"] == "dashboard_id_required"


def test_run_unknown_dashboard_returns_404():
    resp = _client().post("/bridge/run", json={"dashboard_id": "nope"})

    assert resp.status_code == 404
    assert resp.get_json()["error"] == "unknown_dashboard"


def test_run_all_ok_returns_200_with_stable_idempotency_keys():
    resp = _client().post(
        "/bridge/run",
        json={"dashboard_id": "d1", "tick_timestamp": "ts", "run_id": "r1", "schedule_id": "s1"},
    )

    assert resp.status_code == 200
    body = resp.get_json()
    assert body["ok"] is True
    assert body["targets_ok"] == 2
    assert [r["idempotency_key"] for r in body["results"]] == ["d1:okx:ts", "d1:hl:ts"]
    assert body["run_id"] == "r1"


def test_run_one_failure_returns_502():
    resp = _client(caller=_fail_okx_caller).post(
        "/bridge/run", json={"dashboard_id": "d1", "tick_timestamp": "ts"}
    )

    assert resp.status_code == 502
    assert resp.get_json()["ok"] is False


def test_run_live_okx_dashboard_is_rejected_400():
    live = {
        "d1": Dashboard(
            dashboard_id="d1",
            targets=[DashboardTarget(target_id="okx", exchange="okx", dry_run=False)],
        )
    }

    resp = _client(dashboards=live).post("/bridge/run", json={"dashboard_id": "d1"})

    assert resp.status_code == 400
    assert resp.get_json()["error"] == "dashboard_run_failed"
