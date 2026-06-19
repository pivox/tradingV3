"""Tests du cron Temporal cible vers l'orchestrateur Python (TM-001).

Couvre :
- l'activity ``orchestrator_run`` (POST httpx, retour RunResponse tel quel,
  chemins d'erreur réseau / non-JSON) ;
- le workflow ``OrchestratorCronWorkflow`` : avec l'activity mockée renvoyant
  ``ok=true`` / ``ok=false``, on vérifie qu'il PROPAGE le résultat sans le
  modifier (l'échec sur ok=false est TM-002, hors scope ici), et qu'il bâtit un
  RunRequest minimal avec un ``tick_timestamp`` dérivé de ``workflow.now()``.

Le serveur de test Temporal n'étant pas téléchargeable dans cet environnement,
le workflow est exercé en patchant les primitives ``workflow.now`` /
``workflow.execute_activity`` / ``workflow.logger`` (pas de serveur requis), à la
manière d'un test unitaire — cohérent avec le pattern ``asyncio.run`` du repo.
"""

import asyncio
import json
from datetime import datetime, timezone

import activities.orchestrator_http as orchestrator_http
import workflows.orchestrator_cron as orchestrator_cron
from activities.orchestrator_http import orchestrator_run
from workflows.orchestrator_cron import (
    DEFAULT_ORCHESTRATOR_URL,
    OrchestratorCronWorkflow,
)


# ---------------------------------------------------------------------------
# Fakes httpx pour l'activity
# ---------------------------------------------------------------------------


class _FakeResponse:
    def __init__(self, payload, status_code=200, text=None, raise_json=False):
        self._payload = payload
        self.status_code = status_code
        self.text = text if text is not None else json.dumps(payload)
        self._raise_json = raise_json

    @property
    def is_success(self):
        # Aligné sur httpx.Response.is_success (2xx).
        return 200 <= self.status_code < 300

    def json(self):
        if self._raise_json:
            raise json.JSONDecodeError("invalid", self.text or "", 0)
        return self._payload


class _FakeClient:
    """AsyncClient minimal capturant l'appel POST."""

    def __init__(self, *, response=None, exc=None, capture=None):
        self._response = response
        self._exc = exc
        self._capture = capture if capture is not None else {}

    async def __aenter__(self):
        return self

    async def __aexit__(self, *_):
        return False

    async def post(self, url, json=None):
        self._capture["url"] = url
        self._capture["json"] = json
        if self._exc is not None:
            raise self._exc
        return self._response


def _patch_async_client(monkeypatch, *, response=None, exc=None):
    capture = {}

    def _factory(*_args, **_kwargs):
        return _FakeClient(response=response, exc=exc, capture=capture)

    monkeypatch.setattr(orchestrator_http.httpx, "AsyncClient", _factory)
    return capture


# ---------------------------------------------------------------------------
# Activity : orchestrator_run
# ---------------------------------------------------------------------------


def test_activity_returns_run_response_verbatim(monkeypatch):
    run_response = {
        "ok": True,
        "run_id": "run_42",
        "status": "success",
        "summary": {"total_calls": 3, "success": 3, "failed": 0},
    }
    capture = _patch_async_client(monkeypatch, response=_FakeResponse(run_response))

    result = asyncio.run(
        orchestrator_run(
            "http://python-orchestrator:8099/orchestrator/run",
            {"dashboard_id": "7", "schedule_id": "sched-1"},
        )
    )

    # Le RunResponse est remonté tel quel (aucune reconstruction métier).
    assert result == run_response
    assert capture["url"] == "http://python-orchestrator:8099/orchestrator/run"
    assert capture["json"] == {"dashboard_id": "7", "schedule_id": "sched-1"}


def test_activity_propagates_ok_false_without_raising(monkeypatch):
    # TM-001 : ok=false n'est PAS transformé en exception (c'est TM-002).
    run_response = {
        "ok": False,
        "run_id": "run_99",
        "status": "no_sets",
        "summary": {"total_calls": 0, "success": 0, "failed": 0},
    }
    _patch_async_client(monkeypatch, response=_FakeResponse(run_response))

    result = asyncio.run(orchestrator_run(DEFAULT_ORCHESTRATOR_URL, None))

    assert result == run_response


def test_activity_none_request_posts_empty_body(monkeypatch):
    capture = _patch_async_client(
        monkeypatch,
        response=_FakeResponse(
            {"ok": True, "run_id": "r", "status": "success", "summary": {}}
        ),
    )

    asyncio.run(orchestrator_run(DEFAULT_ORCHESTRATOR_URL, None))

    assert capture["json"] == {}


def test_activity_network_error_returns_explicit_dict(monkeypatch):
    import httpx

    _patch_async_client(monkeypatch, exc=httpx.ConnectError("boom"))

    result = asyncio.run(orchestrator_run(DEFAULT_ORCHESTRATOR_URL, {"dashboard_id": "1"}))

    assert result["ok"] is False
    assert result["status"] == "error"
    assert "boom" in result["error"]
    assert result["summary"] == {"total_calls": 0, "success": 0, "failed": 0}


