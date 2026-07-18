"""Tests unitaires du client Symfony (SF-002b)."""

from __future__ import annotations

import asyncio
import hashlib
import json
import re
from types import SimpleNamespace
from typing import Any

import httpx
import pytest

from app.schemas import OrchestratorSet
from app.services.correlation import canonical_correlation_id
from app.services.symfony_client import (
    ContractsUnavailableError,
    OpenStateUnavailableError,
    OutcomeUnavailableError,
    build_mtf_payload,
    effective_set_payload,
    fetch_open_state,
    fetch_run_trade_outcome,
    fetch_selected_contracts,
    generate_set_payload,
    is_business_success,
    run_mtf_set,
    run_persisted_set,
    snapshot_key,
)


def _make_set(**kwargs: Any) -> OrchestratorSet:
    base = {"set_id": "s", "exchange": "fake"}
    base.update(kwargs)
    return OrchestratorSet(**base)


def _expected_config_hash(payload: dict) -> str:
    canonical_payload = {
        key: value
        for key, value in payload.items()
        if key not in {"config_hash", "open_state_snapshot"}
    }
    canonical = json.dumps(
        canonical_payload,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    )
    return f"sha256:{hashlib.sha256(canonical.encode('utf-8')).hexdigest()}"


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

    async def post(self, url, json=None, headers=None):
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


def test_snapshot_key_normalizes_casing_and_whitespace():
    # Une ligne ORM hors API peut porter une casse/des espaces ; le regroupement
    # snapshot doit la normaliser (sinon des variantes échapperaient au partage).
    orm = SimpleNamespace(exchange=" Bitmart ", market_type="PERPETUAL")
    assert snapshot_key(orm) == ("bitmart", "perpetual")


@pytest.mark.parametrize("alias", ["perp", "future", "futures", "PERP", " Perpetual "])
def test_snapshot_key_canonicalizes_market_type_aliases(alias):
    # Symfony (ExchangeContextResolver) canonicalise perp/future/futures en
    # perpetual : on miroir la table pour regrouper le même marché.
    orm = SimpleNamespace(exchange="bitmart", market_type=alias)
    assert snapshot_key(orm) == ("bitmart", "perpetual")


def _client_with(handler) -> httpx.AsyncClient:
    return httpx.AsyncClient(transport=httpx.MockTransport(handler))


