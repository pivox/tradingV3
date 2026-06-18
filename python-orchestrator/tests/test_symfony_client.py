"""Tests unitaires du client Symfony (SF-002b)."""

from __future__ import annotations

import asyncio
from typing import Any

import httpx
import pytest

from app.schemas import OrchestratorSet
from app.services.symfony_client import (
    ContractsUnavailableError,
    OpenStateUnavailableError,
    build_mtf_payload,
    fetch_open_state,
    fetch_selected_contracts,
    is_business_success,
    run_mtf_set,
    snapshot_key,
)


def _make_set(**kwargs: Any) -> OrchestratorSet:
    base = {"set_id": "s", "exchange": "fake"}
    base.update(kwargs)
    return OrchestratorSet(**base)


def test_build_payload_forces_sync_tables_false_and_attaches_snapshot():
    a_set = _make_set(symbols=("BTCUSDT", "ETHUSDT"), dry_run=True)
    snapshot = {"open_positions": [], "open_orders": []}

    payload = build_mtf_payload(a_set, snapshot)

    assert payload["sync_tables"] is False
    # TP/SL recalc per set refetcherait l'exchange et aurait des effets de bord live :
    # désactivé pour préserver l'objectif "zéro appel exchange par set".
    assert payload["process_tp_sl"] is False
    assert payload["open_state_snapshot"] == snapshot
    assert payload["symbols"] == ["BTCUSDT", "ETHUSDT"]
    assert payload["exchange"] == "fake"
    assert payload["market_type"] == "perpetual"


@pytest.mark.parametrize(
    "body,expected",
    [
        ({"status": "success"}, True),
        ({"status": "success", "data": {"errors": []}}, True),
        ({"status": "partial_success"}, False),
        ({"status": "completed_with_errors"}, False),
        ({"status": "rejected"}, False),
        ({"status": "error"}, False),
        # Le contrôleur peut écraser le statut par summary.status : on vérifie errors.
        ({"status": "success", "data": {"errors": ["BTCUSDT: boom"]}}, False),
        ({"status": "success", "errors": ["x"]}, False),
        ("not-json-string", False),
        ({}, False),
    ],
)
def test_is_business_success(body, expected):
    assert is_business_success(body) is expected


class _StubResponse:
    def __init__(self, status_code, payload):
        self.status_code = status_code
        self._payload = payload
        self.text = str(payload)

    @property
    def is_success(self):
        return 200 <= self.status_code < 300

    def json(self):
        return self._payload


class _StubClient:
    def __init__(self, response):
        self._response = response

    async def post(self, url, json=None):
        return self._response


def _run_set(status_code, payload):
    client = _StubClient(_StubResponse(status_code, payload))
    return asyncio.run(run_mtf_set(client, "http://sym", _make_set(), None))


def test_run_mtf_set_ok_on_business_success():
    result = _run_set(200, {"status": "success"})
    assert result["ok"] is True
    assert result["business_status"] == "success"


def test_run_mtf_set_failed_on_business_failure_with_http_200():
    # HTTP 200 mais statut métier d'échec : le set doit être compté en échec.
    result = _run_set(200, {"status": "partial_success", "data": {"errors": ["x"]}})
    assert result["ok"] is False
    assert result["business_status"] == "partial_success"


def test_run_mtf_set_failed_on_http_error():
    result = _run_set(500, {"status": "error"})
    assert result["ok"] is False


def test_build_payload_omits_snapshot_when_none():
    payload = build_mtf_payload(_make_set(), None)

    assert payload["sync_tables"] is False
    assert payload["process_tp_sl"] is False
    assert "open_state_snapshot" not in payload


def test_snapshot_key_uses_exchange_and_market_type():
    assert snapshot_key(_make_set(exchange="bitmart", market_type="perpetual")) == (
        "bitmart",
        "perpetual",
    )


def _client_with(handler) -> httpx.AsyncClient:
    return httpx.AsyncClient(transport=httpx.MockTransport(handler))


