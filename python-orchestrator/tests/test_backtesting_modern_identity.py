from __future__ import annotations

import json
import pickle
from copy import deepcopy
from pathlib import Path

import pytest
from pydantic import ValidationError

from app import modern_trading_contracts
from app.modern_trading_contracts import (
    PUBLISHED_MODE_VERSIONS,
    PUBLISHED_SETUP_VERSIONS,
    CanonicalEffectiveConfigLayer,
    CanonicalEffectiveConfigRequest,
    CanonicalEffectiveConfigSnapshot,
    FrozenJsonDict,
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

FIXTURES = Path(__file__).parent / "fixtures" / "backtesting"
PHP_INT_MIN = -(1 << 63)
PHP_INT_MAX = (1 << 63) - 1
PHP_INT_MAX_FLOAT_BELOW_LIMIT = float.fromhex("0x1.fffffffffffffp+62")


def test_php_effective_config_snapshot_golden_parity_and_tamper_detection() -> None:
    payload = json.loads(
        (FIXTURES / "php-effective-config-snapshot.json").read_text(encoding="utf-8")
    )

    assert calculate_config_hash(
        payload["config"], payload["condition_catalog_hash"]
    ) == payload["config_hash"]
    assert calculate_snapshot_hash(payload) == payload["snapshot_hash"]
    snapshot = CanonicalEffectiveConfigSnapshot(**payload)
    assert snapshot.request.execution_capability == "backtest"
    evidence = snapshot.config["environment"]["evidence"]
    assert evidence["unicode"] == "café/path"
    assert evidence["integral_float"] == 3.0
    assert evidence["scientific"] == 1e-7
    assert evidence["line_separators"] == "x\u2028y\u2029z"
    assert snapshot.model_dump()["provenance"] == payload["provenance"]
    layer_payloads = [layer.model_dump() for layer in snapshot.ordered_layers]
    assert all(layer in layer_payloads for layer in snapshot.provenance.values())

    one_byte_forgery = deepcopy(payload)
    one_byte_forgery["config"]["environment"]["evidence"]["unicode"] = "café/pati"
    assert payload["config"]["environment"]["evidence"]["unicode"] == "café/path"
    canonical_hash_payload = {
        "config": payload["config"],
        "condition_catalog_hash": payload["condition_catalog_hash"],
    }
    forged_hash_payload = {
        **canonical_hash_payload,
        "config": one_byte_forgery["config"],
    }
    canonical_bytes = modern_trading_contracts._canonical_json(  # noqa: SLF001
        canonical_hash_payload
    ).encode("utf-8")
    forged_bytes = modern_trading_contracts._canonical_json(  # noqa: SLF001
        forged_hash_payload
    ).encode("utf-8")
    assert len(canonical_bytes) == len(forged_bytes)
    byte_differences = [
        (index, before, after)
        for index, (before, after) in enumerate(
            zip(canonical_bytes, forged_bytes, strict=True)
        )
        if before != after
    ]
    assert [(before, after) for _, before, after in byte_differences] == [
        (ord("h"), ord("i"))
    ]
    with pytest.raises(ValidationError, match="effective_config_snapshot_hash_mismatch"):
        CanonicalEffectiveConfigSnapshot(**one_byte_forgery)

    for path, value in (
        (("request", "execution_capability"), "paper"),
        (("ordered_files", 2), "/setup-X.yaml"),
    ):
        forged = deepcopy(payload)
        target = forged
        for part in path[:-1]:
            target = target[part]
        target[path[-1]] = value
        with pytest.raises(ValidationError):
            CanonicalEffectiveConfigSnapshot(**forged)


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
        "negative": -1e-7,
        "mantissa": -1.234567890123456e-7,
    }

    # PHP 8.4 / serialize_precision=-1 public calculateConfigHash() fixture.
    assert calculate_config_hash(config, "sha256:" + "b" * 64) == (
        "sha256:f6f30f236d91d6bceee845f06d868fd009bdaf30f4a85731f12a0044744353e8"
    )


