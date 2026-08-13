from __future__ import annotations

from copy import deepcopy

import pytest
from pydantic import ValidationError

from app.modern_trading_contracts import (
    PUBLISHED_MODE_VERSIONS,
    PUBLISHED_SETUP_VERSIONS,
    CanonicalEffectiveConfigLayer,
    CanonicalEffectiveConfigRequest,
    CanonicalEffectiveConfigSnapshot,
    ModernTradingIdentity,
    calculate_config_hash,
    calculate_snapshot_hash,
)


PUBLISHED_RUN_IDENTITIES = (
    ("day_trading", "1.0.0", "day_trading.trend_continuation.long", "1.0.0", "long"),
    ("day_trading", "1.1.0", "day_trading.trend_continuation.long", "1.1.0", "long"),
    ("day_trading", "1.0.0", "day_trading.trend_continuation.short", "1.0.0", "short"),
    ("scalping", "1.0.0", "scalping.trend_continuation.long", "1.0.0", "long"),
    ("scalping", "1.1.0", "scalping.trend_continuation.long", "1.1.0", "long"),
    ("scalping", "1.0.0", "scalping.pullback.long", "1.0.0", "long"),
    ("scalping", "1.1.0", "scalping.pullback.long", "1.1.0", "long"),
    ("scalping", "1.0.0", "scalping.trend_momentum.short", "1.0.0", "short"),
    ("scalping", "1.1.0", "scalping.trend_momentum.short", "1.1.0", "short"),
    ("micro_scalping", "1.0.0", "micro_scalping.momentum_ofi.long", "1.0.0", "long"),
    ("micro_scalping", "1.0.0", "micro_scalping.momentum_ofi.short", "1.0.0", "short"),
)


@pytest.mark.parametrize(
    ("mode_id", "mode_version", "setup_id", "setup_version", "side"),
    PUBLISHED_RUN_IDENTITIES,
)
def test_modern_identity_accepts_every_exact_published_run_cell(
    mode_id: str,
    mode_version: str,
    setup_id: str,
    setup_version: str,
    side: str,
) -> None:
    identity = ModernTradingIdentity(
        mode_id=mode_id,
        mode_version=mode_version,
        setup_id=setup_id,
        setup_version=setup_version,
        exchange="fake",
        environment="test",
        side=side,
    )

    assert identity.model_dump() == {
        "mode_id": mode_id,
        "mode_version": mode_version,
        "setup_id": setup_id,
        "setup_version": setup_version,
        "exchange": "fake",
        "environment": "test",
        "side": side,
    }


def test_catalogue_versions_match_the_exact_contract_files() -> None:
    assert PUBLISHED_MODE_VERSIONS == {
        "day_trading": ("1.0.0", "1.1.0"),
        "scalping": ("1.0.0", "1.1.0"),
        "micro_scalping": ("1.0.0",),
    }
    assert PUBLISHED_SETUP_VERSIONS == {
        "crash_short": ("1.0.0", "1.1.0"),
        "day_trading.trend_continuation.long": ("1.0.0", "1.1.0"),
        "day_trading.trend_continuation.short": ("1.0.0",),
        "scalping.trend_continuation.long": ("1.0.0", "1.1.0"),
        "scalping.pullback.long": ("1.0.0", "1.1.0"),
        "scalping.trend_momentum.short": ("1.0.0", "1.1.0"),
        "micro_scalping.momentum_ofi.long": ("1.0.0",),
        "micro_scalping.momentum_ofi.short": ("1.0.0",),
    }


