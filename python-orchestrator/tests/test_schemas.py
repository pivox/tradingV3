from datetime import datetime
import hashlib
import json

import pytest
from pydantic import ValidationError

from app.schemas import (
    Action,
    Environment,
    Exchange,
    MarketType,
    MtfProfile,
    OrchestratorSet,
    CanonicalEffectiveConfigRequest,
    CanonicalEffectiveConfigSnapshot,
    CanonicalTradingIdentity,
    SetCreate,
    SetRead,
    SetUpdate,
    assert_set_persistable,
    calculate_config_hash,
    calculate_snapshot_hash,
)


def test_full_php_133_snapshot_shape_round_trips_unchanged_with_hash_parity():
    catalog_hash = "sha256:" + "b" * 64
    config = {
        "schema_version": "effective-trading-config.v2",
        "units": {"percent": "percentage_points", "duration": "iso8601", "price": "quote_price", "notional": "quote_notional"},
        "safety": {"mainnet_write_enabled": False, "demo_testnet_write_enabled": False, "require_stop_loss": True, "kill_switch_enabled": True},
        "mode": {"mode_id": "scalping", "mode_version": "1.1.0"},
        "setup": {"setup_id": "scalping.pullback.long", "setup_version": "1.1.0", "side": "long"},
        "exchange": {"id": "fake"},
        "environment": {"id": "test", "note": "café/path"},
    }
    config_hash = calculate_config_hash(config, catalog_hash)
    layers = [
        {"type": kind, "name": kind, "path": f"/{kind}.yaml", "required": True}
        for kind in ("base", "mode", "setup", "exchange", "mode_exchange", "environment")
    ]
    payload = {
        "request": {"mode_id": "scalping", "mode_version": "1.1.0", "setup_id": "scalping.pullback.long", "setup_version": "1.1.0", "exchange": "fake", "environment": "test", "side": "long", "execution_capability": "backtest"},
        "config": config, "config_hash": config_hash, "condition_catalog_hash": catalog_hash,
        "ordered_layers": layers, "ordered_files": [layer["path"] for layer in layers],
        "provenance": {"mode.mode_id": layers[1]}, "executable": True, "blockers": [],
    }
    payload["snapshot_hash"] = calculate_snapshot_hash(payload)

    snapshot = CanonicalEffectiveConfigSnapshot(**payload)

    assert snapshot.model_dump(mode="json") == payload


def test_effective_snapshot_hash_normalizes_integral_float_and_deep_freezes_metadata():
    payload = _canonical_identity_payload()["effective_config_snapshot"]
    payload["config"]["environment"]["note"] = "café/path"
    payload["config"]["environment"]["leverage"] = 3.0
    payload["config_hash"] = calculate_config_hash(
        payload["config"], payload["condition_catalog_hash"]
    )
    payload["snapshot_hash"] = calculate_snapshot_hash(payload)

    snapshot = CanonicalEffectiveConfigSnapshot(**payload)
    before_hash = snapshot.config_hash
    before_payload = snapshot.model_dump(mode="json")

    with pytest.raises(TypeError):
        snapshot.provenance["mode.mode_id"] = {}  # type: ignore[index]
    with pytest.raises(TypeError):
        snapshot.provenance["mode.mode_id"]["path"] = "/mutated.yaml"  # type: ignore[index]

    assert snapshot.config_hash == before_hash
    assert snapshot.model_dump(mode="json") == before_payload


def test_php_api_integral_number_unicode_slash_hash_fixture_matches_python():
    config = {"leverage": 3, "note": "café/path"}
    canonical = json.dumps(
        {"config": config, "condition_catalog_hash": "sha256:" + "b" * 64},
        ensure_ascii=False, separators=(",", ":"), sort_keys=True,
    )
    assert "sha256:" + hashlib.sha256(canonical.encode("utf-8")).hexdigest() == (
        "sha256:1f55b0a0080a7c32b97ab8ff2907485ac3ebcb0dd4f1efb391b4c4b5f90c1418"
    )


def test_full_php_133_snapshot_rejects_layer_file_order_mismatch():
    payload = _canonical_identity_payload()["effective_config_snapshot"]
    payload["ordered_files"] = list(reversed(payload["ordered_files"]))
    with pytest.raises(ValidationError, match="effective_config_snapshot_layer_files_mismatch"):
        CanonicalEffectiveConfigSnapshot(**payload)


