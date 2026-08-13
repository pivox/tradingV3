"""Dependency-neutral modern trading identity and effective-config contracts.

This module is the Python owner of the exact #300/#301 identity catalogue and
the immutable #133 snapshot envelope.  It deliberately contains no legacy
profile aliases and imports neither orchestration nor backtesting modules.
"""

from __future__ import annotations

import hashlib
import json
import math
from collections.abc import Mapping
from types import MappingProxyType
from typing import Any, Iterator, Literal

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    field_serializer,
    field_validator,
    model_validator,
)


_SHA256_PATTERN = r"^sha256:[0-9a-f]{64}$"
_PHP_INT_MIN = -(1 << 63)
_PHP_INT_MAX = (1 << 63) - 1
_EXPECTED_LAYER_ORDER = (
    "base",
    "mode",
    "setup",
    "exchange",
    "mode_exchange",
    "environment",
)
_EXPECTED_CONFIG_ROOTS = {
    "schema_version",
    "units",
    "safety",
    "mode",
    "setup",
    "exchange",
    "environment",
}

PUBLISHED_MODE_VERSIONS = MappingProxyType(
    {
        "day_trading": ("1.0.0", "1.1.0"),
        "scalping": ("1.0.0", "1.1.0"),
        "micro_scalping": ("1.0.0",),
    }
)

PUBLISHED_SETUP_VERSIONS = MappingProxyType(
    {
        "crash_short": ("1.0.0", "1.1.0"),
        "day_trading.trend_continuation.long": ("1.0.0", "1.1.0"),
        "day_trading.trend_continuation.short": ("1.0.0",),
        "scalping.trend_continuation.long": ("1.0.0", "1.1.0"),
        "scalping.pullback.long": ("1.0.0", "1.1.0"),
        "scalping.trend_momentum.short": ("1.0.0", "1.1.0"),
        "micro_scalping.momentum_ofi.long": ("1.0.0",),
        "micro_scalping.momentum_ofi.short": ("1.0.0",),
    }
)

# crash_short is intentionally absent: both published versions remain
# catalogue-only until #310 assigns a compatible executable modern envelope.
_PUBLISHED_RUN_IDENTITIES = frozenset(
    {
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
    }
)

_EXCHANGE_ENVIRONMENTS = {
    "fake": frozenset({"local", "test"}),
    "okx": frozenset({"demo", "mainnet"}),
    "hyperliquid": frozenset({"testnet", "mainnet"}),
}


class FrozenJsonDict(Mapping[str, Any]):
    """A recursively immutable mapping containing only unambiguous JSON."""

    __slots__ = ("__backing",)

    def __init__(self, value: Mapping[str, Any]) -> None:
        if _has_contiguous_zero_based_integer_string_key_set(value):
            raise ValueError("canonical_json_ambiguous_integer_key_map")
        data: dict[str, Any] = {}
        for key, item in value.items():
            if not isinstance(key, str):
                raise ValueError("canonical_json_mapping_keys_must_be_strings")
            data[key] = _deep_freeze(item)
        object.__setattr__(self, "_FrozenJsonDict__backing", MappingProxyType(data))

    @property
    def _data(self) -> "FrozenJsonDict":
        """Compatibility view that cannot expose the immutable backing map."""

        return self

    def __setattr__(self, _name: str, _value: Any) -> None:
        raise TypeError("FrozenJsonDict is immutable")

    def __delattr__(self, _name: str) -> None:
        raise TypeError("FrozenJsonDict is immutable")

    def __setitem__(self, _key: str, _value: Any) -> None:
        raise TypeError("FrozenJsonDict is immutable")

    def __delitem__(self, _key: str) -> None:
        raise TypeError("FrozenJsonDict is immutable")

    def __getitem__(self, key: str) -> Any:
        return self.__backing[key]

    def __iter__(self) -> Iterator[str]:
        return iter(self.__backing)

    def __len__(self) -> int:
        return len(self.__backing)

    def __repr__(self) -> str:
        return repr(dict(self.__backing))

    def __copy__(self) -> "FrozenJsonDict":
        return self

    def __deepcopy__(self, _memo: dict[int, Any]) -> "FrozenJsonDict":
        return self

    def __reduce__(self) -> tuple[Any, tuple[dict[str, Any]]]:
        return type(self), (thaw_json(self),)

    @classmethod
    def __get_pydantic_json_schema__(cls, _core_schema: Any, _handler: Any) -> dict:
        return {"type": "object", "additionalProperties": True}