def test_activity_http_error_with_json_body_returns_explicit_dict(monkeypatch):
    # FastAPI renvoie un JSON {"detail": ...} avec un statut non-2xx (404/422/…).
    # Ce n'est pas un RunResponse : il doit être normalisé en ok=false, pas
    # propagé verbatim (sinon TM-002 ne distinguerait pas l'échec HTTP d'un run).
    _patch_async_client(
        monkeypatch,
        response=_FakeResponse({"detail": "Not Found"}, status_code=404),
    )

    result = asyncio.run(orchestrator_run(DEFAULT_ORCHESTRATOR_URL, None))

    assert result["ok"] is False
    assert result["status"] == "error"
    assert "404" in result["error"]
    assert "Not Found" in result["error"]
    assert result["summary"] == {"total_calls": 0, "success": 0, "failed": 0}
    # Le corps brut n'est PAS propagé tel quel.
    assert "detail" not in result


def test_activity_ok_false_with_http_200_is_propagated(monkeypatch):
    # ok=false avec HTTP 200 (no_sets / failed) reste un RunResponse valide :
    # il doit être propagé tel quel (pas transformé en erreur HTTP).
    run_response = {
        "ok": False,
        "run_id": "run_x",
        "status": "no_sets",
        "summary": {"total_calls": 0, "success": 0, "failed": 0},
    }
    _patch_async_client(
        monkeypatch, response=_FakeResponse(run_response, status_code=200)
    )

    result = asyncio.run(orchestrator_run(DEFAULT_ORCHESTRATOR_URL, None))

    assert result == run_response


def test_activity_non_json_body_returns_explicit_dict(monkeypatch):
    _patch_async_client(
        monkeypatch,
        response=_FakeResponse(None, status_code=502, text="<html>bad gateway</html>", raise_json=True),
    )

    result = asyncio.run(orchestrator_run(DEFAULT_ORCHESTRATOR_URL, None))

    assert result["ok"] is False
    assert result["status"] == "error"
    assert "502" in result["error"]


# ---------------------------------------------------------------------------
# Workflow : OrchestratorCronWorkflow (primitives patchées, sans serveur)
# ---------------------------------------------------------------------------


class _DummyLogger:
    def info(self, *_args, **_kwargs):
        pass

    def error(self, *_args, **_kwargs):
        pass


def _run_workflow(monkeypatch, *, config, activity_result):
    """Exécute le workflow en patchant les primitives Temporal.

    Renvoie ``(result, captured_activity_args)``.
    """
    fixed_now = datetime(2026, 6, 19, 12, 0, 0, tzinfo=timezone.utc)
    captured = {}

    async def _fake_execute_activity(name, *, args, start_to_close_timeout):
        captured["name"] = name
        captured["args"] = args
        captured["timeout"] = start_to_close_timeout
        return activity_result

    monkeypatch.setattr(orchestrator_cron.workflow, "now", lambda: fixed_now)
    monkeypatch.setattr(orchestrator_cron.workflow, "execute_activity", _fake_execute_activity)
    monkeypatch.setattr(orchestrator_cron.workflow, "logger", _DummyLogger())

    wf = OrchestratorCronWorkflow()
    result = asyncio.run(wf.run(config))
    return result, captured, fixed_now


def test_workflow_propagates_ok_true_result(monkeypatch):
    activity_result = {
        "ok": True,
        "run_id": "run_ok",
        "status": "success",
        "summary": {"total_calls": 2, "success": 2, "failed": 0},
    }

    result, captured, fixed_now = _run_workflow(
        monkeypatch,
        config={"dashboard_id": "7", "schedule_id": "sched-1"},
        activity_result=activity_result,
    )

    # Le workflow propage le RunResponse tel quel.
    assert result == activity_result
    # Une seule activity, la cible orchestrateur.
    assert captured["name"] == "orchestrator_run"
    url, request = captured["args"]
    assert url == DEFAULT_ORCHESTRATOR_URL
    # RunRequest minimal : tick_timestamp dérivé de workflow.now() (déterminisme).
    assert request["tick_timestamp"] == fixed_now.isoformat()
    assert request["dashboard_id"] == "7"
    assert request["schedule_id"] == "sched-1"


def test_workflow_propagates_ok_false_without_raising(monkeypatch):
    # TM-001 : ok=false est propagé, PAS converti en échec (TM-002).
    activity_result = {
        "ok": False,
        "run_id": "run_ko",
        "status": "failed",
        "summary": {"total_calls": 1, "success": 0, "failed": 1},
    }

    result, _captured, _now = _run_workflow(
        monkeypatch,
        config={"dashboard_id": "7"},
        activity_result=activity_result,
    )

    assert result == activity_result


def test_workflow_uses_custom_url_and_forwards_optional_fields(monkeypatch):
    activity_result = {"ok": True, "run_id": "r", "status": "success", "summary": {}}

    _result, captured, _now = _run_workflow(
        monkeypatch,
        config={
            "url": "http://custom:9000/orchestrator/run",
            "dashboard_id": "3",
            "schedule_id": "s",
            "idempotency_key": "key-1",
            "dry_run": True,
        },
        activity_result=activity_result,
    )

    url, request = captured["args"]
    assert url == "http://custom:9000/orchestrator/run"
    assert request["idempotency_key"] == "key-1"
    assert request["dry_run"] is True


def test_workflow_with_none_config_builds_minimal_request(monkeypatch):
    activity_result = {"ok": True, "run_id": "r", "status": "success", "summary": {}}

    _result, captured, fixed_now = _run_workflow(
        monkeypatch,
        config=None,
        activity_result=activity_result,
    )

    url, request = captured["args"]
    assert url == DEFAULT_ORCHESTRATOR_URL
    # Aucun dashboard/schedule fourni : seul le tick_timestamp est présent.
    assert request == {"tick_timestamp": fixed_now.isoformat()}