def test_fetch_open_state_returns_normalized_shape():
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/api/exchange/open-state"
        assert request.url.params["exchange"] == "bitmart"
        return httpx.Response(200, json={"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []})

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    snapshot = asyncio.run(_run())
    assert snapshot == {"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []}


def test_fetch_open_state_raises_on_http_error_status():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(503, json={"status": "error"})

    async def _run():
        async with _client_with(handler) as client:
            await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    with pytest.raises(OpenStateUnavailableError):
        asyncio.run(_run())


def test_fetch_open_state_raises_on_unexpected_shape():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"unexpected": True})

    async def _run():
        async with _client_with(handler) as client:
            await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    with pytest.raises(OpenStateUnavailableError):
        asyncio.run(_run())


@pytest.mark.parametrize(
    "open_positions,open_orders",
    [
        (None, []),
        ([], None),
        ("nope", []),
        ([], {"k": "v"}),
    ],
)
def test_fetch_open_state_raises_on_non_list_arrays(open_positions, open_orders):
    # Clés présentes mais valeurs non-listes : ne pas normaliser en [] (fail-closed).
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200, json={"open_positions": open_positions, "open_orders": open_orders}
        )

    async def _run():
        async with _client_with(handler) as client:
            await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    with pytest.raises(OpenStateUnavailableError):
        asyncio.run(_run())


def test_build_payload_applies_dry_run_override():
    live_set = _make_set(dry_run=False)
    # Override run-level dry_run=True => le payload force le dry-run.
    assert build_mtf_payload(live_set, None, dry_run=True)["dry_run"] is True
    # dry_run=None => on retombe sur la valeur du set (ici live).
    assert build_mtf_payload(live_set, None, dry_run=None)["dry_run"] is False


# --- fetch_selected_contracts (PY-003) --------------------------------------


def _ok_contracts_body(**overrides):
    body = {
        "ok": True,
        "profile": "scalper_micro",
        "exchange": "bitmart",
        "market_type": "perpetual",
        "count": 2,
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "filters": {"quote_currency": "USDT", "top_n": 140},
    }
    body.update(overrides)
    return body


def _fetch_contracts(handler, profile="scalper_micro", exchange="bitmart", market_type="perpetual"):
    async def _run():
        async with _client_with(handler) as client:
            return await fetch_selected_contracts(
                client, "http://symfony", profile, exchange, market_type
            )

    return asyncio.run(_run())


def test_fetch_selected_contracts_returns_normalized_shape_and_passes_params():
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/api/mtf/contracts"
        assert request.url.params["profile"] == "scalper_micro"
        assert request.url.params["exchange"] == "bitmart"
        assert request.url.params["market_type"] == "perpetual"
        return httpx.Response(200, json=_ok_contracts_body())

    result = _fetch_contracts(handler)
    assert result == {
        "profile": "scalper_micro",
        "exchange": "bitmart",
        "market_type": "perpetual",
        "count": 2,
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "filters": {"quote_currency": "USDT", "top_n": 140},
    }


def test_fetch_selected_contracts_omits_profile_when_none():
    def handler(request: httpx.Request) -> httpx.Response:
        # Profil None => la clé n'est pas envoyée (Symfony retombe sur le mode actif).
        assert "profile" not in request.url.params
        return httpx.Response(200, json=_ok_contracts_body(profile="regular"))

    result = _fetch_contracts(handler, profile=None)
    assert result["profile"] == "regular"


def test_fetch_selected_contracts_defaults_filters_to_empty_dict():
    def handler(request: httpx.Request) -> httpx.Response:
        body = _ok_contracts_body()
        body.pop("filters")
        return httpx.Response(200, json=body)

    assert _fetch_contracts(handler)["filters"] == {}


def test_fetch_selected_contracts_raises_on_http_error_status():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(500, json={"ok": False, "error": "boom"})

    with pytest.raises(ContractsUnavailableError):
        _fetch_contracts(handler)


def test_fetch_selected_contracts_raises_on_invalid_json():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, content=b"not-json", headers={"content-type": "application/json"})

    with pytest.raises(ContractsUnavailableError):
        _fetch_contracts(handler)


def test_fetch_selected_contracts_raises_on_ok_false():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json=_ok_contracts_body(ok=False))

    with pytest.raises(ContractsUnavailableError):
        _fetch_contracts(handler)


def test_fetch_selected_contracts_raises_on_non_list_symbols():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json=_ok_contracts_body(symbols="BTCUSDT"))

    with pytest.raises(ContractsUnavailableError):
        _fetch_contracts(handler)


@pytest.mark.parametrize("missing", ["profile", "exchange", "market_type", "count"])
def test_fetch_selected_contracts_raises_on_missing_required_field(missing):
    def handler(request: httpx.Request) -> httpx.Response:
        body = _ok_contracts_body()
        body.pop(missing)
        return httpx.Response(200, json=body)

    with pytest.raises(ContractsUnavailableError):
        _fetch_contracts(handler)
