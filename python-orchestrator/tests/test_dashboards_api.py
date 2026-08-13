"""Tests de l'API de gestion des dashboards et sets (PY-002)."""

from __future__ import annotations

import pytest

from app.modern_trading_contracts import calculate_config_hash, calculate_snapshot_hash


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


def _canonical_identity_payload():
    catalog_hash = "sha256:" + "b" * 64
    config = {
        "schema_version": "effective-trading-config.v2",
        "units": {"percent": "percentage_points", "duration": "iso8601", "price": "quote_price", "notional": "quote_notional"},
        "safety": {"mainnet_write_enabled": False, "demo_testnet_write_enabled": False, "require_stop_loss": True, "kill_switch_enabled": True},
        "mode": {"mode_id": "scalping", "mode_version": "1.0.0"},
        "setup": {"setup_id": "scalping.pullback.long", "setup_version": "1.0.0", "side": "long"},
        "exchange": {"id": "fake"},
        "environment": {"id": "test"},
    }
    config_hash = calculate_config_hash(config, catalog_hash)
    layers = [
        {"type": kind, "name": kind, "path": f"/{kind}.yaml", "required": True}
        for kind in ("base", "mode", "setup", "exchange", "mode_exchange", "environment")
    ]
    snapshot = {
        "request": {"mode_id": "scalping", "mode_version": "1.0.0", "setup_id": "scalping.pullback.long", "setup_version": "1.0.0", "exchange": "fake", "environment": "test", "side": "long"},
        "config": config,
        "config_hash": config_hash,
        "condition_catalog_hash": catalog_hash,
        "ordered_layers": layers,
        "ordered_files": [layer["path"] for layer in layers],
        "provenance": {"mode.mode_id": layers[1]},
        "executable": True,
        "blockers": [],
    }
    snapshot["snapshot_hash"] = calculate_snapshot_hash(snapshot)
    return {
        "mode_id": "scalping",
        "mode_version": "1.0.0",
        "setup_id": "scalping.pullback.long",
        "setup_version": "1.0.0",
        "config_hash": config_hash,
        "condition_catalog_hash": catalog_hash,
        "side": "LONG",
        "effective_config_reference": "effective-config:cfg-1",
        "effective_config_snapshot": snapshot,
    }


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


