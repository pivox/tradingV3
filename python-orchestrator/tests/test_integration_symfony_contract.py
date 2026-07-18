"""QA-002 — Tests d'intégration : contrat HTTP des 3 endpoints Symfony.

Exerce ``app/services/symfony_client.py`` au travers d'un **vrai** ``httpx.AsyncClient``
adossé à un transport en mémoire (``FakeSymfony`` / ``httpx.MockTransport``), SANS
socket ni backend Symfony. À la différence des tests unitaires QA-001 (qui stubbaient
parfois l'objet client lui-même), ce module valide la **forme exacte des requêtes
sortantes** (méthode, chemin, params, corps JSON, en-tête ``X-Run-Id``) et le **mapping
des réponses** (nominales + dégradées : 502, timeout/connexion, payload malformé,
``ok=false``) vers les résultats consommés par le runner.

Cible aussi les branches d'erreur de ``symfony_client.py`` restées non couvertes par
QA-001 (rapport term-missing : lignes ~70, 104-105, 116-117, 169-170, 402-403) : erreurs
transport (``httpx.HTTPError``), JSON malformé et normalisation d'un ``market_type`` non
chaîne.
"""

from __future__ import annotations

import asyncio
from types import SimpleNamespace
from typing import Any

import pytest

from tests.conftest import FakeSymfony

from app.schemas import OrchestratorSet
from app.services.symfony_client import (
    ContractsUnavailableError,
    OpenStateUnavailableError,
    build_mtf_payload,
    fetch_open_state,
    fetch_selected_contracts,
    run_persisted_set,
    snapshot_key,
)


def _orm_set(**kwargs: Any) -> SimpleNamespace:
    base = {
        "set_id": "s",
        "dry_run": True,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "scalper_micro",
        "symbols": ["BTCUSDT"],
    }
    base.update(kwargs)
    return SimpleNamespace(**base)


def _run(coro):
    return asyncio.run(coro)


# ===========================================================================
# GET /api/exchange/open-state (SF-002b)
# ===========================================================================