@pytest.mark.parametrize(
    ("case", "expected_error"),
    [
        ("config_not_mapping", "effective_config_snapshot.config must be a mapping"),
        ("provenance_empty", "effective_config_snapshot_provenance_empty"),
        ("layer_order", "effective_config_snapshot_layer_order_invalid"),
        ("roots", "effective_config_snapshot_roots_invalid"),
        ("schema_version", "effective_config_snapshot_schema_version_invalid"),
        ("config_identity", "effective_config_snapshot_config_identity_mismatch"),
        ("hash", "effective_config_snapshot_hash_mismatch"),
    ],
)
def test_effective_snapshot_rejects_each_fail_closed_contract_boundary(case, expected_error):
    payload = _canonical_identity_payload()["effective_config_snapshot"]

    if case == "config_not_mapping":
        payload["config"] = []
    elif case == "provenance_empty":
        payload["provenance"] = {}
    elif case == "layer_order":
        payload["ordered_layers"][0], payload["ordered_layers"][1] = (
            payload["ordered_layers"][1],
            payload["ordered_layers"][0],
        )
        payload["ordered_files"] = [layer["path"] for layer in payload["ordered_layers"]]
    elif case == "roots":
        del payload["config"]["safety"]
        _rehash_effective_snapshot(payload)
    elif case == "schema_version":
        payload["config"]["schema_version"] = "effective-trading-config.v1"
        _rehash_effective_snapshot(payload)
    elif case == "config_identity":
        payload["config"]["mode"]["mode_id"] = "day_trading"
        _rehash_effective_snapshot(payload)
    elif case == "hash":
        payload["config_hash"] = "sha256:" + "c" * 64

    with pytest.raises(ValidationError, match=expected_error):
        CanonicalEffectiveConfigSnapshot(**payload)


def test_effective_snapshot_round_trip_thaws_sequences_and_hashes_integral_floats():
    payload = _canonical_identity_payload()["effective_config_snapshot"]
    payload["config"]["environment"]["tags"] = ["paper", "certified"]
    payload["config"]["environment"]["leverage"] = 3.0
    _rehash_effective_snapshot(payload, normalize_integral_floats=True)

    snapshot = CanonicalEffectiveConfigSnapshot(**payload)

    assert snapshot.model_dump(mode="json")["config"]["environment"] == {
        "id": "test",
        "tags": ["paper", "certified"],
        "leverage": 3.0,
    }


def test_canonical_trading_identity_is_immutable_and_rejects_mismatch():
    identity = CanonicalTradingIdentity(**_canonical_identity_payload())
    with pytest.raises(ValidationError):
        identity.side = "SHORT"  # type: ignore[misc]

    with pytest.raises(ValidationError, match="mode_version_mismatch"):
        CanonicalTradingIdentity(
            **identity.model_dump(exclude_none=True), requested_mode_version="2.0.0"
        )


def test_canonical_trading_identity_rejects_mode_and_snapshot_identity_mismatches():
    with pytest.raises(ValidationError, match="mode_id_mismatch"):
        CanonicalTradingIdentity(
            **_canonical_identity_payload(), requested_mode_id="day_trading"
        )

    payload = _canonical_identity_payload()
    payload["config_hash"] = "sha256:" + "c" * 64
    with pytest.raises(ValidationError, match="effective_config_snapshot_identity_mismatch"):
        CanonicalTradingIdentity(**payload)


def test_effective_config_reference_is_trimmed_and_blank_is_rejected():
    identity = CanonicalTradingIdentity(
        **{**_canonical_identity_payload(), "effective_config_reference": "  effective-config:cfg-1  "}
    )
    assert identity.effective_config_reference == "effective-config:cfg-1"

    with pytest.raises(ValidationError):
        CanonicalTradingIdentity(
            **{**_canonical_identity_payload(), "effective_config_reference": "   "}
        )


def test_bitmart_remains_legacy_only_when_canonical_identity_is_present():
    legacy = OrchestratorSet(set_id="legacy-bitmart", exchange="bitmart", dry_run=True)
    assert legacy.trading_identity is None

    with pytest.raises(ValidationError, match="canonical_exchange_invalid"):
        OrchestratorSet(
            set_id="modern-bitmart",
            exchange="bitmart",
            dry_run=True,
            symbols=("BTCUSDT",),
            trading_identity=CanonicalTradingIdentity(**_canonical_identity_payload()),
        )