def test_patch_dashboard_explicit_null_on_not_null_field_rejected(api_client):
    """{"name": null}/{"enabled": null} → 422, pas un 409 trompeur ni une écriture."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    for field in ("name", "enabled"):
        resp = api_client.patch(f"/dashboards/{dashboard_id}", json={field: None})
        assert resp.status_code == 422, field
    # description est nullable : un null explicite l'efface.
    resp = api_client.patch(f"/dashboards/{dashboard_id}", json={"description": None})
    assert resp.status_code == 200
    assert resp.json()["description"] is None


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


def test_create_set_rejects_non_url_safe_set_id(api_client):
    # set_id est adressé en URL (/sets/{set_id}, /runs/{run_id}/sets/{set_id}) : un
    # set_id porteur de `/` (ou d'espaces) serait stocké mais non récupérable. On le
    # rejette à l'écriture (422) plutôt que de le sanitiser.
    dashboard_id = _create_dashboard(api_client).json()["id"]
    for bad in ("mtf/regular/top", "with space", "slash\\back"):
        resp = api_client.post(
            f"/dashboards/{dashboard_id}/sets", json=_set_payload(set_id=bad)
        )
        assert resp.status_code == 422, bad
    # Un set_id URL-safe (alphanumérique + _ . -) reste accepté.
    ok = api_client.post(
        f"/dashboards/{dashboard_id}/sets", json=_set_payload(set_id="mtf.regular-top_1")
    )
    assert ok.status_code == 201


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


def test_create_read_and_patch_preserve_exact_canonical_identity(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    identity = _canonical_identity_payload()
    create_payload = _set_payload(
        set_id="canonical",
        exchange="fake",
        environment="test",
        mtf_profile="scalping",
        trading_identity=identity,
    )

    created = api_client.post(
        f"/dashboards/{dashboard_id}/sets", json=create_payload
    )
    assert created.status_code == 201
    assert created.json()["trading_identity"] == identity
    assert created.json()["payload"]["trading_identity"] == identity
    assert created.json()["effective_payload"]["trading_identity"] == identity

    read = api_client.get(f"/dashboards/{dashboard_id}/sets/canonical")
    assert read.status_code == 200
    assert read.json()["trading_identity"] == identity

    patched = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/canonical",
        json={"symbols": ["SOLUSDT"]},
    )
    assert patched.status_code == 200
    assert patched.json()["trading_identity"] == identity
    assert patched.json()["payload"]["trading_identity"] == identity
    assert patched.json()["effective_payload"]["trading_identity"] == identity


def test_patch_can_promote_historical_legacy_set_with_exact_identity(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="legacy", exchange="fake", environment="test"),
    )
    identity = _canonical_identity_payload()

    patched = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/legacy",
        json={"mtf_profile": "scalping", "trading_identity": identity},
    )

    assert patched.status_code == 200
    assert patched.json()["trading_identity"] == identity


@pytest.mark.parametrize(
    ("override", "error"),
    [
        ({"exchange": "okx", "environment": "demo"}, "canonical_exchange_mismatch"),
        ({"environment": "local"}, "canonical_environment_mismatch"),
        ({"mtf_profile": "scalper"}, "canonical_profile_mismatch"),
    ],
)
def test_create_rejects_canonical_set_context_contradictions(api_client, override, error):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    payload = _set_payload(
        set_id="contradiction",
        exchange="fake",
        environment="test",
        mtf_profile="scalping",
        trading_identity=_canonical_identity_payload(),
    )
    payload.update(override)

    response = api_client.post(f"/dashboards/{dashboard_id}/sets", json=payload)

    assert response.status_code == 422
    assert error in response.text
    assert api_client.get(f"/dashboards/{dashboard_id}/sets").json() == []


@pytest.mark.parametrize(
    ("patch", "error"),
    [
        ({"exchange": "okx"}, "canonical_exchange_mismatch"),
        ({"environment": "local"}, "canonical_environment_mismatch"),
        ({"mtf_profile": "scalper"}, "canonical_profile_mismatch"),
    ],
)
def test_patch_validates_merged_canonical_set_context(api_client, patch, error):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    identity = _canonical_identity_payload()
    created = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(
            set_id="canonical",
            exchange="fake",
            environment="test",
            mtf_profile="scalping",
            trading_identity=identity,
        ),
    )
    assert created.status_code == 201

    response = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/canonical", json=patch
    )

    assert response.status_code == 422
    assert error in response.text
    persisted = api_client.get(f"/dashboards/{dashboard_id}/sets/canonical").json()
    assert persisted["trading_identity"] == identity
    for key in patch:
        assert persisted[key] == created.json()[key]


def test_patch_rejects_replacing_immutable_canonical_identity(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    identity = _canonical_identity_payload()
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(
            set_id="canonical",
            exchange="fake",
            environment="test",
            mtf_profile="scalping",
            trading_identity=identity,
        ),
    )
    replacement = _canonical_identity_payload()
    replacement["effective_config_reference"] = "effective-config:cfg-2"

    response = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/canonical",
        json={"trading_identity": replacement},
    )

    assert response.status_code == 422
    assert "canonical_identity_immutable" in response.text
    assert api_client.get(
        f"/dashboards/{dashboard_id}/sets/canonical"
    ).json()["trading_identity"] == identity


def test_historical_set_without_identity_remains_legacy(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    created = api_client.post(
        f"/dashboards/{dashboard_id}/sets", json=_set_payload()
    )

    assert created.status_code == 201
    assert created.json()["trading_identity"] is None
    assert "trading_identity" not in created.json()["payload"]
    assert "trading_identity" not in created.json()["effective_payload"]


@pytest.mark.parametrize("mtf_profile", ["day_trading", "scalping", "micro_scalping"])
def test_create_requires_identity_for_every_modern_profile(api_client, mtf_profile):
    dashboard_id = _create_dashboard(api_client).json()["id"]

    response = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="modern", mtf_profile=mtf_profile),
    )

    assert response.status_code == 422
    assert "canonical_identity_required" in response.text
    assert api_client.get(f"/dashboards/{dashboard_id}/sets").json() == []


@pytest.mark.parametrize("mtf_profile", ["day_trading", "scalping", "micro_scalping"])
def test_patch_merged_state_requires_identity_for_modern_profile(
    api_client, mtf_profile
):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    created = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="legacy", mtf_profile="regular"),
    ).json()

    response = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/legacy",
        json={"mtf_profile": mtf_profile},
    )

    assert response.status_code == 422
    assert response.json()["detail"] == "canonical_identity_required"
    assert api_client.get(f"/dashboards/{dashboard_id}/sets/legacy").json() == created


@pytest.mark.parametrize("mtf_profile", ["regular", "scalper", "scalper_micro"])
def test_legacy_profiles_without_identity_remain_accepted_and_readable(
    api_client, mtf_profile
):
    dashboard_id = _create_dashboard(api_client).json()["id"]

    created = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id=f"legacy-{mtf_profile}", mtf_profile=mtf_profile),
    )

    assert created.status_code == 201
    assert created.json()["trading_identity"] is None
    read = api_client.get(
        f"/dashboards/{dashboard_id}/sets/legacy-{mtf_profile}"
    )
    assert read.status_code == 200
    assert read.json()["trading_identity"] is None


def test_create_set_rejects_incomplete_canonical_identity_without_persisting(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]

    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(
            set_id="pending_identity",
            trading_identity={"mode_id": "scalping"},
        ),
    )

    assert resp.status_code == 422
    assert "trading_identity" in resp.text
    assert api_client.get(f"/dashboards/{dashboard_id}/sets").json() == []


def test_patch_set_rejects_canonical_identity_without_changing_set(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    created = api_client.post(
        f"/dashboards/{dashboard_id}/sets", json=_set_payload()
    ).json()

    resp = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/bitmart_regular_top",
        json={"trading_identity": None, "priority": 42},
    )

    assert resp.status_code == 422
    assert "champ 'trading_identity' ne peut pas être null" in resp.text
    persisted = api_client.get(
        f"/dashboards/{dashboard_id}/sets/bitmart_regular_top"
    ).json()
    assert persisted == created


# --- Payload serveur (PY-004) -----------------------------------------------


def test_create_set_generates_payload(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]

    body = api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload()).json()

    # Le payload /api/mtf/run est généré côté serveur dès la création.
    assert body["payload"] == {
        "dry_run": True,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "regular",
        "sync_tables": False,
        "process_tp_sl": False,
        "symbols": ["BTCUSDT", "ETHUSDT"],
    }


def test_set_read_exposes_effective_payload(api_client):
    # PY-007 : la lecture d'un set expose le payload /api/mtf/run EFFECTIF (ce qui
    # part réellement à Symfony), pour que le cockpit n'ait plus à le reconstruire.
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())

    body = api_client.get(f"/dashboards/{dashboard_id}/sets/bitmart_regular_top").json()

    assert body["effective_payload"] == {
        "dry_run": True,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "regular",
        "sync_tables": False,
        "process_tp_sl": False,
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "config_hash": "sha256:7a427521b12ca8c1a789200261533a331e89c62d9560565826dfc3eb3a49ad55",
    }


def test_set_read_effective_payload_null_when_not_materialized(api_client):
    # Set capé sans symboles concrets (sélection pas encore rafraîchie) : le payload
    # effectif est null, et le front en déduit « set non matérialisé ».
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(symbols=[], contracts_limit=5),
    )

    body = api_client.get(f"/dashboards/{dashboard_id}/sets/bitmart_regular_top").json()

    assert body["effective_payload"] is None


def test_patch_set_regenerates_payload(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())

    body = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/bitmart_regular_top",
        json={"symbols": ["SOLUSDT"], "mtf_profile": "scalper_micro"},
    ).json()

    # Le payload reflète l'état résultant du PATCH.
    assert body["payload"]["symbols"] == ["SOLUSDT"]
    assert body["payload"]["mtf_profile"] == "scalper_micro"
    assert body["payload"]["sync_tables"] is False


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


def test_patch_set_rejects_recipe_fault_profile_outside_safe_envelope(api_client):
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="bitmart_dry", exchange="bitmart", dry_run=True),
    )

    resp = api_client.patch(
        f"/dashboards/{dashboard_id}/sets/bitmart_dry",
        json={"mtf_profile": "recipe_functional_error"},
    )

    assert resp.status_code == 422
    assert "restricted to fake/demo dry-run" in resp.json()["detail"]


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


def test_create_blank_only_symbols_rejected(api_client):
    """symbols blancs uniquement (et pas de contracts_limit) → set ambigu, refusé.

    Sans normalisation, une telle liste « non vide » passerait la validation mais
    se réduirait à vide au dispatch (« not materialized » à chaque run) : on la
    rejette à la création.
    """
    dashboard_id = _create_dashboard(api_client).json()["id"]
    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(set_id="blank", symbols=["  ", ""], contracts_limit=None),
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
    body = resp.json()
    assert body["contracts_limit"] == 20
    # Sélection non matérialisée : pas de payload « run-all » trompeur tant qu'un
    # refresh n'a pas renseigné de symboles concrets (le payload reste null).
    assert body["payload"] is None


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
    """payload est read-only : un payload client est ignoré ; le serveur génère le sien (PY-004)."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    resp = api_client.post(
        f"/dashboards/{dashboard_id}/sets",
        json=_set_payload(payload={"forged": True}),
    )
    assert resp.status_code == 201
    payload = resp.json()["payload"]
    # Le payload forgé par le client est ignoré ; c'est le payload serveur qui est stocké.
    assert "forged" not in payload
    assert payload["exchange"] == "bitmart"
    assert payload["sync_tables"] is False


def test_patch_explicit_null_on_not_null_field_rejected(api_client):
    """{"exchange": null} ne doit pas passer (colonne NOT NULL) ni faire un 500."""
    dashboard_id = _create_dashboard(api_client).json()["id"]
    api_client.post(f"/dashboards/{dashboard_id}/sets", json=_set_payload())
    for field in ("exchange", "dry_run", "enabled", "symbols"):
        resp = api_client.patch(
            f"/dashboards/{dashboard_id}/sets/bitmart_regular_top", json={field: None}
        )
        assert resp.status_code == 422, field