@pytest.mark.parametrize(
    "override",
    (
        {"mode_id": "regular"},
        {"mode_id": "scalper"},
        {"mode_id": "scalper_micro"},
        {"mode_id": "Scalping"},
        {"mode_id": " scalping"},
        {"mode_version": "latest"},
        {"mode_version": "1.2.0"},
        {"setup_id": "SCALPING.PULLBACK.LONG"},
        {"setup_id": "scalping.pullback.long "},
        {"setup_version": "latest"},
        {"setup_version": "1.0.1"},
        {"side": "LONG"},
        {"side": "short"},
        {"mode_id": "day_trading"},
        {"mode_version": "1.0.0"},
    ),
)
def test_modern_identity_rejects_aliases_versions_and_catalogue_mismatches(
    override: dict[str, str],
) -> None:
    payload = _identity_payload()
    payload.update(override)

    with pytest.raises(ValidationError):
        ModernTradingIdentity(**payload)


@pytest.mark.parametrize("setup_version", ("1.0.0", "1.1.0"))
def test_crash_short_remains_catalogue_only_without_a_compatible_mode(
    setup_version: str,
) -> None:
    with pytest.raises(ValidationError, match="modern_trading_identity_incompatible"):
        ModernTradingIdentity(
            mode_id="day_trading",
            mode_version="1.1.0",
            setup_id="crash_short",
            setup_version=setup_version,
            exchange="fake",
            environment="test",
            side="short",
        )


@pytest.mark.parametrize(
    ("exchange", "environment"),
    (
        ("fake", "local"),
        ("fake", "test"),
        ("okx", "demo"),
        ("okx", "mainnet"),
        ("hyperliquid", "testnet"),
        ("hyperliquid", "mainnet"),
    ),
)
def test_modern_identity_accepts_exact_exchange_environment_pairs(
    exchange: str,
    environment: str,
) -> None:
    assert ModernTradingIdentity(
        **{**_identity_payload(), "exchange": exchange, "environment": environment}
    ).environment == environment


@pytest.mark.parametrize(
    ("exchange", "environment"),
    (("fake", "demo"), ("okx", "test"), ("hyperliquid", "demo")),
)
def test_modern_identity_rejects_exchange_environment_mismatches(
    exchange: str,
    environment: str,
) -> None:
    with pytest.raises(ValidationError, match="canonical_exchange_environment_invalid"):
        ModernTradingIdentity(
            **{**_identity_payload(), "exchange": exchange, "environment": environment}
        )


def test_modern_identity_forbids_extra_fields_and_is_immutable() -> None:
    with pytest.raises(ValidationError):
        ModernTradingIdentity(**_identity_payload(), profile="scalper")

    identity = ModernTradingIdentity(**_identity_payload())
    with pytest.raises(ValidationError):
        identity.side = "short"  # type: ignore[misc]


def test_effective_config_request_reuses_the_exact_modern_identity_contract() -> None:
    request = CanonicalEffectiveConfigRequest(
        **_identity_payload(), execution_capability="backtest"
    )

    assert request.model_dump() == {
        **_identity_payload(),
        "execution_capability": "backtest",
    }


@pytest.mark.parametrize(
    "identity",
    (
        {
            "mode_id": "scalping",
            "mode_version": "1.1.0",
            "setup_id": "scalping.pullback.long",
            "setup_version": "1.1.0",
            "exchange": "fake",
            "environment": "test",
            "side": "long",
        },
        {
            "mode_id": "day_trading",
            "mode_version": "1.1.0",
            "setup_id": "day_trading.trend_continuation.long",
            "setup_version": "1.1.0",
            "exchange": "fake",
            "environment": "test",
            "side": "long",
        },
    ),
)
def test_shadow_110_request_requires_an_explicit_execution_capability(
    identity: dict[str, str],
) -> None:
    with pytest.raises(ValidationError, match="shadow_capability_required"):
        CanonicalEffectiveConfigRequest(**identity)


@pytest.mark.parametrize(
    ("override", "reason"),
    (
        ({"execution_capability": "private_mainnet"}, "private_mainnet_execution_forbidden"),
        (
            {"exchange": "okx", "environment": "demo", "execution_capability": "backtest"},
            "backtest_requires_fake_exchange",
        ),
    ),
)
def test_shadow_request_rejects_private_mainnet_and_non_fake_backtest(
    override: dict[str, str], reason: str
) -> None:
    with pytest.raises(ValidationError, match=reason):
        CanonicalEffectiveConfigRequest(**{**_identity_payload(), **override})