def test_open_state_request_shape_and_normalized_response():
    fake = FakeSymfony(open_state={"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []})

    async def _go():
        async with fake.new_client() as client:
            return await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    snapshot = _run(_go())

    # Réponse normalisée consommée par le runner.
    assert snapshot == {"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []}
    # Contrat de la requête sortante : GET, chemin et params exacts.
    assert len(fake.open_state_requests) == 1
    req = fake.open_state_requests[0]
    assert req["method"] == "GET"
    assert req["path"] == "/api/exchange/open-state"
    assert req["params"] == {"exchange": "bitmart", "market_type": "perpetual"}


def test_open_state_transport_error_raises_unavailable():
    # Branche httpx.HTTPError (timeout/connexion) -> OpenStateUnavailableError (l.104-105).
    fake = FakeSymfony(open_state_raise=True)

    async def _go():
        async with fake.new_client() as client:
            await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    with pytest.raises(OpenStateUnavailableError):
        _run(_go())


def test_open_state_malformed_json_raises_unavailable():
    # Branche `except ValueError` sur un corps non-JSON annoncé application/json (l.116-117).
    fake = FakeSymfony(open_state_raw=b"<html>502 Bad Gateway</html>")

    async def _go():
        async with fake.new_client() as client:
            await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    with pytest.raises(OpenStateUnavailableError):
        _run(_go())


def test_open_state_502_raises_unavailable():
    fake = FakeSymfony(open_state_status=502, open_state={"status": "error"})

    async def _go():
        async with fake.new_client() as client:
            await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    with pytest.raises(OpenStateUnavailableError):
        _run(_go())


# ===========================================================================
# GET /api/mtf/contracts (PY-003)
# ===========================================================================


def test_contracts_request_shape_and_normalized_response():
    fake = FakeSymfony()

    async def _go():
        async with fake.new_client() as client:
            return await fetch_selected_contracts(
                client, "http://symfony", "scalper_micro", "bitmart", "perpetual"
            )

    result = _run(_go())

    assert result == {
        "profile": "scalper_micro",
        "exchange": "bitmart",
        "market_type": "perpetual",
        "count": 2,
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "filters": {"quote_currency": "USDT", "top_n": 140},
    }
    assert len(fake.contracts_requests) == 1
    req = fake.contracts_requests[0]
    assert req["method"] == "GET"
    assert req["path"] == "/api/mtf/contracts"
    assert req["params"] == {
        "profile": "scalper_micro",
        "exchange": "bitmart",
        "market_type": "perpetual",
    }


def test_contracts_omits_profile_param_when_none():
    fake = FakeSymfony(contracts=dict(FakeSymfony.DEFAULT_CONTRACTS, profile="regular"))

    async def _go():
        async with fake.new_client() as client:
            return await fetch_selected_contracts(
                client, "http://symfony", None, "bitmart", "perpetual"
            )

    result = _run(_go())
    assert result["profile"] == "regular"
    # Profil None => clé absente des params (Symfony retombe sur le mode actif).
    assert "profile" not in fake.contracts_requests[0]["params"]


def test_contracts_transport_error_raises_unavailable():
    # Branche httpx.HTTPError -> ContractsUnavailableError (l.169-170).
    fake = FakeSymfony(contracts_raise=True)

    async def _go():
        async with fake.new_client() as client:
            await fetch_selected_contracts(
                client, "http://symfony", "scalper_micro", "bitmart", "perpetual"
            )

    with pytest.raises(ContractsUnavailableError):
        _run(_go())


def test_contracts_502_raises_unavailable():
    fake = FakeSymfony(contracts_status=502, contracts={"ok": False})

    async def _go():
        async with fake.new_client() as client:
            await fetch_selected_contracts(
                client, "http://symfony", "scalper_micro", "bitmart", "perpetual"
            )

    with pytest.raises(ContractsUnavailableError):
        _run(_go())


# ===========================================================================
# POST /api/mtf/run (PY-005)
# ===========================================================================


def test_mtf_run_request_payload_contract_and_trace_id():
    fake = FakeSymfony()
    snapshot = {"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []}
    orm = _orm_set(dry_run=False, symbols=["BTCUSDT", "ETHUSDT"])

    async def _go():
        async with fake.new_client() as client:
            # Override run-level dry_run=True + propagation du trace-id X-Run-Id.
            return await run_persisted_set(
                client, "http://symfony", orm, snapshot, dry_run=True, run_id="run_xyz"
            )

    result = _run(_go())

    assert result["ok"] is True
    assert result["business_status"] == "success"
    assert len(fake.run_requests) == 1
    req = fake.run_requests[0]
    assert req["method"] == "POST"
    assert req["path"] == "/api/mtf/run"
    # OBS-001 : X-Run-Id propagé en en-tête de corrélation.
    assert req["headers"].get("x-run-id") == "run_xyz"
    sent = req["json"]
    # Contrat SF-002b : effets de bord exchange par set neutralisés.
    assert sent["sync_tables"] is False
    assert sent["process_tp_sl"] is False
    # Override run-level appliqué, snapshot joint, sélection transmise.
    assert sent["dry_run"] is True
    assert sent["open_state_snapshot"] == snapshot
    assert sent["symbols"] == ["BTCUSDT", "ETHUSDT"]
    assert sent["mtf_profile"] == "scalper_micro"
    # Allow-list stricte des clés (aucun flag de contrôle runner ne fuite).
    assert set(sent.keys()) <= {
        "dry_run", "workers", "exchange", "market_type", "mtf_profile",
        "sync_tables", "process_tp_sl", "symbols", "open_state_snapshot", "config_hash",
    }


def test_mtf_run_no_trace_id_header_when_run_id_absent():
    fake = FakeSymfony()
    orm = _orm_set(symbols=["BTCUSDT"])

    async def _go():
        async with fake.new_client() as client:
            return await run_persisted_set(client, "http://symfony", orm, None)

    _run(_go())
    assert "x-run-id" not in fake.run_requests[0]["headers"]


def test_mtf_run_malformed_json_body_falls_back_to_text():
    # Branche `except ValueError: body = response.text` de _dispatch_mtf_run (l.402-403) :
    # Symfony renvoie 200 mais un corps non-JSON (ex. page d'erreur d'un proxy).
    fake = FakeSymfony(run_raw=b"<html>504 Gateway Time-out</html>")
    orm = _orm_set(symbols=["BTCUSDT"])

    async def _go():
        async with fake.new_client() as client:
            return await run_persisted_set(client, "http://symfony", orm, None)

    result = _run(_go())
    # Corps non-JSON => repli sur le texte brut ; pas de business_status exploitable.
    assert result["body"] == "<html>504 Gateway Time-out</html>"
    assert result["business_status"] is None
    assert result["ok"] is False


def test_mtf_run_502_maps_to_failure():
    fake = FakeSymfony(run_status=502, run_response={"status": "error", "message": "upstream"})
    orm = _orm_set(symbols=["BTCUSDT"])

    async def _go():
        async with fake.new_client() as client:
            return await run_persisted_set(client, "http://symfony", orm, None)

    result = _run(_go())
    assert result["ok"] is False
    assert result["status"] == 502


@pytest.mark.parametrize(
    "body,business_status",
    [
        ({"status": "partial_success", "data": {"errors": ["BTCUSDT: boom"]}}, "partial_success"),
        ({"status": "completed_with_errors"}, "completed_with_errors"),
        ({"status": "rejected"}, "rejected"),
        ({"status": "success", "errors": ["x"]}, "success"),
    ],
)
def test_mtf_run_business_failure_maps_ok_false(body, business_status):
    # HTTP 200 mais statut métier d'échec (ou success AVEC errors) => ok=false.
    fake = FakeSymfony(run_response=body)
    orm = _orm_set(symbols=["BTCUSDT"])

    async def _go():
        async with fake.new_client() as client:
            return await run_persisted_set(client, "http://symfony", orm, None)

    result = _run(_go())
    assert result["ok"] is False
    assert result["business_status"] == business_status


# ===========================================================================
# Normalisation de contrat (snapshot_key) — branche non-chaîne (l.70)
# ===========================================================================


def test_snapshot_key_passes_through_non_string_market_type():
    # `_normalize_market_type` retourne tel quel une valeur non chaîne (l.70) :
    # une ligne ORM aberrante ne plante pas le regroupement snapshot.
    orm = SimpleNamespace(exchange="bitmart", market_type=123)
    assert snapshot_key(orm) == ("bitmart", 123)


def test_build_mtf_payload_shape_matches_run_request():
    # Garde-fou de cohérence : la forme construite côté client == celle envoyée sur le
    # fil (mêmes clés de contrat), pour les sets pydantic comme ORM.
    pyd = OrchestratorSet(
        set_id="s",
        exchange="bitmart",
        market_type="perpetual",
        mtf_profile="scalper_micro",
        symbols=("BTCUSDT",),
        dry_run=True,
    )
    fake = FakeSymfony()

    async def _go():
        async with fake.new_client() as client:
            return await run_persisted_set(
                client, "http://symfony", _orm_set(symbols=["BTCUSDT"]), None
            )

    _run(_go())
    built = build_mtf_payload(pyd, None)
    sent = fake.run_requests[0]["json"]
    assert set(built.keys()) == set(sent.keys())
