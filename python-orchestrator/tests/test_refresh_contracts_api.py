"""Tests de la route de refresh explicite des contrats (PY-003).

``POST /dashboards/{id}/refresh-contracts`` interroge Symfony (mocké ici) une fois
par couple (profil, exchange, market_type), écrit les ``symbols`` des sets actifs
`mtf_run` et renvoie un aperçu. Fail-closed : tout fetch en échec => 502 sans
écriture partielle.
"""

from __future__ import annotations

import pytest

from app.services import symfony_client


def _mk_dashboard(client, name="dash"):
    resp = client.post("/dashboards", json={"name": name})
    assert resp.status_code == 201, resp.text
    return resp.json()["id"]


def _mk_set(client, dashboard_id, set_id, **overrides):
    payload = {
        "set_id": set_id,
        "exchange": "bitmart",
        "mtf_profile": "scalper_micro",
        "symbols": ["OLD"],
    }
    payload.update(overrides)
    resp = client.post(f"/dashboards/{dashboard_id}/sets", json=payload)
    assert resp.status_code == 201, resp.text
    return resp.json()


def _get_set(client, dashboard_id, set_id):
    resp = client.get(f"/dashboards/{dashboard_id}/sets/{set_id}")
    assert resp.status_code == 200, resp.text
    return resp.json()


class _Recorder:
    """Stub async de ``fetch_selected_contracts`` qui enregistre ses appels."""

    def __init__(self, symbols_by_profile=None):
        self.calls = []
        self._by_profile = symbols_by_profile or {}

    async def __call__(self, client, base_url, profile, exchange, market_type):
        self.calls.append((profile, exchange, market_type))
        symbols = self._by_profile.get(profile, ["BTCUSDT", "ETHUSDT", "SOLUSDT"])
        return {
            "profile": profile,
            "exchange": exchange,
            "market_type": market_type,
            "count": len(symbols),
            "symbols": list(symbols),
            "filters": {"top_n": 140},
        }


def _patch_fetch(monkeypatch, recorder):
    monkeypatch.setattr(symfony_client, "fetch_selected_contracts", recorder)


def test_refresh_updates_symbols_and_returns_preview(api_client, monkeypatch):
    dashboard_id = _mk_dashboard(api_client)
    _mk_set(api_client, dashboard_id, "s1")
    _patch_fetch(monkeypatch, _Recorder())

    resp = api_client.post(f"/dashboards/{dashboard_id}/refresh-contracts")
    assert resp.status_code == 200, resp.text
    body = resp.json()

    assert body["dashboard_id"] == dashboard_id
    assert body["count"] == 1
    preview = body["sets"][0]
    assert preview["set_id"] == "s1"
    assert preview["symbol_count"] == 3
    assert preview["mtf_profile"] == "scalper_micro"
    assert preview["filters"] == {"top_n": 140}

    # Symboles réellement persistés en DB.
    assert _get_set(api_client, dashboard_id, "s1")["symbols"] == [
        "BTCUSDT",
        "ETHUSDT",
        "SOLUSDT",
    ]


def test_refresh_respects_contracts_limit(api_client, monkeypatch):
    dashboard_id = _mk_dashboard(api_client)
    # Set borné (contracts_limit=2, symbols vide autorisé) + set sans limite.
    _mk_set(api_client, dashboard_id, "capped", symbols=[], contracts_limit=2)
    _mk_set(api_client, dashboard_id, "full")
    _patch_fetch(monkeypatch, _Recorder())

    resp = api_client.post(f"/dashboards/{dashboard_id}/refresh-contracts")
    assert resp.status_code == 200, resp.text

    assert _get_set(api_client, dashboard_id, "capped")["symbols"] == ["BTCUSDT", "ETHUSDT"]
    assert _get_set(api_client, dashboard_id, "full")["symbols"] == [
        "BTCUSDT",
        "ETHUSDT",
        "SOLUSDT",
    ]