def _deep_freeze(value: Any) -> Any:
    if isinstance(value, FrozenJsonDict):
        return value
    if isinstance(value, Mapping):
        return FrozenJsonDict(value)
    if isinstance(value, (list, tuple)):
        return tuple(_deep_freeze(item) for item in value)
    if isinstance(value, (set, frozenset)):
        raise ValueError("canonical_json_value_invalid")
    if isinstance(value, float):
        if not math.isfinite(value):
            raise ValueError("canonical_json_non_finite_float")
        if value.is_integer() and not _is_php_integer(value):
            raise ValueError(
                "canonical_json_ambiguous_integral_float_out_of_php_range"
            )
        return value
    if isinstance(value, int) and not isinstance(value, bool):
        if not _is_php_integer(value):
            raise ValueError("canonical_json_integer_out_of_php_range")
        return value
    if isinstance(value, (str, bool, type(None))):
        return value
    raise ValueError("canonical_json_value_invalid")


def _is_php_integer(value: int | float) -> bool:
    return _PHP_INT_MIN <= value <= _PHP_INT_MAX


def _has_contiguous_zero_based_integer_string_key_set(
    value: Mapping[object, Any],
) -> bool:
    return bool(value) and set(value) == {str(index) for index in range(len(value))}


def thaw_json(value: Any) -> Any:
    """Return ordinary dict/list JSON data from recursively frozen values."""

    if isinstance(value, Mapping):
        return {key: thaw_json(item) for key, item in value.items()}
    if isinstance(value, (tuple, list)):
        return [thaw_json(item) for item in value]
    return value


def _canonical_json_value(value: Any) -> Any:
    if isinstance(value, BaseModel):
        return _canonical_json_value(value.model_dump(mode="json"))
    if isinstance(value, Mapping):
        if _has_contiguous_zero_based_integer_string_key_set(value):
            raise ValueError("canonical_json_ambiguous_integer_key_map")
        normalized: dict[str, Any] = {}
        for key, item in value.items():
            if not isinstance(key, str):
                raise ValueError("canonical_json_mapping_keys_must_be_strings")
            normalized[key] = _canonical_json_value(item)
        return normalized
    if isinstance(value, (tuple, list)):
        return [_canonical_json_value(item) for item in value]
    if isinstance(value, (set, frozenset)):
        raise ValueError("canonical_json_value_invalid")
    if isinstance(value, float):
        if not math.isfinite(value):
            raise ValueError("canonical_json_non_finite_float")
        if value.is_integer():
            if not _is_php_integer(value):
                raise ValueError(
                    "canonical_json_ambiguous_integral_float_out_of_php_range"
                )
            return int(value)
        return value
    if isinstance(value, int) and not isinstance(value, bool):
        if not _is_php_integer(value):
            raise ValueError("canonical_json_integer_out_of_php_range")
        return value
    if isinstance(value, (str, bool, type(None))):
        return value
    raise ValueError("canonical_json_value_invalid")


def _encode_php_float(value: float) -> str:
    """Match PHP json_encode with serialize_precision=-1 for finite floats."""

    encoded = repr(value)
    if "e" not in encoded:
        return encoded
    mantissa, exponent_text = encoded.split("e", 1)
    if "." not in mantissa:
        mantissa += ".0"
    exponent = int(exponent_text)
    sign = "+" if exponent >= 0 else "-"
    return f"{mantissa}e{sign}{abs(exponent)}"


def _encode_php_json_string(value: str) -> str:
    encoded = json.dumps(value, ensure_ascii=False, separators=(",", ":"))
    return encoded.replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")


def _encode_canonical_value(value: Any) -> str:
    normalized = _canonical_json_value(value)
    if isinstance(normalized, Mapping):
        return "{" + ",".join(
            _encode_php_json_string(key)
            + ":"
            + _encode_canonical_value(normalized[key])
            for key in sorted(normalized)
        ) + "}"
    if isinstance(normalized, list):
        return "[" + ",".join(_encode_canonical_value(item) for item in normalized) + "]"
    if normalized is None:
        return "null"
    if normalized is True:
        return "true"
    if normalized is False:
        return "false"
    if isinstance(normalized, int):
        return str(normalized)
    if isinstance(normalized, float):
        return _encode_php_float(normalized)
    if isinstance(normalized, str):
        return _encode_php_json_string(normalized)
    raise ValueError("canonical_json_value_invalid")


def _canonical_json(payload: Mapping[str, Any]) -> str:
    return _encode_canonical_value(payload)