@pytest.mark.parametrize("version", ["latest", "^1.0", "1", "1.0", "01.0.0", "1.0.0-rc1"])
def test_canonical_trading_identity_rejects_non_exact_published_versions(version):
    payload = _canonical_identity_payload()
    payload["mode_version"] = version
    with pytest.raises(ValidationError):
        CanonicalTradingIdentity(**payload)

    payload = _canonical_identity_payload()
    payload["setup_version"] = version
    with pytest.raises(ValidationError):
        CanonicalTradingIdentity(**payload)


@pytest.mark.parametrize("field", ["config_hash", "condition_catalog_hash"])
@pytest.mark.parametrize("value", ["a" * 64, "sha256:" + "A" * 64, "sha256:" + "a" * 63, "md5:" + "a" * 64])
def test_canonical_trading_identity_rejects_malformed_hashes(field, value):
    payload = _canonical_identity_payload()
    payload[field] = value
    with pytest.raises(ValidationError):
        CanonicalTradingIdentity(**payload)


@pytest.mark.parametrize("field", ["config_hash", "condition_catalog_hash"])
def test_canonical_trading_identity_rejects_bytes_hashes_before_coercion(field):
    payload = _canonical_identity_payload()
    payload[field] = payload[field].encode("ascii")

    with pytest.raises(ValidationError, match="canonical_trading_identity_hash_type_invalid"):
        CanonicalTradingIdentity(**payload)


@pytest.mark.parametrize(
    "override",
    [
        {"mode_id": "scalper"},
        {"setup_id": "scalping.unknown.long"},
        {"mode_id": "day_trading"},
        {"side": "SHORT"},
        {"setup_version": "1.0.1"},
    ],
)
def test_canonical_trading_identity_rejects_unknown_or_catalog_mismatched_identity(override):
    payload = {**_canonical_identity_payload(), **override}
    with pytest.raises(ValidationError):
        CanonicalTradingIdentity(**payload)


def _canonical_identity_payload():
    catalog_hash = "sha256:" + "b" * 64
    config = {
        "schema_version": "effective-trading-config.v2",
        "units": {"percent": "percentage_points", "duration": "iso8601", "price": "quote_price", "notional": "quote_notional"},
        "safety": {"mainnet_write_enabled": False, "demo_testnet_write_enabled": False, "require_stop_loss": True, "kill_switch_enabled": True},
        "mode": {"mode_id": "scalping", "mode_version": "1.1.0"},
        "setup": {"setup_id": "scalping.pullback.long", "setup_version": "1.1.0", "side": "long"},
        "exchange": {"id": "fake"}, "environment": {"id": "test"},
    }
    config_hash = calculate_config_hash(config, catalog_hash)
    layers = [{"type": kind, "name": kind, "path": f"/{kind}.yaml", "required": True} for kind in ("base", "mode", "setup", "exchange", "mode_exchange", "environment")]
    snapshot = {
        "request": {"mode_id": "scalping", "mode_version": "1.1.0", "setup_id": "scalping.pullback.long", "setup_version": "1.1.0", "exchange": "fake", "environment": "test", "side": "long", "execution_capability": "backtest"},
        "config": config, "config_hash": config_hash, "condition_catalog_hash": catalog_hash,
        "ordered_layers": layers, "ordered_files": [layer["path"] for layer in layers],
        "provenance": {"mode.mode_id": layers[1]},
        "executable": True, "blockers": [],
    }
    snapshot["snapshot_hash"] = calculate_snapshot_hash(snapshot)
    return {
        "mode_id": "scalping",
        "mode_version": "1.1.0",
        "setup_id": "scalping.pullback.long",
        "setup_version": "1.1.0",
        "config_hash": config_hash,
        "condition_catalog_hash": catalog_hash,
        "side": "LONG",
        "effective_config_reference": "effective-config:cfg-1",
        "effective_config_snapshot": snapshot,
    }


def _rehash_effective_snapshot(payload, *, normalize_integral_floats=False):
    del normalize_integral_floats  # canonical hashing always normalizes integral floats
    payload["config_hash"] = calculate_config_hash(
        payload["config"], payload["condition_catalog_hash"]
    )
    payload["snapshot_hash"] = calculate_snapshot_hash(payload)


