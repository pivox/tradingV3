"""Tests for the thin Temporal activity wrappers (I/O mocked: httpx + runtime-check + file)."""

import asyncio

import pytest

pytest.importorskip("temporalio")
pytest.importorskip("httpx")
yaml = pytest.importorskip("yaml")

import activities.dashboard as act  # noqa: E402
from temporalio.exceptions import ApplicationError  # noqa: E402


def _write_dashboards(tmp_path, payload):
    path = tmp_path / "dashboards.yaml"
    path.write_text(yaml.safe_dump(payload), encoding="utf-8")
    return str(path)


class _FakeResponse:
    def __init__(self, status_code, text):
        self.status_code = status_code
        self.text = text

    @property
    def is_success(self):
        return 200 <= self.status_code < 300


class _FakeClient:
    def __init__(self, response):
        self._response = response

    async def __aenter__(self):
        return self

    async def __aexit__(self, *exc):
        return False

    async def post(self, url, json):
        self.posted = {"url": url, "json": json}
        return self._response


def test_load_dashboard_snapshot_returns_snapshot(tmp_path):
    path = _write_dashboards(
        tmp_path,
        {"dashboards": [{"dashboard_id": "d", "targets": [{"target_id": "okx", "exchange": "okx"}]}]},
    )

    snapshot = asyncio.run(act.load_dashboard_snapshot("d", path))

    assert snapshot["dashboard_id"] == "d"
    assert snapshot["targets"][0]["target_id"] == "okx"
    assert "fingerprint" in snapshot["targets"][0]


def test_load_dashboard_snapshot_unknown_dashboard_raises(tmp_path):
    path = _write_dashboards(
        tmp_path,
        {"dashboards": [{"dashboard_id": "d", "targets": [{"target_id": "okx", "exchange": "okx"}]}]},
    )

    with pytest.raises(ApplicationError):
        asyncio.run(act.load_dashboard_snapshot("missing", path))


def test_runtime_check_target_skips_for_dry_run():
    result = asyncio.run(act.runtime_check_target({"exchange": "okx", "dry_run": True}))

    assert result["checked"] is False


def test_runtime_check_target_blocks_live_okx():
    with pytest.raises(ApplicationError):
        asyncio.run(
            act.runtime_check_target({"exchange": "okx", "dry_run": False, "market_type": "perpetual"})
        )


def test_call_mtf_run_target_success(monkeypatch):
    response = _FakeResponse(200, '{"status": "success", "data": {"summary": {"success_rate": 100}}}')
    monkeypatch.setattr(act.httpx, "AsyncClient", lambda *a, **k: _FakeClient(response))

    target = {"target_id": "okx", "exchange": "okx", "dry_run": True, "market_type": "perpetual"}
    result = asyncio.run(act.call_mtf_run_target(target, "d:okx:ts:abc"))

    assert result["ok"] is True
    assert result["status"] == 200


def test_call_mtf_run_target_applicative_failure_is_non_retryable(monkeypatch):
    response = _FakeResponse(200, '{"data": {"errors": ["boom"]}}')
    monkeypatch.setattr(act.httpx, "AsyncClient", lambda *a, **k: _FakeClient(response))

    target = {"target_id": "okx", "exchange": "okx", "dry_run": True, "market_type": "perpetual"}
    with pytest.raises(ApplicationError) as excinfo:
        asyncio.run(act.call_mtf_run_target(target, "d:okx:ts:abc"))

    assert excinfo.value.non_retryable is True


def test_call_mtf_run_target_transient_5xx_is_retryable(monkeypatch):
    response = _FakeResponse(503, "service unavailable")
    monkeypatch.setattr(act.httpx, "AsyncClient", lambda *a, **k: _FakeClient(response))

    target = {"target_id": "okx", "exchange": "okx", "dry_run": True, "market_type": "perpetual"}
    with pytest.raises(ApplicationError) as excinfo:
        asyncio.run(act.call_mtf_run_target(target, "d:okx:ts:abc"))

    assert excinfo.value.non_retryable is False  # 5xx is transient -> per-target retry kept