def test_php_backtest_snapshot_accepts_exact_execution_capability_and_hash() -> None:
    payload = _snapshot_payload()
    payload["request"]["execution_capability"] = "backtest"
    # Generated independently with PHP 8.4 and the public
    # CanonicalEffectiveConfigSnapshot::calculateSnapshotHash() API.
    payload["snapshot_hash"] = (
        "sha256:3348aba268943bdbe77cb7a529894e9ca0c4d6e614a6955bddeaefe0aced5a7b"
    )

    snapshot = CanonicalEffectiveConfigSnapshot(**payload)

    assert snapshot.request.execution_capability == "backtest"
    assert snapshot.snapshot_hash == payload["snapshot_hash"]


def test_canonical_values_reject_ambiguous_or_non_finite_input() -> None:
    with pytest.raises(ValueError, match="canonical_json_mapping_keys_must_be_strings"):
        calculate_config_hash({0: "ambiguous"}, "sha256:" + "b" * 64)  # type: ignore[dict-item]
    with pytest.raises(ValueError, match="canonical_json_non_finite_float"):
        calculate_config_hash({"leverage": float("nan")}, "sha256:" + "b" * 64)
    with pytest.raises(ValidationError):
        CanonicalEffectiveConfigLayer(
            type="base", name="base", path="/base.yaml", required=1
        )

    payload = _snapshot_payload()
    payload["blockers"] = {"unordered"}
    with pytest.raises(ValidationError, match="effective_config_snapshot_sequence_invalid"):
        CanonicalEffectiveConfigSnapshot(**payload)


def test_config_hash_matches_php_scientific_float_encoding() -> None:
    config = {
        "tiny": 1e-7,
        "huge": 1e20,
        "negative": -1e-7,
        "mantissa": -1.234567890123456e-7,
    }

    # PHP 8.4 / serialize_precision=-1 public calculateConfigHash() fixture.
    assert calculate_config_hash(config, "sha256:" + "b" * 64) == (
        "sha256:63c116450f4c52b518addcb7338ba265b61dae6bf1839dfd6aae41e6ce2df841"
    )


def test_snapshot_validates_hashes_layers_provenance_and_deep_immutability() -> None:
    payload = _snapshot_payload()
    snapshot = CanonicalEffectiveConfigSnapshot(**payload)

    assert snapshot.snapshot_hash == calculate_snapshot_hash(payload)
    assert snapshot.config_hash == calculate_config_hash(
        payload["config"], payload["condition_catalog_hash"]
    )
    with pytest.raises(TypeError):
        snapshot.config["mode"]["mode_id"] = "day_trading"  # type: ignore[index]
    with pytest.raises(TypeError):
        snapshot.provenance["mode.mode_id"]["path"] = "/other.yaml"  # type: ignore[index]