def test_canonical_set_rejects_snapshot_exchange_or_environment_mismatch():
    identity = _canonical_identity_payload()
    with pytest.raises(ValidationError, match="canonical_exchange_mismatch"):
        OrchestratorSet(
            set_id="exchange-mismatch", exchange="okx", environment="demo", dry_run=True,
            symbols=("BTCUSDT",), trading_identity=CanonicalTradingIdentity(**identity),
        )

    identity["effective_config_snapshot"]["request"]["environment"] = "test"
    identity["effective_config_snapshot"]["config"]["environment"]["id"] = "test"
    _rehash_effective_snapshot(identity["effective_config_snapshot"])
    identity["config_hash"] = identity["effective_config_snapshot"]["config_hash"]
    with pytest.raises(ValidationError, match="canonical_environment_mismatch"):
        OrchestratorSet(
            set_id="environment-mismatch", exchange="fake", environment="demo", dry_run=True,
            symbols=("BTCUSDT",), trading_identity=CanonicalTradingIdentity(**identity),
        )


@pytest.mark.parametrize(
    ("exchange", "environment"),
    [
        ("fake", "local"),
        ("fake", "test"),
        ("okx", "demo"),
        ("okx", "mainnet"),
        ("hyperliquid", "testnet"),
        ("hyperliquid", "mainnet"),
    ],
)
def test_canonical_effective_config_request_accepts_php_exchange_environment_pairs(
    exchange, environment
):
    request = CanonicalEffectiveConfigRequest(
        mode_id="scalping",
        mode_version="1.1.0",
        setup_id="scalping.pullback.long",
        setup_version="1.1.0",
        exchange=exchange,
        environment=environment,
        side="long",
        execution_capability="backtest" if exchange == "fake" else "paper",
    )

    assert request.exchange == exchange
    assert request.environment == environment


def test_canonical_effective_config_request_rejects_invalid_exchange_environment_pair():
    with pytest.raises(ValidationError, match="canonical_exchange_environment_invalid"):
        CanonicalEffectiveConfigRequest(
            mode_id="scalping",
            mode_version="1.1.0",
            setup_id="scalping.pullback.long",
            setup_version="1.1.0",
            exchange="fake",
            environment="demo",
            side="long",
            execution_capability="backtest",
        )


def test_environment_enum_exposes_canonical_fake_environments():
    assert Environment.LOCAL.value == "local"
    assert Environment.TEST.value == "test"


def test_set_create_accepts_legacy_row_without_canonical_identity():
    created = SetCreate.model_validate(
        {
            "set_id": "legacy",
            "exchange": "fake",
            "environment": "test",
            "symbols": ["BTCUSDT"],
        }
    )

    assert created.trading_identity is None


def test_set_create_and_update_accept_typed_canonical_identity():
    identity = _canonical_identity_payload()
    created = SetCreate.model_validate(
        {
            "set_id": "canonical",
            "exchange": "fake",
            "environment": "test",
            "mtf_profile": "scalping",
            "symbols": ["BTCUSDT"],
            "trading_identity": identity,
        }
    )
    updated = SetUpdate.model_validate({"trading_identity": identity})

    assert created.trading_identity == updated.trading_identity
    assert created.trading_identity.mode_id == "scalping"


def test_set_update_rejects_null_canonical_identity():
    with pytest.raises(ValidationError, match="trading_identity.*ne peut pas être null"):
        SetUpdate.model_validate({"trading_identity": None})


def test_okx_live_is_forbidden():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="okx", dry_run=False)


def test_hyperliquid_live_is_forbidden():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="hyperliquid", dry_run=False)


def test_okx_dry_run_is_allowed():
    s = OrchestratorSet(set_id="x", exchange="okx", dry_run=True)
    assert s.dry_run is True


def test_bitmart_live_is_allowed():
    s = OrchestratorSet(set_id="x", exchange="bitmart", dry_run=False)
    assert s.dry_run is False


# --- assert_set_persistable ⇆ assess_live (SAFE-003) ------------------------
#
# La persistance d'un set live n'est autorisée que si le runner l'exécuterait
# (mêmes gardes via `live_guard.assess_live`). On vérifie ici la cohérence
# persistance ↔ runtime en pilotant l'interrupteur d'activation par l'env.


def _persist(exchange="bitmart", dry_run=False):
    assert_set_persistable(
        dry_run=dry_run,
        symbols=["BTCUSDT"],
        contracts_limit=None,
        exchange=exchange,
        market_type="perpetual",
        environment="mainnet",
    )


def test_persist_live_refused_by_default(monkeypatch):
    # Interrupteur OFF (config livrée) : aucun set live persistable, comme avant SAFE-003.
    monkeypatch.delenv("ORCHESTRATION_LIVE_ENABLED", raising=False)
    monkeypatch.delenv("ORCHESTRATION_LIVE_EXCHANGES", raising=False)
    with pytest.raises(ValueError):
        _persist(exchange="bitmart", dry_run=False)