def test_config_hash_matches_php_line_separator_escaping() -> None:
    config = {
        "line": "x\u2028y\u2029z",
        "ordinary": "café/path",
    }

    # PHP 8.4 public calculateConfigHash() fixture. JSON_UNESCAPED_UNICODE
    # preserves ordinary Unicode but PHP still escapes both line separators.
    assert calculate_config_hash(config, "sha256:" + "b" * 64) == (
        "sha256:973a2fe8c70c76473942ba036637284caf00e40f48a471fcfada91cd5a78beee"
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
    assert snapshot.config["environment"]["tags"] == ("paper", "certified")


def test_frozen_json_backing_cannot_be_mutated_or_rebound() -> None:
    snapshot = CanonicalEffectiveConfigSnapshot(**_snapshot_payload())
    original = snapshot.model_dump(mode="json")

    with pytest.raises(TypeError, match="immutable"):
        snapshot.config._data["mode"] = {}  # type: ignore[attr-defined,index]
    with pytest.raises(TypeError, match="immutable"):
        snapshot.config._data = {}  # type: ignore[attr-defined]
    with pytest.raises(TypeError, match="immutable"):
        snapshot.config["mode"]._data["mode_id"] = "day_trading"  # type: ignore[attr-defined,index]
    with pytest.raises(TypeError, match="immutable"):
        snapshot.provenance["mode.mode_id"]._data["path"] = "/forged.yaml"  # type: ignore[attr-defined,index]
    with pytest.raises(TypeError):
        snapshot.config._FrozenJsonDict__backing["mode"] = {}  # type: ignore[attr-defined,index]
    with pytest.raises(TypeError, match="immutable"):
        snapshot.config._FrozenJsonDict__backing = {}  # type: ignore[attr-defined]

    assert snapshot.model_dump(mode="json") == original
    assert calculate_snapshot_hash(snapshot) == snapshot.snapshot_hash


def test_frozen_json_deletion_and_invalid_nested_values_fail_closed() -> None:
    frozen = FrozenJsonDict({"nested": {"value": 1}})

    with pytest.raises(TypeError, match="immutable"):
        del frozen["nested"]
    with pytest.raises(TypeError, match="immutable"):
        del frozen._data

    for invalid in (
        {0: "non-string-key"},
        {"nested": {"unordered"}},
        {"nested": float("inf")},
        {"nested": object()},
    ):
        with pytest.raises(ValueError, match="canonical_json"):
            FrozenJsonDict(invalid)  # type: ignore[arg-type]


def test_frozen_snapshot_round_trips_through_pickle_without_losing_hashes() -> None:
    snapshot = CanonicalEffectiveConfigSnapshot(**_snapshot_payload())

    restored = pickle.loads(pickle.dumps(snapshot))

    assert restored.model_dump(mode="json") == snapshot.model_dump(mode="json")
    assert calculate_snapshot_hash(restored) == restored.snapshot_hash


@pytest.mark.parametrize(
    "field",
    ("config_hash", "condition_catalog_hash", "snapshot_hash"),
)
def test_snapshot_hash_fields_reject_bytes_before_string_coercion(field: str) -> None:
    payload = _snapshot_payload()
    payload[field] = payload[field].encode("ascii")

    with pytest.raises(ValidationError, match="effective_config_snapshot_hash_type_invalid"):
        CanonicalEffectiveConfigSnapshot(**payload)


def test_canonical_hashing_rejects_non_json_inputs_and_covers_scalar_forms() -> None:
    catalog_hash = "sha256:" + "b" * 64

    assert calculate_config_hash(
        {"disabled": None, "ratio": 0.5}, catalog_hash
    ).startswith("sha256:")
    with pytest.raises(ValueError, match="canonical_json_value_invalid"):
        calculate_config_hash({"unordered": {"value"}}, catalog_hash)
    with pytest.raises(ValueError, match="canonical_json_value_invalid"):
        calculate_config_hash({"opaque": object()}, catalog_hash)
    with pytest.raises(ValueError, match="effective_config_snapshot must be a mapping"):
        calculate_snapshot_hash(["not", "a", "mapping"])  # type: ignore[arg-type]


@pytest.mark.parametrize(
    ("value", "expected_hash"),
    (
        (
            PHP_INT_MIN,
            "sha256:9969c55be4b653411083212fedb03558720a6acea4d41ae8520f0e9428374095",
        ),
        (
            PHP_INT_MAX,
            "sha256:3496ec5cd57832d13ef6d0421419b6b5b562500a5ac92f89cbbc30aaac9a8e2c",
        ),
        (
            float(PHP_INT_MIN),
            "sha256:9969c55be4b653411083212fedb03558720a6acea4d41ae8520f0e9428374095",
        ),
        (
            PHP_INT_MAX_FLOAT_BELOW_LIMIT,
            "sha256:2f4d1f213c4a462e150332f80ac1e2a4f7e8b0e14459bcb151f0dee3c384a578",
        ),
    ),
)
def test_php_integer_domain_bounds_have_independent_php_hashes(
    value: int | float, expected_hash: str
) -> None:
    catalog_hash = "sha256:" + "b" * 64

    assert PHP_INT_MAX_FLOAT_BELOW_LIMIT == PHP_INT_MAX - 1023
    assert FrozenJsonDict({"value": value})["value"] == value
    assert calculate_config_hash({"value": value}, catalog_hash) == expected_hash


@pytest.mark.parametrize("value", (PHP_INT_MIN - 1, PHP_INT_MAX + 1))
def test_php_integer_domain_rejects_out_of_range_ints(value: int) -> None:
    with pytest.raises(ValueError, match="canonical_json_integer_out_of_php_range"):
        FrozenJsonDict({"value": value})
    with pytest.raises(ValueError, match="canonical_json_integer_out_of_php_range"):
        calculate_config_hash({"value": value}, "sha256:" + "b" * 64)


@pytest.mark.parametrize(
    "value",
    (
        float(1 << 63),
        float.fromhex("-0x1.0000000000001p+63"),
        1e20,
    ),
)
def test_php_integer_domain_rejects_ambiguous_out_of_range_integral_floats(
    value: float,
) -> None:
    assert value.is_integer()
    with pytest.raises(
        ValueError, match="canonical_json_ambiguous_integral_float_out_of_php_range"
    ):
        FrozenJsonDict({"value": value})
    with pytest.raises(
        ValueError, match="canonical_json_ambiguous_integral_float_out_of_php_range"
    ):
        calculate_config_hash({"value": value}, "sha256:" + "b" * 64)


@pytest.mark.parametrize(
    "ambiguous",
    (
        {"0": "x"},
        {"1": "x", "0": "y"},
    ),
)
def test_contiguous_integer_string_key_mappings_are_rejected_independent_of_order(
    ambiguous: dict[str, str],
) -> None:
    catalog_hash = "sha256:" + "b" * 64

    with pytest.raises(ValueError, match="canonical_json_ambiguous_integer_key_map"):
        FrozenJsonDict(ambiguous)
    with pytest.raises(ValueError, match="canonical_json_ambiguous_integer_key_map"):
        calculate_config_hash({"value": ambiguous}, catalog_hash)
    if tuple(ambiguous) == ("0",):
        # PHP 8.4 turns decoded {"0":"x"} into the list ["x"]. Rejecting the
        # Python mapping prevents it from receiving this different PHP hash.
        assert calculate_config_hash({"value": ["x"]}, catalog_hash) == (
            "sha256:2f9d3a8adad89944550c6288d00f00ba6973a7436eb934aa0edac01e0e4174db"
        )


@pytest.mark.parametrize(
    ("mapping", "expected_hash"),
    (
        (
            # PHP 8.4 json_encode on the explicit empty object `(object) []`.
            {},
            "sha256:47948c903e73b9514f6197841dbc5b5055e1d03901642e88c188ae0292115dfe",
        ),
        (
            {"1": "x"},
            "sha256:ac57774e0ca6e948702467cf2f5d49c532b70376a0ad80ec15c7d1e4563f1fd2",
        ),
        (
            {"name": "x"},
            "sha256:c6e88f6a1d2c0efa88e5e3c7ebfdff18fb3779f3eac9c75bb5d759b830d7a32b",
        ),
    ),
)
def test_empty_noncontiguous_and_nonnumeric_mappings_remain_objects(
    mapping: dict[str, str], expected_hash: str
) -> None:
    catalog_hash = "sha256:" + "b" * 64

    assert dict(FrozenJsonDict(mapping)) == mapping
    assert calculate_config_hash({"value": mapping}, catalog_hash) == expected_hash


@pytest.mark.parametrize(
    ("mutation", "reason"),
    (
        ("non_mapping", "model_type"),
        ("non_boolean_executable", "effective_config_snapshot_executable_invalid"),
        ("spaced_layer", "effective_config_snapshot_layer_value_invalid"),
        ("blank_provenance_key", "effective_config_snapshot_provenance_invalid"),
        ("spaced_ordered_file", "effective_config_snapshot_layer_files_mismatch"),
        ("blank_blocker", "effective_config_snapshot_blocker_invalid"),
        ("non_mapping_config_root", "effective_config_snapshot_roots_invalid"),
    ),
)
def test_snapshot_rejects_ambiguous_scalar_and_metadata_shapes(
    mutation: str, reason: str
) -> None:
    if mutation == "non_mapping":
        payload: object = []
    else:
        payload = _snapshot_payload()
        if mutation == "non_boolean_executable":
            payload["executable"] = 1
        elif mutation == "spaced_layer":
            payload["ordered_layers"][0]["path"] = " /base.yaml"
        elif mutation == "blank_provenance_key":
            payload["provenance"][""] = payload["provenance"].pop("mode.mode_id")
        elif mutation == "spaced_ordered_file":
            payload["ordered_files"][0] = " /base.yaml"
        elif mutation == "blank_blocker":
            payload["blockers"] = [""]
        else:
            payload["config"]["units"] = "percentage_points"
            payload["config_hash"] = calculate_config_hash(
                payload["config"], payload["condition_catalog_hash"]
            )
            payload["snapshot_hash"] = calculate_snapshot_hash(payload)

    with pytest.raises(ValidationError, match=reason):
        CanonicalEffectiveConfigSnapshot.model_validate(payload)


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