@pytest.mark.parametrize(
    ("mutation", "reason"),
    (
        ("layer_order", "effective_config_snapshot_layer_order_invalid"),
        ("ordered_files", "effective_config_snapshot_layer_files_mismatch"),
        ("foreign_provenance", "effective_config_snapshot_provenance_invalid"),
        ("config_identity", "effective_config_snapshot_config_identity_mismatch"),
        ("config_hash", "effective_config_snapshot_hash_mismatch"),
        ("snapshot_hash", "effective_config_snapshot_hash_mismatch"),
    ),
)
def test_snapshot_rejects_tampered_metadata_and_hashes(
    mutation: str,
    reason: str,
) -> None:
    payload = _snapshot_payload()
    if mutation == "layer_order":
        payload["ordered_layers"][0], payload["ordered_layers"][1] = (
            payload["ordered_layers"][1],
            payload["ordered_layers"][0],
        )
        payload["ordered_files"] = [item["path"] for item in payload["ordered_layers"]]
        payload["snapshot_hash"] = calculate_snapshot_hash(payload)
    elif mutation == "ordered_files":
        payload["ordered_files"] = list(reversed(payload["ordered_files"]))
        payload["snapshot_hash"] = calculate_snapshot_hash(payload)
    elif mutation == "foreign_provenance":
        payload["provenance"]["mode.mode_id"] = {
            "type": "mode",
            "name": "mode",
            "path": "/foreign.yaml",
            "required": True,
        }
        payload["snapshot_hash"] = calculate_snapshot_hash(payload)
    elif mutation == "config_identity":
        payload["config"]["setup"]["side"] = "short"
        payload["config_hash"] = calculate_config_hash(
            payload["config"], payload["condition_catalog_hash"]
        )
        payload["snapshot_hash"] = calculate_snapshot_hash(payload)
    elif mutation == "config_hash":
        payload["config_hash"] = "sha256:" + "c" * 64
        payload["snapshot_hash"] = calculate_snapshot_hash(payload)
    else:
        payload["snapshot_hash"] = "sha256:" + "d" * 64

    with pytest.raises(ValidationError, match=reason):
        CanonicalEffectiveConfigSnapshot(**payload)


def test_general_snapshot_retains_non_executable_evidence() -> None:
    payload = _snapshot_payload()
    payload["executable"] = False
    payload["blockers"] = ["shadow_evidence_pending"]
    payload["snapshot_hash"] = calculate_snapshot_hash(payload)

    snapshot = CanonicalEffectiveConfigSnapshot(**payload)

    assert not snapshot.executable
    assert snapshot.blockers == ("shadow_evidence_pending",)


def test_snapshot_and_layer_forbid_extra_fields() -> None:
    with pytest.raises(ValidationError):
        CanonicalEffectiveConfigLayer(
            type="base", name="base", path="/base.yaml", required=True, alias="legacy"
        )
    with pytest.raises(ValidationError):
        CanonicalEffectiveConfigSnapshot(**_snapshot_payload(), profile="scalper")


def _identity_payload() -> dict[str, str]:
    return {
        "mode_id": "scalping",
        "mode_version": "1.1.0",
        "setup_id": "scalping.pullback.long",
        "setup_version": "1.1.0",
        "exchange": "fake",
        "environment": "test",
        "side": "long",
    }


def _snapshot_payload() -> dict:
    request = {**_identity_payload(), "execution_capability": "backtest"}
    config = {
        "schema_version": "effective-trading-config.v2",
        "units": {
            "percent": "percentage_points",
            "duration": "iso8601",
            "price": "quote_price",
            "notional": "quote_notional",
        },
        "safety": {
            "mainnet_write_enabled": False,
            "demo_testnet_write_enabled": False,
            "require_stop_loss": True,
            "kill_switch_enabled": True,
        },
        "mode": {"mode_id": request["mode_id"], "mode_version": request["mode_version"]},
        "setup": {
            "setup_id": request["setup_id"],
            "setup_version": request["setup_version"],
            "side": request["side"],
        },
        "exchange": {"id": request["exchange"]},
        "environment": {"id": request["environment"], "tags": ["paper", "certified"]},
    }
    layers = [
        {"type": kind, "name": kind, "path": f"/{kind}.yaml", "required": True}
        for kind in ("base", "mode", "setup", "exchange", "mode_exchange", "environment")
    ]
    payload = {
        "request": request,
        "config": config,
        "config_hash": calculate_config_hash(config, "sha256:" + "b" * 64),
        "condition_catalog_hash": "sha256:" + "b" * 64,
        "ordered_layers": layers,
        "ordered_files": [item["path"] for item in layers],
        "provenance": {"mode.mode_id": deepcopy(layers[1])},
        "executable": True,
        "blockers": [],
    }
    payload["snapshot_hash"] = calculate_snapshot_hash(payload)
    return payload