def test_fetch_open_state_returns_normalized_shape():
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/api/exchange/open-state"
        assert request.url.params["exchange"] == "bitmart"
        assert "x-fake-only-safety-evidence" not in request.headers
        return httpx.Response(200, json={"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []})

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    snapshot = asyncio.run(_run())
    assert snapshot == {"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []}


def test_fetch_fake_open_state_requests_and_preserves_safety_evidence():
    evidence = {
        "ambiguous_calls": 0,
        "async_exchange_capable_dispatches_suppressed": True,
        "complete": True,
        "exchange_calls": {"bitmart": 0, "hyperliquid": 0, "okx": 0},
        "schema_version": "fake-only-exchange-safety-v1",
        "source": "symfony_http_client_guard",
    }

    def handler(request: httpx.Request) -> httpx.Response:
        assert request.headers["x-fake-only-safety-evidence"] == "v1"
        assert request.url.params["dry_run"] == "true"
        return httpx.Response(
            200,
            json={
                "fake_only_safety_evidence": evidence,
                "open_positions": [],
                "open_orders": [],
            },
        )

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_open_state(client, "http://symfony", "fake", "perpetual")

    assert asyncio.run(_run()) == {
        "fake_only_safety_evidence": evidence,
        "open_positions": [],
        "open_orders": [],
    }


def test_fetch_open_state_raises_on_http_error_status():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(503, json={"status": "error"})

    async def _run():
        async with _client_with(handler) as client:
            await fetch_open_state(client, "http://symfony", "bitmart", "perpetual")

    with pytest.raises(OpenStateUnavailableError) as caught:
        asyncio.run(_run())

    assert caught.value.fake_only_safety_evidence is None


def test_fetch_fake_open_state_http_error_preserves_only_safety_evidence():
    evidence = {
        "ambiguous_calls": 1,
        "async_exchange_capable_dispatches_suppressed": True,
        "complete": True,
        "exchange_calls": {"bitmart": 1, "hyperliquid": 0, "okx": 0},
        "schema_version": "fake-only-exchange-safety-v1",
        "source": "symfony_http_client_guard",
    }

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            503,
            json={
                "status": "error",
                "message": "provider failed with api_key=must-not-escape",
                "fake_only_safety_evidence": evidence,
            },
        )

    async def _run():
        async with _client_with(handler) as client:
            await fetch_open_state(client, "http://symfony", "fake", "perpetual")

    with pytest.raises(OpenStateUnavailableError) as caught:
        asyncio.run(_run())

    assert caught.value.fake_only_safety_evidence == evidence
    assert "api_key" not in str(caught.value).lower()
    assert "must-not-escape" not in str(caught.value)


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
    overridden = build_mtf_payload(live_set, None, dry_run=True)
    assert overridden["dry_run"] is True
    assert overridden["config_hash"] == _expected_config_hash(overridden)
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


# --- generate_set_payload (PY-004) ------------------------------------------


def _orm_set(**kwargs: Any) -> SimpleNamespace:
    # Imite un OrchestrationSet ORM : exchange/market_type/mtf_profile sont des
    # chaînes (pas des enums), symbols une liste.
    base = {
        "dry_run": True,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "scalper_micro",
        "symbols": ["BTCUSDT", "ETHUSDT"],
    }
    base.update(kwargs)
    return SimpleNamespace(**base)


def test_generate_set_payload_from_orm_string_fields():
    payload = generate_set_payload(_orm_set())

    assert payload == {
        "dry_run": True,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "scalper_micro",
        "sync_tables": False,
        "process_tp_sl": False,
        "symbols": ["BTCUSDT", "ETHUSDT"],
    }
    # Le snapshot est une valeur runtime : jamais stockée dans le payload préparé.
    assert "open_state_snapshot" not in payload


def test_generate_set_payload_none_when_symbols_blank():
    # symbols réduit à du vide après trim => sélection non matérialisée (Symfony
    # filtrerait ces entrées et retomberait sur tout l'univers).
    assert generate_set_payload(_orm_set(symbols=[" ", "\t", ""])) is None


def test_generate_set_payload_strips_symbol_whitespace():
    assert generate_set_payload(_orm_set(symbols=[" BTCUSDT ", "ETHUSDT"]))["symbols"] == [
        "BTCUSDT",
        "ETHUSDT",
    ]


def test_generate_set_payload_none_when_no_concrete_symbols():
    # Un set persisté sans symbole concret n'est valide que par sa
    # `contracts_limit` (sélection non matérialisée). `/api/mtf/run` n'ayant pas
    # de paramètre de cap, un payload sans `symbols` y signifierait « tout
    # l'univers » : on renvoie donc `None` (pas de payload « run-all » trompeur)
    # jusqu'à ce qu'un refresh renseigne des symboles concrets.
    assert generate_set_payload(_orm_set(symbols=[])) is None


def test_generate_set_payload_uses_set_dry_run():
    # Pas d'override run-level ici : le payload persisté reflète le dry_run du set.
    assert generate_set_payload(_orm_set(dry_run=True))["dry_run"] is True


def test_generate_set_payload_matches_build_mtf_payload_shape():
    # Cœur partagé : la forme persistée doit coïncider avec le payload runtime
    # (hors open_state_snapshot, runtime uniquement).
    orm = _orm_set()
    pyd = OrchestratorSet(
        set_id="s",
        exchange="bitmart",
        market_type="perpetual",
        mtf_profile="scalper_micro",
        symbols=("BTCUSDT", "ETHUSDT"),
        dry_run=True,
    )
    runtime_payload = build_mtf_payload(pyd, None)
    runtime_payload.pop("config_hash")
    assert generate_set_payload(orm) == runtime_payload


# --- effective_set_payload (PY-007) -----------------------------------------


def test_effective_set_payload_matches_generate_when_within_bounds():
    # Cas nominal : la configuration envoyée reste celle persistée et le payload
    # effectif ajoute uniquement son empreinte canonique de lineage.
    orm = _orm_set()
    effective = dict(effective_set_payload(orm))
    config_hash = effective.pop("config_hash")
    assert effective == generate_set_payload(orm)
    assert re.fullmatch(r"sha256:[0-9a-f]{64}", config_hash)


def test_effective_set_payload_clamps_oversized_workers():
    # Ligne écrite hors API avec workers>1 (aucune contrainte DB) : le payload
    # effectif clampe à la borne, comme l'envoi réel de run_persisted_set.
    payload = effective_set_payload(_orm_set(symbols=["BTCUSDT"], workers=8))
    assert payload["workers"] == 1


def test_effective_set_payload_adds_stable_distinct_config_hashes():
    regular = effective_set_payload(_orm_set(mtf_profile="regular", symbols=["BTCUSDT"]))
    replay = effective_set_payload(_orm_set(mtf_profile="regular", symbols=["BTCUSDT"]))
    scalper = effective_set_payload(_orm_set(mtf_profile="scalper", symbols=["BTCUSDT"]))
    distinct_symbol = effective_set_payload(
        _orm_set(mtf_profile="regular", symbols=["ETHUSDT"])
    )

    assert re.fullmatch(r"sha256:[0-9a-f]{64}", regular["config_hash"])
    assert replay["config_hash"] == regular["config_hash"]
    assert len(
        {
            regular["config_hash"],
            scalper["config_hash"],
            distinct_symbol["config_hash"],
        }
    ) == 3


def test_effective_set_payload_none_when_not_materialized():
    # Sélection non matérialisée (symbols vide/blanc) => null, comme
    # generate_set_payload : le front en déduit « set non matérialisé ».
    assert effective_set_payload(_orm_set(symbols=[])) is None
    assert effective_set_payload(_orm_set(symbols=[" ", "\t"])) is None


def test_effective_set_payload_tolerates_enum_fields():
    # SetRead porte des enums str (exchange/market_type/mtf_profile) là où l'ORM
    # porte des chaînes : le payload effectif doit être identique dans les deux cas
    # (mêmes valeurs string), pour que la preview du cockpit colle à l'envoi réel.
    from app.schemas import Exchange, MarketType, MtfProfile

    enum_set = _orm_set(
        exchange=Exchange.BITMART,
        market_type=MarketType.PERPETUAL,
        mtf_profile=MtfProfile.SCALPER_MICRO,
    )
    assert effective_set_payload(enum_set) == effective_set_payload(_orm_set())


def test_effective_set_payload_equals_payload_sent_by_run_persisted_set():
    # Invariant central PY-007 : effective_set_payload == le payload réellement
    # envoyé par run_persisted_set, une fois retirés open_state_snapshot (runtime)
    # et l'override dry_run run-level. Garanti par la fonction partagée.
    orm = _orm_set(set_id="s", symbols=["BTCUSDT"], workers=8)
    snapshot = {"open_positions": [], "open_orders": []}
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            # On force un dry_run run-level différent du set pour vérifier que
            # effective_set_payload ne porte PAS cet override (il reste run-level).
            return await run_persisted_set(client, "http://sym", orm, snapshot, dry_run=False)

    result = asyncio.run(_run())
    sent = dict(captured["json"])
    # Retire la couche runtime exclue de effective_set_payload et son empreinte,
    # qui doit décrire le dry_run effectif après l'override.
    sent.pop("open_state_snapshot", None)
    sent.pop("dry_run", None)
    sent_hash = sent.pop("config_hash")
    expected = dict(effective_set_payload(orm))
    expected.pop("dry_run", None)
    configured_hash = expected.pop("config_hash")
    assert sent == expected
    assert sent_hash != configured_hash
    # Et le payload effectif a bien clampé workers, comme l'envoi réel.
    assert expected["workers"] == 1 == result["payload_sent"]["workers"]


# --- run_persisted_set (PY-005) ---------------------------------------------


def test_run_persisted_set_dispatches_persisted_payload_with_snapshot_and_override():
    # Part d'un payload persisté (live), injecte le snapshot runtime et applique
    # l'override dry_run run-level ; sync_tables/process_tp_sl restent false.
    persisted = {
        "dry_run": False,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "scalper_micro",
        "sync_tables": False,
        "process_tp_sl": False,
        "symbols": ["BTCUSDT"],
    }
    orm = _orm_set(set_id="s", payload=persisted, dry_run=False, symbols=["BTCUSDT"])
    snapshot = {"open_positions": [], "open_orders": []}
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/api/mtf/run"
        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(client, "http://sym", orm, snapshot, dry_run=True)

    result = asyncio.run(_run())
    sent = captured["json"]
    assert sent["sync_tables"] is False
    assert sent["process_tp_sl"] is False
    assert sent["dry_run"] is True  # override run-level appliqué
    assert sent["open_state_snapshot"] == snapshot
    assert sent["symbols"] == ["BTCUSDT"]
    assert result["ok"] is True
    assert result["payload_sent"]["dry_run"] is True
    # Le payload persisté n'est pas muté (copie défensive).
    assert persisted["dry_run"] is False
    assert "open_state_snapshot" not in persisted


def test_run_persisted_set_hashes_payload_after_dry_run_override():
    orm = _orm_set(set_id="s", dry_run=False, symbols=["BTCUSDT"])
    snapshot = {"open_positions": [], "open_orders": []}
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(
                client,
                "http://sym",
                orm,
                snapshot,
                dry_run=True,
            )

    asyncio.run(_run())

    sent = captured["json"]
    assert sent["dry_run"] is True
    assert sent["config_hash"] == _expected_config_hash(sent)
    assert sent["config_hash"] != effective_set_payload(orm)["config_hash"]


def test_run_persisted_set_forces_safety_flags_over_stored_payload():
    # Un payload stocké périmé/écrit hors API peut activer sync_tables/process_tp_sl ;
    # le dispatch doit les forcer à false (le snapshot remplace tout effet de bord).
    persisted = {
        "dry_run": True,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "scalper_micro",
        "sync_tables": True,
        "process_tp_sl": True,
        "symbols": ["BTCUSDT"],
    }
    orm = _orm_set(set_id="s", payload=persisted, symbols=["BTCUSDT"])
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(client, "http://sym", orm, None)

    result = asyncio.run(_run())
    assert captured["json"]["sync_tables"] is False
    assert captured["json"]["process_tp_sl"] is False
    assert result["payload_sent"]["process_tp_sl"] is False
    # Le payload stocké n'est pas muté.
    assert persisted["sync_tables"] is True
    assert persisted["process_tp_sl"] is True


def test_run_persisted_set_rebuilds_from_orm_columns_not_stored_payload():
    # Ligne écrite hors API : le `payload` stocké diverge des colonnes ORM ET
    # contient un flag de contrôle runner. Le dispatch doit TOUJOURS reconstruire
    # depuis les colonnes (allow-list) : champs réalignés, flag parasite supprimé.
    persisted = {
        "dry_run": True,            # divergent (colonne dry_run=False)
        "workers": 1,
        "exchange": "okx",          # divergent (colonne 'bitmart')
        "market_type": "spot",      # divergent (colonne 'perpetual')
        "mtf_profile": "scalper_micro",
        "sync_tables": True,        # divergent (doit finir false)
        "process_tp_sl": True,      # divergent (doit finir false)
        "symbols": ["ETHUSDT"],     # divergent (colonne ['BTCUSDT'])
        "skip_open_state_filter": True,  # flag de contrôle runner parasite
    }
    orm = _orm_set(
        set_id="s", payload=persisted, exchange="bitmart",
        market_type="perpetual", symbols=["BTCUSDT"], dry_run=False,
    )
    snapshot = {"open_positions": [], "open_orders": []}
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            # dry_run=False (override run-level) ; les colonnes font autorité.
            return await run_persisted_set(client, "http://sym", orm, snapshot, dry_run=False)

    asyncio.run(_run())
    sent = captured["json"]
    assert sent["exchange"] == "bitmart"
    assert sent["market_type"] == "perpetual"
    assert sent["symbols"] == ["BTCUSDT"]
    assert sent["dry_run"] is False
    assert sent["sync_tables"] is False
    assert sent["process_tp_sl"] is False
    assert sent["mtf_profile"] == "scalper_micro"
    # Flag de contrôle runner stocké => supprimé (allow-list stricte).
    assert "skip_open_state_filter" not in sent
    assert set(sent.keys()) <= {
        "dry_run", "workers", "exchange", "market_type", "mtf_profile",
        "sync_tables", "process_tp_sl", "symbols", "open_state_snapshot", "config_hash",
    }


def test_run_persisted_set_clamps_oversized_workers():
    # Ligne écrite hors API avec workers>1 (aucune contrainte DB) : le dispatch
    # doit clamper à la borne (politique « workers=1 côté Symfony »).
    orm = _orm_set(set_id="s", payload=None, symbols=["BTCUSDT"], workers=8)
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(client, "http://sym", orm, None)

    asyncio.run(_run())
    assert captured["json"]["workers"] == 1


def test_run_persisted_set_not_materialized_when_symbols_blank():
    # symbols=[" "] est truthy mais se réduit à du vide => not materialized, pas
    # de dispatch « tout l'univers ».
    orm = _orm_set(set_id="s", payload=None, symbols=[" "])
    calls: list = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(client, "http://sym", orm, None)

    result = asyncio.run(_run())
    assert result["ok"] is False
    assert "not materialized" in result["body"]
    assert calls == []


def test_run_persisted_set_not_materialized_even_with_stale_payload():
    # symbols vidé en base mais un `payload` périmé subsiste : on doit échouer
    # « not materialized » (pas de run « tout l'univers ») et NE PAS appeler Symfony.
    persisted = {
        "dry_run": True, "workers": 1, "exchange": "bitmart",
        "market_type": "perpetual", "mtf_profile": "scalper_micro",
        "sync_tables": False, "process_tp_sl": False, "symbols": ["BTCUSDT"],
    }
    orm = _orm_set(set_id="s", payload=persisted, symbols=[])
    calls: list = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(client, "http://sym", orm, None)

    result = asyncio.run(_run())
    assert result["ok"] is False
    assert result["payload_sent"] is None
    assert "not materialized" in result["body"]
    assert calls == []  # aucun appel HTTP


def test_run_persisted_set_falls_back_to_generate_when_payload_missing():
    # payload absent => repli sur generate_set_payload (symboles présents).
    orm = _orm_set(set_id="s", payload=None)
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["json"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(client, "http://sym", orm, None)

    result = asyncio.run(_run())
    assert result["ok"] is True
    assert captured["json"]["symbols"] == ["BTCUSDT", "ETHUSDT"]
    assert captured["json"]["sync_tables"] is False
    assert "open_state_snapshot" not in captured["json"]


def test_run_persisted_set_not_materialized_without_symbols():
    # Aucun payload ni symbole concret => échec sans appel HTTP.
    orm = _orm_set(set_id="s", payload=None, symbols=[])
    calls: list = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(client, "http://sym", orm, None)

    result = asyncio.run(_run())
    assert result["ok"] is False
    assert result["payload_sent"] is None
    assert "not materialized" in result["body"]
    assert calls == []  # aucun appel HTTP


# --- OBS-003 : en-têtes de lineage + fetch_run_trade_outcome ----------------


def test_run_persisted_set_propagates_orchestration_headers():
    orm = _orm_set(set_id="s1", dashboard_id=42, symbols=["BTCUSDT"], exchange="fake")
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["headers"] = dict(request.headers)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(
                client, "http://sym", orm, None, dry_run=True, run_id="run_dashA_20260617"
            )

    asyncio.run(_run())
    headers = captured["headers"]
    assert headers["x-run-id"] == "run_dashA_20260617"
    # corrélation = algorithme PARTAGÉ avec Symfony (identité ici car court+sûr).
    assert headers["x-run-correlation-id"] == canonical_correlation_id("run_dashA_20260617")
    assert headers["x-orchestration-set-id"] == "s1"
    assert headers["x-orchestration-dashboard-id"] == "42"
    assert headers["x-fake-only-safety-evidence"] == "v1"


def test_run_persisted_set_hashes_long_run_id_in_correlation_header():
    orm = _orm_set(set_id="s1", dashboard_id=1, symbols=["BTCUSDT"])
    long_run = "run_" + "b" * 64  # 68 chars > 64
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["headers"] = dict(request.headers)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(
                client, "http://sym", orm, None, dry_run=True, run_id=long_run
            )

    asyncio.run(_run())
    headers = captured["headers"]
    assert headers["x-run-id"] == long_run  # original conservé
    corr = headers["x-run-correlation-id"]
    assert len(corr) == 64 and corr != long_run[:64]  # haché, jamais tronqué


def test_run_persisted_set_without_run_id_sends_no_orchestration_headers():
    orm = _orm_set(set_id="s1", dashboard_id=42, symbols=["BTCUSDT"], exchange="fake")
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["headers"] = dict(request.headers)
        return httpx.Response(200, json={"status": "success"})

    async def _run():
        async with _client_with(handler) as client:
            return await run_persisted_set(client, "http://sym", orm, None, dry_run=True)

    asyncio.run(_run())
    headers = captured["headers"]
    assert "x-run-id" not in headers
    assert "x-run-correlation-id" not in headers
    assert "x-orchestration-set-id" not in headers
    assert headers["x-fake-only-safety-evidence"] == "v1"


def test_fetch_run_trade_outcome_returns_body_and_forwards_params():
    captured: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["path"] = request.url.path
        captured["params"] = dict(request.url.params)
        return httpx.Response(200, json={
            "run_id": "run_x",
            "correlation_run_id": "run_x",
            "source_available": True,
            "summary": {"trade_count": 3},
        })

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_run_trade_outcome(client, "http://sym", "run_x", "s1")

    body = asyncio.run(_run())
    assert captured["path"] == "/api/positions/analysis"
    assert captured["params"] == {"run_id": "run_x", "set_id": "s1"}
    assert body["summary"]["trade_count"] == 3


def test_fetch_run_trade_outcome_run_without_trade_is_success():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={
            "correlation_run_id": "run_x",
            "source_available": True,
            "summary": {"trade_count": 0},
        })

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_run_trade_outcome(client, "http://sym", "run_x")

    body = asyncio.run(_run())
    assert body["summary"]["trade_count"] == 0


def test_fetch_run_trade_outcome_raises_on_source_unavailable_flag():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(503, json={"source_available": False, "error": "source_unavailable"})

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_run_trade_outcome(client, "http://sym", "run_x")

    with pytest.raises(OutcomeUnavailableError):
        asyncio.run(_run())


def test_fetch_run_trade_outcome_raises_on_5xx():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(500, text="boom")

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_run_trade_outcome(client, "http://sym", "run_x")

    with pytest.raises(OutcomeUnavailableError):
        asyncio.run(_run())


def test_fetch_run_trade_outcome_raises_on_http_error():
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("refused")

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_run_trade_outcome(client, "http://sym", "run_x")

    with pytest.raises(OutcomeUnavailableError):
        asyncio.run(_run())


def test_fetch_run_trade_outcome_raises_on_invalid_json():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, content=b"not-json", headers={"content-type": "application/json"})

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_run_trade_outcome(client, "http://sym", "run_x")

    with pytest.raises(OutcomeUnavailableError):
        asyncio.run(_run())


def test_fetch_run_trade_outcome_raises_on_4xx():
    # 404/403 (route absente pendant un deploy, proxy/auth) = indisponibilite, jamais
    # un agregat vide "0 trade" : le run est deja confirme cote orchestrateur.
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(404, json={"error": "not found"})

    async def _run():
        async with _client_with(handler) as client:
            return await fetch_run_trade_outcome(client, "http://sym", "run_x")

    with pytest.raises(OutcomeUnavailableError):
        asyncio.run(_run())