def calculate_config_hash(config: Mapping[str, Any], condition_catalog_hash: str) -> str:
    canonical = _canonical_json(
        {"config": config, "condition_catalog_hash": condition_catalog_hash}
    )
    return "sha256:" + hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def calculate_snapshot_hash(snapshot: Mapping[str, Any] | BaseModel) -> str:
    if isinstance(snapshot, BaseModel):
        payload = snapshot.model_dump(mode="json")
    elif isinstance(snapshot, Mapping):
        payload = dict(snapshot)
    else:
        raise ValueError("effective_config_snapshot must be a mapping")
    payload.pop("snapshot_hash", None)
    return "sha256:" + hashlib.sha256(
        _canonical_json(payload).encode("utf-8")
    ).hexdigest()


ModeId = Literal["day_trading", "scalping", "micro_scalping"]
PublishedVersion = Literal["1.0.0", "1.1.0"]
SetupId = Literal[
    "crash_short",
    "day_trading.trend_continuation.long",
    "day_trading.trend_continuation.short",
    "scalping.trend_continuation.long",
    "scalping.pullback.long",
    "scalping.trend_momentum.short",
    "micro_scalping.momentum_ofi.long",
    "micro_scalping.momentum_ofi.short",
]


class ModernTradingIdentity(BaseModel):
    """One exact executable cell from the frozen modern catalogue."""

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    mode_id: ModeId
    mode_version: PublishedVersion
    setup_id: SetupId
    setup_version: PublishedVersion
    exchange: Literal["fake", "okx", "hyperliquid"]
    environment: Literal["local", "test", "demo", "testnet", "mainnet"]
    side: Literal["long", "short"]

    @model_validator(mode="after")
    def _validate_exact_compatibility(self) -> "ModernTradingIdentity":
        if self.environment not in _EXCHANGE_ENVIRONMENTS[self.exchange]:
            raise ValueError("canonical_exchange_environment_invalid")
        identity = (
            self.mode_id,
            self.mode_version,
            self.setup_id,
            self.setup_version,
            self.side,
        )
        if identity not in _PUBLISHED_RUN_IDENTITIES:
            raise ValueError("modern_trading_identity_incompatible")
        return self


class CanonicalEffectiveConfigRequest(ModernTradingIdentity):
    """Exact identity requested from the canonical six-layer resolver."""

    execution_capability: Literal["fake", "paper", "backtest", "private_mainnet"] | None = Field(
        default=None, exclude_if=lambda value: value is None
    )

    @model_validator(mode="after")
    def _validate_execution_capability(self) -> "CanonicalEffectiveConfigRequest":
        if self.execution_capability == "private_mainnet":
            raise ValueError("private_mainnet_execution_forbidden")
        if (
            (self.mode_id, self.mode_version)
            in {("day_trading", "1.1.0"), ("scalping", "1.1.0")}
            and self.execution_capability is None
        ):
            raise ValueError(f"{self.mode_id}_shadow_capability_required")
        if self.execution_capability == "backtest" and self.exchange != "fake":
            raise ValueError(f"{self.mode_id}_backtest_requires_fake_exchange")
        return self