def test_persist_dry_run_allowed_by_default(monkeypatch):
    monkeypatch.delenv("ORCHESTRATION_LIVE_ENABLED", raising=False)
    monkeypatch.delenv("ORCHESTRATION_LIVE_EXCHANGES", raising=False)
    _persist(exchange="bitmart", dry_run=True)  # ne lève pas


def test_persist_live_allowed_when_switch_on_and_allowlisted(monkeypatch):
    # Interrupteur ON + bitmart allow-listé ⇒ assess_live autorise ⇒ persistance OK.
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", "true")
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", "bitmart")
    _persist(exchange="bitmart", dry_run=False)  # ne lève pas


def test_persist_live_okx_refused_even_when_switch_on(monkeypatch):
    # Bannissement permanent : OKX live refusé même interrupteur ON + allow-listé.
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", "true")
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", "okx,bitmart")
    with pytest.raises(ValueError):
        _persist(exchange="okx", dry_run=False)


def test_persist_live_refused_when_exchange_not_allowlisted(monkeypatch):
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", "true")
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", "fake")
    with pytest.raises(ValueError):
        _persist(exchange="bitmart", dry_run=False)


def test_unknown_exchange_is_rejected():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="binance")


def test_unknown_profile_is_rejected():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="fake", mtf_profile="hyper_scalp")


def test_recipe_functional_error_profile_is_allowed_only_for_fake_demo_dry_run():
    safe = SetCreate(
        set_id="recipe_r5",
        exchange="fake",
        environment="demo",
        dry_run=True,
        mtf_profile="recipe_functional_error",
        symbols=["BTCUSDT"],
    )
    assert safe.mtf_profile is MtfProfile.RECIPE_FUNCTIONAL_ERROR

    unsafe_overrides = (
        {"exchange": "bitmart"},
        {"environment": "mainnet"},
        {"dry_run": False},
    )
    for override in unsafe_overrides:
        payload = {
            "set_id": "recipe_r5",
            "exchange": "fake",
            "environment": "demo",
            "dry_run": True,
            "mtf_profile": "recipe_functional_error",
            "symbols": ["BTCUSDT"],
            **override,
        }
        with pytest.raises(ValidationError):
            SetCreate(**payload)


def test_workers_upper_bound_is_enforced():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="fake", workers=2)


def test_workers_zero_is_rejected():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="fake", workers=0)


def test_symbols_are_immutable():
    a_set = OrchestratorSet(set_id="x", exchange="fake", symbols=["BTCUSDT"])
    # Tuple immuable : pas de .append possible, et l'affectation est bloquée (frozen).
    assert isinstance(a_set.symbols, tuple)
    with pytest.raises(AttributeError):
        a_set.symbols.append("ETHUSDT")  # type: ignore[attr-defined]


# --- SetRead.effective_payload (PY-007) -------------------------------------


def _set_read(**kwargs) -> SetRead:
    base = dict(
        id=1,
        dashboard_id=1,
        set_id="s1",
        enabled=True,
        action=Action.MTF_RUN,
        exchange=Exchange.BITMART,
        market_type=MarketType.PERPETUAL,
        mtf_profile=MtfProfile.SCALPER_MICRO,
        environment=Environment.DEMO,
        dry_run=True,
        workers=1,
        sync_tables=False,
        symbols=["BTCUSDT", "ETHUSDT"],
        contracts_limit=None,
        priority=0,
        payload=None,
        created_at=datetime(2026, 1, 1),
        updated_at=datetime(2026, 1, 1),
    )
    base.update(kwargs)
    return SetRead(**base)


def test_set_read_exposes_effective_payload_when_materialized():
    # Le champ calculé reflète le payload /api/mtf/run effectif (enums déballés en
    # chaînes, sync_tables/process_tp_sl forcés false), sérialisé dans la réponse.
    dumped = _set_read().model_dump()
    assert dumped["effective_payload"] == {
        "dry_run": True,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "scalper_micro",
        "sync_tables": False,
        "process_tp_sl": False,
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "config_hash": "sha256:feb32cc0bf6491ed5f7a551ae53ec5b8db234fdaa692f108583792d91c9aea3f",
    }


def test_set_read_effective_payload_null_when_not_materialized():
    # Sélection capée pas encore résolue (symbols vide) ou symbols blancs => null :
    # le front en déduit « set non matérialisé ».
    assert _set_read(symbols=[], contracts_limit=5).effective_payload is None
    assert _set_read(symbols=[" ", "\t"]).effective_payload is None
