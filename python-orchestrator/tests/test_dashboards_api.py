"""Tests de l'API de gestion des dashboards et sets (PY-002)."""

from __future__ import annotations


def _create_dashboard(client, name="dash_a", enabled=True, description="demo"):
    return client.post(
        "/dashboards",
        json={"name": name, "enabled": enabled, "description": description},
    )


def _set_payload(**overrides):
    payload = {
        "set_id": "bitmart_regular_top",
        "exchange": "bitmart",
        "mtf_profile": "regular",
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "priority": 10,
    }
    payload.update(overrides)
    return payload


# --- Dashboards -------------------------------------------------------------


def test_create_then_get_dashboard(api_client):
    created = _create_dashboard(api_client)
    assert created.status_code == 201
    body = created.json()
    assert body["name"] == "dash_a"
    assert body["enabled"] is True
    assert "id" in body and "created_at" in body

    fetched = api_client.get(f"/dashboards/{body['id']}")
    assert fetched.status_code == 200
    assert fetched.json()["id"] == body["id"]


def test_list_dashboards_sorted_by_name(api_client):
    _create_dashboard(api_client, name="zeta")
    _create_dashboard(api_client, name="alpha")

    names = [d["name"] for d in api_client.get("/dashboards").json()]
    assert names == ["alpha", "zeta"]


def test_duplicate_dashboard_name_returns_409(api_client):
    _create_dashboard(api_client, name="dup")
    conflict = _create_dashboard(api_client, name="dup")
    assert conflict.status_code == 409


def test_get_missing_dashboard_returns_404(api_client):
    assert api_client.get("/dashboards/9999").status_code == 404


def test_patch_dashboard_partial(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]

    resp = api_client.patch(f"/dashboards/{dashboard_id}", json={"enabled": False})
    assert resp.status_code == 200
    body = resp.json()
    assert body["enabled"] is False
    assert body["name"] == "dash_a"  # non fourni → inchangé


def test_delete_dashboard_then_404(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    assert api_client.delete(f"/dashboards/{dashboard_id}").status_code == 204
    assert api_client.get(f"/dashboards/{dashboard_id}").status_code == 404


# --- Sets -------------------------------------------------------------------


def test_create_and_get_set(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]

    created = api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())
    assert created.status_code == 201
    body = created.json()
    assert body["set_id"] == "bitmart_regular_top"
    assert body["symbols"] == ["BTCUSDT", "ETHUSDT"]
    assert body["dashboard_id"] == dashboard_id
    # Défauts appliqués.
    assert body["dry_run"] is True
    assert body["workers"] == 1

    fetched = api_client.get(f"/dashboards/{dashboard_id}/sets/bitmart_regular_top")
    assert fetched.status_code == 200
    assert fetched.json()["id"] == body["id"]


def test_create_set_on_missing_dashboard_returns_404(api_client):
    assert api_client.post("/dashboards/123/sets", json=_set_payload()).status_code == 404


def test_duplicate_set_id_returns_409(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())
    dup = api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())
    assert dup.status_code == 409


def test_list_sets_enabled_only_and_priority_order(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="low", priority=1, enabled=True),
    )
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="high", priority=10, enabled=True),
    )
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="off", priority=99, enabled=False),
    )

    all_ids = [s["set_id"] for s in api_client.get(f"/dashboards/{dashboard_id}/sets").json()]
    assert all_ids == ["off", "high", "low"]  # tri priorité desc

    active_ids = [
        s["set_id"]
        for s in api_client.get(
            f"/dashboards/{dashboard_id}/sets", params={"enabled_only": True}
        ).json()
    ]
    assert active_ids == ["high", "low"]


def test_patch_set_partial(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())

    resp = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/bitmart_regular_top",
        json={"priority": 42, "symbols": ["SOLUSDT"]},
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["priority"] == 42
    assert body["symbols"] == ["SOLUSDT"]
    assert body["exchange"] == "bitmart"  # inchangé


def test_delete_set_then_404(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())

    assert (
        api_client.delete(
            f"/dashboards/{dashboard_id}/sets/bitmart_regular_top"
        ).status_code
        == 204
    )
    assert (
        api_client.get(f"/dashboards/{dashboard_id}/sets/bitmart_regular_top").status_code
        == 404
    )


# --- Garde-fous live --------------------------------------------------------


def test_create_okx_live_set_rejected(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="okx_live", exchange="okx", dry_run=False),
    )
    assert resp.status_code == 422


def test_patch_set_to_live_forbidden_rejected(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    # Set OKX en dry-run autorisé.
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="okx_dry", exchange="okx", dry_run=True),
    )
    # Bascule live via PATCH partiel (seul dry_run fourni) → refusée.
    resp = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/okx_dry", json={"dry_run": False}
    )
    assert resp.status_code == 422


def test_workers_above_bound_rejected(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="too_many", workers=4),
    )
    assert resp.status_code == 422


def test_create_bitmart_live_rejected(api_client):
    """Aucun live persistable en PY-002, même sur un exchange autorisé live."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="bm_live", exchange="bitmart", dry_run=False),
    )
    assert resp.status_code == 422


# --- Invariant de sélection -------------------------------------------------


def test_create_ambiguous_set_rejected(api_client):
    """Ni symbols, ni contracts_limit → set ambigu, refusé."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="ambiguous", symbols=[], contracts_limit=None),
    )
    assert resp.status_code == 422


def test_create_with_contracts_limit_only_ok(api_client):
    """Sélection dynamique bornée (contracts_limit) sans symbols explicites."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="dyn", symbols=[], contracts_limit=20),
    )
    assert resp.status_code == 201
    assert resp.json()["contracts_limit"] == 20


def test_patch_clearing_symbols_without_limit_rejected(api_client):
    """Vider symbols alors qu'aucune limite n'est posée → état ambigu, refusé."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())
    resp = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/bitmart_regular_top", json={"symbols": []}
    )
    assert resp.status_code == 422


def test_patch_clear_contracts_limit_null_ok(api_client):
    """contracts_limit est nullable : un null explicite l'efface (symbols restent)."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(contracts_limit=10),
    )
    resp = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/bitmart_regular_top",
        json={"contracts_limit": None},
    )
    assert resp.status_code == 200
    assert resp.json()["contracts_limit"] is None


# --- payload non writable + nulls explicites --------------------------------


def test_payload_not_accepted_from_client_on_create(api_client):
    """payload est read-only : un payload client est ignoré, pas persisté."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(payload={"forged": True}),
    )
    assert resp.status_code == 201
    assert resp.json()["payload"] is None


def test_patch_explicit_null_on_not_null_field_rejected(api_client):
    """{"exchange": null} ne doit pas passer (colonne NOT NULL) ni faire un 500."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())
    for field in ("exchange", "dry_run", "enabled", "symbols"):
        resp = api_client.patch(
            f"/dashboards/{dashboard_id}/sets/bitmart_regular_top", json={field: None}
        )
        assert resp.status_code == 422, field