def test_refresh_one_fetch_per_distinct_triple(api_client, monkeypatch):
    dashboard_id = _mk_dashboard(api_client)
    # Deux sets partageant (profil, exchange, market_type) + un sur un autre profil.
    _mk_set(api_client, dashboard_id, "a", mtf_profile="scalper_micro")
    _mk_set(api_client, dashboard_id, "b", mtf_profile="scalper_micro")
    _mk_set(api_client, dashboard_id, "c", mtf_profile="regular")
    recorder = _Recorder()
    _patch_fetch(monkeypatch, recorder)

    resp = api_client.post(f"/dashboards/{dashboard_id}/refresh-contracts")
    assert resp.status_code == 200, resp.text
    assert resp.json()["count"] == 3
    # Un seul fetch par couple distinct : 2 couples => 2 appels (pas 3).
    assert sorted(recorder.calls) == [
        ("regular", "bitmart", "perpetual"),
        ("scalper_micro", "bitmart", "perpetual"),
    ]


def test_refresh_failclosed_no_write_on_fetch_error(api_client, monkeypatch):
    dashboard_id = _mk_dashboard(api_client)
    _mk_set(api_client, dashboard_id, "ok_set", mtf_profile="scalper_micro", symbols=["OLD_OK"])
    _mk_set(api_client, dashboard_id, "ko_set", mtf_profile="regular", symbols=["OLD_KO"])

    async def _fetch(client, base_url, profile, exchange, market_type):
        if profile == "regular":
            raise symfony_client.ContractsUnavailableError("symfony down")
        return {
            "profile": profile,
            "exchange": exchange,
            "market_type": market_type,
            "count": 1,
            "symbols": ["NEW"],
            "filters": {},
        }

    monkeypatch.setattr(symfony_client, "fetch_selected_contracts", _fetch)

    resp = api_client.post(f"/dashboards/{dashboard_id}/refresh-contracts")
    assert resp.status_code == 502, resp.text

    # AUCUNE écriture partielle : même le groupe qui a réussi reste inchangé.
    assert _get_set(api_client, dashboard_id, "ok_set")["symbols"] == ["OLD_OK"]
    assert _get_set(api_client, dashboard_id, "ko_set")["symbols"] == ["OLD_KO"]


def test_refresh_failclosed_on_empty_selection_for_uncapped_set(api_client, monkeypatch):
    # Set non capé (contracts_limit=None) : une sélection vide le rendrait ambigu
    # (ni symbols, ni limite) et build_mtf_payload lancerait tout l'univers => 409.
    dashboard_id = _mk_dashboard(api_client)
    _mk_set(api_client, dashboard_id, "uncapped", symbols=["OLD"])
    _patch_fetch(monkeypatch, _Recorder(symbols_by_profile={"scalper_micro": []}))

    resp = api_client.post(f"/dashboards/{dashboard_id}/refresh-contracts")
    assert resp.status_code == 409, resp.text
    # Fail-closed : aucune écriture, les symboles précédents sont préservés.
    assert _get_set(api_client, dashboard_id, "uncapped")["symbols"] == ["OLD"]


def test_refresh_allows_empty_selection_for_capped_set(api_client, monkeypatch):
    # Set capé : une sélection vide reste valide (contracts_limit porte la sélection).
    dashboard_id = _mk_dashboard(api_client)
    _mk_set(api_client, dashboard_id, "capped", symbols=[], contracts_limit=5)
    _patch_fetch(monkeypatch, _Recorder(symbols_by_profile={"scalper_micro": []}))

    resp = api_client.post(f"/dashboards/{dashboard_id}/refresh-contracts")
    assert resp.status_code == 200, resp.text
    assert resp.json()["sets"][0]["symbol_count"] == 0
    assert _get_set(api_client, dashboard_id, "capped")["symbols"] == []


def test_refresh_skips_disabled_sets(api_client, monkeypatch):
    dashboard_id = _mk_dashboard(api_client)
    _mk_set(api_client, dashboard_id, "active")
    _mk_set(api_client, dashboard_id, "inactive", enabled=False, symbols=["KEEP"])
    _patch_fetch(monkeypatch, _Recorder())

    resp = api_client.post(f"/dashboards/{dashboard_id}/refresh-contracts")
    assert resp.status_code == 200, resp.text
    assert resp.json()["count"] == 1
    # Le set désactivé n'est pas rafraîchi.
    assert _get_set(api_client, dashboard_id, "inactive")["symbols"] == ["KEEP"]


def test_refresh_missing_dashboard_returns_404(api_client):
    assert api_client.post("/dashboards/9999/refresh-contracts").status_code == 404