class CanonicalEffectiveConfigLayer(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    type: Literal["base", "mode", "setup", "exchange", "mode_exchange", "environment"]
    name: str = Field(min_length=1)
    path: str = Field(min_length=1)
    required: Literal[True]

    @field_validator("required", mode="before")
    @classmethod
    def _require_strict_true(cls, value: Any) -> Any:
        if value is not True:
            raise ValueError("effective_config_snapshot_layer_required_invalid")
        return value

    @field_validator("name", "path")
    @classmethod
    def _reject_normalized_or_blank_value(cls, value: str) -> str:
        if value != value.strip():
            raise ValueError("effective_config_snapshot_layer_value_invalid")
        return value


class CanonicalEffectiveConfigSnapshot(BaseModel):
    """Immutable, hash-verified #133 effective configuration evidence."""

    model_config = ConfigDict(frozen=True, extra="forbid", arbitrary_types_allowed=True)

    request: CanonicalEffectiveConfigRequest
    config: FrozenJsonDict
    config_hash: str = Field(pattern=_SHA256_PATTERN)
    condition_catalog_hash: str = Field(pattern=_SHA256_PATTERN)
    ordered_layers: tuple[CanonicalEffectiveConfigLayer, ...]
    ordered_files: tuple[str, ...]
    provenance: FrozenJsonDict
    executable: bool
    blockers: tuple[str, ...] = ()
    snapshot_hash: str = Field(pattern=_SHA256_PATTERN)

    @model_validator(mode="before")
    @classmethod
    def _reject_ambiguous_sequences(cls, value: Any) -> Any:
        if not isinstance(value, Mapping):
            return value
        for field in ("ordered_layers", "ordered_files", "blockers"):
            item = value.get(field, ())
            if not isinstance(item, (list, tuple)):
                raise ValueError("effective_config_snapshot_sequence_invalid")
        executable = value.get("executable")
        if executable is not True and executable is not False:
            raise ValueError("effective_config_snapshot_executable_invalid")
        return value

    @field_validator(
        "config_hash", "condition_catalog_hash", "snapshot_hash", mode="before"
    )
    @classmethod
    def _reject_coerced_hashes(cls, value: Any) -> Any:
        if not isinstance(value, str):
            raise ValueError("effective_config_snapshot_hash_type_invalid")
        return value

    @field_validator("config", mode="before")
    @classmethod
    def _freeze_config(cls, value: Any) -> FrozenJsonDict:
        if not isinstance(value, Mapping):
            raise ValueError("effective_config_snapshot.config must be a mapping")
        return FrozenJsonDict(value)

    @field_serializer("config")
    def _serialize_config(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @field_validator("provenance", mode="before")
    @classmethod
    def _freeze_provenance(cls, value: Any) -> FrozenJsonDict:
        if not isinstance(value, Mapping) or not value:
            raise ValueError("effective_config_snapshot_provenance_empty")
        normalized: dict[str, dict[str, Any]] = {}
        for key, layer in value.items():
            if not isinstance(key, str) or not key or key != key.strip():
                raise ValueError("effective_config_snapshot_provenance_invalid")
            normalized[key] = CanonicalEffectiveConfigLayer.model_validate(layer).model_dump()
        return FrozenJsonDict(normalized)

    @field_serializer("provenance")
    def _serialize_provenance(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @field_validator("ordered_files")
    @classmethod
    def _validate_ordered_files(cls, value: tuple[str, ...]) -> tuple[str, ...]:
        if any(not path or path != path.strip() for path in value):
            raise ValueError("effective_config_snapshot_layer_files_mismatch")
        return value

    @field_validator("blockers")
    @classmethod
    def _validate_blockers(cls, value: tuple[str, ...]) -> tuple[str, ...]:
        if any(not blocker or blocker != blocker.strip() for blocker in value):
            raise ValueError("effective_config_snapshot_blocker_invalid")
        return value

    @model_validator(mode="after")
    def _validate_snapshot(self) -> "CanonicalEffectiveConfigSnapshot":
        if tuple(layer.type for layer in self.ordered_layers) != _EXPECTED_LAYER_ORDER:
            raise ValueError("effective_config_snapshot_layer_order_invalid")
        if tuple(layer.path for layer in self.ordered_layers) != self.ordered_files:
            raise ValueError("effective_config_snapshot_layer_files_mismatch")

        layer_payloads = tuple(layer.model_dump() for layer in self.ordered_layers)
        if any(layer not in layer_payloads for layer in thaw_json(self.provenance).values()):
            raise ValueError("effective_config_snapshot_provenance_invalid")

        config = thaw_json(self.config)
        if set(config) != _EXPECTED_CONFIG_ROOTS:
            raise ValueError("effective_config_snapshot_roots_invalid")
        if config["schema_version"] != "effective-trading-config.v2":
            raise ValueError("effective_config_snapshot_schema_version_invalid")
        if any(
            not isinstance(config[root], Mapping)
            for root in ("units", "safety", "mode", "setup", "exchange", "environment")
        ):
            raise ValueError("effective_config_snapshot_roots_invalid")

        request = self.request
        if (
            config["mode"].get("mode_id") != request.mode_id
            or config["mode"].get("mode_version") != request.mode_version
            or config["setup"].get("setup_id") != request.setup_id
            or config["setup"].get("setup_version") != request.setup_version
            or config["setup"].get("side") != request.side
            or config["exchange"].get("id") != request.exchange
            or config["environment"].get("id") != request.environment
        ):
            raise ValueError("effective_config_snapshot_config_identity_mismatch")

        if self.config_hash != calculate_config_hash(config, self.condition_catalog_hash):
            raise ValueError("effective_config_snapshot_hash_mismatch")
        if self.snapshot_hash != calculate_snapshot_hash(self):
            raise ValueError("effective_config_snapshot_hash_mismatch")
        return self


__all__ = [
    "PUBLISHED_MODE_VERSIONS",
    "PUBLISHED_SETUP_VERSIONS",
    "CanonicalEffectiveConfigLayer",
    "CanonicalEffectiveConfigRequest",
    "CanonicalEffectiveConfigSnapshot",
    "FrozenJsonDict",
    "ModernTradingIdentity",
    "calculate_config_hash",
    "calculate_snapshot_hash",
    "thaw_json",
]
