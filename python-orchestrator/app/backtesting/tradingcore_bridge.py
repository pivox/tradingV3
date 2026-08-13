"""Fail-closed bridge to the canonical Symfony setup-rule runtime."""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
import threading
import time
from collections.abc import Mapping
from pathlib import Path
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_serializer, field_validator, model_validator

from app.modern_trading_contracts import (
    CanonicalEffectiveConfigSnapshot,
    FrozenJsonDict,
    _canonical_json,
    thaw_json,
)


_SHA256_PATTERN = r"^sha256:[0-9a-f]{64}$"
_UTC_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$")
_TIMEFRAMES = frozenset({"1m", "5m", "15m", "1h", "4h"})
_MAX_BYTES = 8 * 1024 * 1024


def _canonical_hash(value: Mapping[str, Any] | BaseModel) -> str:
    payload = value.model_dump(mode="json") if isinstance(value, BaseModel) else value
    return "sha256:" + hashlib.sha256(_canonical_json(payload).encode()).hexdigest()


def _require_utc_z(value: Any, reason: str) -> str:
    if type(value) is not str or _UTC_PATTERN.fullmatch(value) is None:
        raise ValueError(reason)
    # Round-trip through the stdlib to reject calendar-invalid lexemes.
    from datetime import datetime

    try:
        datetime.fromisoformat(value.removesuffix("Z") + "+00:00")
    except ValueError as exc:
        raise ValueError(reason) from exc
    return value


class CanonicalIndicatorSnapshot(BaseModel):
    """One immutable, identity-bound indicator mapping."""

    model_config = ConfigDict(frozen=True, extra="allow", strict=True, arbitrary_types_allowed=True)

    snapshot_identity: FrozenJsonDict
    kline_time: str

    @field_validator("snapshot_identity", mode="before")
    @classmethod
    def _freeze_identity(cls, value: Any) -> FrozenJsonDict:
        if not isinstance(value, Mapping):
            raise ValueError("canonical_rule_indicator_identity_invalid")
        expected = {"timeframe", "symbol", "exchange", "environment", "market_type"}
        if set(value) != expected or any(type(item) is not str for item in value.values()):
            raise ValueError("canonical_rule_indicator_identity_invalid")
        return FrozenJsonDict(value)

    @field_serializer("snapshot_identity")
    def _serialize_identity(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @field_validator("kline_time", mode="before")
    @classmethod
    def _validate_time(cls, value: Any) -> str:
        return _require_utc_z(value, "canonical_rule_kline_time_invalid")

    @model_validator(mode="before")
    @classmethod
    def _reject_unsupported_values(cls, value: Any) -> Any:
        if not isinstance(value, Mapping):
            return value
        # Canonical hashing performs the complete recursive type/finite check.
        _canonical_json(value)
        return value


class CanonicalBacktestRuleRequest(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True, arbitrary_types_allowed=True)

    schema_version: Literal["canonical-backtest-rule-request.v1"]
    request_id: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$")
    effective_config_snapshot: CanonicalEffectiveConfigSnapshot
    symbol: str = Field(pattern=r"^[A-Z0-9]{2,32}$")
    market_type: Literal["perpetual", "spot"]
    evaluated_at: str
    indicators_by_timeframe: FrozenJsonDict

    @field_validator("schema_version", "request_id", "symbol", "market_type", mode="before")
    @classmethod
    def _reject_string_coercion(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("canonical_rule_string_type_invalid")
        return value

    @field_validator("evaluated_at", mode="before")
    @classmethod
    def _validate_evaluated_at(cls, value: Any) -> str:
        return _require_utc_z(value, "canonical_rule_evaluated_at_invalid")

    @field_validator("indicators_by_timeframe", mode="before")
    @classmethod
    def _validate_indicator_mapping(cls, value: Any) -> FrozenJsonDict:
        if not isinstance(value, Mapping) or not value or len(value) > 16:
            raise ValueError("canonical_rule_indicators_invalid")
        if any(type(key) is not str or key not in _TIMEFRAMES for key in value):
            raise ValueError("canonical_rule_indicators_invalid")
        normalized = {
            key: CanonicalIndicatorSnapshot.model_validate(item).model_dump(mode="json")
            for key, item in value.items()
        }
        return FrozenJsonDict(normalized)

    @field_serializer("indicators_by_timeframe")
    def _serialize_indicators(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @model_validator(mode="after")
    def _validate_identity(self) -> "CanonicalBacktestRuleRequest":
        config_request = self.effective_config_snapshot.request
        if (
            config_request.exchange != "fake"
            or config_request.environment not in {"local", "test"}
            or config_request.execution_capability != "backtest"
            or not self.effective_config_snapshot.executable
            or self.effective_config_snapshot.blockers
        ):
            raise ValueError("canonical_rule_fake_backtest_required")
        expected_base = {
            "symbol": self.symbol,
            "exchange": "fake",
            "environment": config_request.environment,
            "market_type": self.market_type,
        }
        for timeframe, indicator in self.indicators_by_timeframe.items():
            identity = thaw_json(indicator["snapshot_identity"])
            if identity != {"timeframe": timeframe, **expected_base}:
                raise ValueError("canonical_rule_indicator_identity_mismatch")
        _canonical_json(self.model_dump(mode="json"))
        return self

    def input_hash(self) -> str:
        return _canonical_hash(self)


class CanonicalBacktestRuleResult(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True, arbitrary_types_allowed=True)

    schema_version: Literal["canonical-backtest-rule-result.v1"]
    request_id: str
    mode_id: Literal["day_trading", "scalping", "micro_scalping"]
    mode_version: Literal["1.0.0", "1.1.0"]
    setup_id: str
    setup_version: Literal["1.0.0", "1.1.0"]
    side: Literal["long", "short"]
    exchange: Literal["fake"]
    environment: Literal["local", "test"]
    market_type: Literal["perpetual", "spot"]
    symbol: str
    config_hash: str = Field(pattern=_SHA256_PATTERN)
    condition_catalog_hash: str = Field(pattern=_SHA256_PATTERN)
    snapshot_hash: str = Field(pattern=_SHA256_PATTERN)
    evaluated_at: str
    passed: bool
    reason_code: str
    trace: FrozenJsonDict
    input_hash: str = Field(pattern=_SHA256_PATTERN)
    result_hash: str = Field(pattern=_SHA256_PATTERN)

    @field_validator(
        "schema_version", "request_id", "mode_id", "mode_version", "setup_id",
        "setup_version", "side", "exchange", "environment", "market_type", "symbol",
        "config_hash", "condition_catalog_hash", "snapshot_hash", "reason_code",
        "input_hash", "result_hash", mode="before",
    )
    @classmethod
    def _reject_string_coercion(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("canonical_rule_result_string_type_invalid")
        return value

    @field_validator("passed", mode="before")
    @classmethod
    def _reject_bool_coercion(cls, value: Any) -> Any:
        if type(value) is not bool:
            raise ValueError("canonical_rule_result_bool_type_invalid")
        return value

    @field_validator("evaluated_at", mode="before")
    @classmethod
    def _validate_time(cls, value: Any) -> str:
        return _require_utc_z(value, "canonical_rule_result_evaluated_at_invalid")

    @field_validator("trace", mode="before")
    @classmethod
    def _freeze_trace(cls, value: Any) -> FrozenJsonDict:
        if not isinstance(value, Mapping) or not value:
            raise ValueError("canonical_rule_result_trace_invalid")
        if "plan_cache_hit" in value:
            raise ValueError("canonical_rule_result_trace_nondeterministic")
        return FrozenJsonDict(value)

    @field_serializer("trace")
    def _serialize_trace(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @model_validator(mode="after")
    def _validate_result_hash(self) -> "CanonicalBacktestRuleResult":
        payload = self.model_dump(mode="json", exclude={"result_hash"})
        if _canonical_hash(payload) != self.result_hash:
            raise ValueError("canonical_rule_result_hash_mismatch")
        return self


class TradingCoreBridgeError(RuntimeError):
    """Stable fail-closed bridge error; child diagnostics are never embedded."""


class BacktestTradingCoreBridge:
    DEFAULT_TIMEOUT_SECONDS = 15.0

    def __init__(
        self,
        argv: tuple[str, ...] | None = None,
        *,
        timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
        max_output_bytes: int = _MAX_BYTES,
    ) -> None:
        if argv is None:
            repository = Path(__file__).resolve().parents[3]
            argv = (
                "php",
                str(repository / "trading-app" / "bin" / "console"),
                "app:backtest:rules:evaluate",
                "--no-interaction",
                "--no-ansi",
            )
        if not argv or any(type(item) is not str or not item for item in argv):
            raise ValueError("tradingcore_bridge_argv_invalid")
        if timeout_seconds <= 0 or max_output_bytes < 1:
            raise ValueError("tradingcore_bridge_bounds_invalid")
        self._argv = argv
        self._timeout = float(timeout_seconds)
        self._max_output = max_output_bytes

    @property
    def argv(self) -> tuple[str, ...]:
        return self._argv

    def evaluate(self, request: CanonicalBacktestRuleRequest) -> CanonicalBacktestRuleResult:
        if not isinstance(request, CanonicalBacktestRuleRequest):
            raise TypeError("canonical_rule_request_required")
        payload = _canonical_json(request.model_dump(mode="json")).encode()
        if len(payload) > _MAX_BYTES:
            raise TradingCoreBridgeError("tradingcore_bridge_input_too_large")
        returncode, stdout, _stderr = self._run_bounded(payload)
        if returncode != 0:
            raise TradingCoreBridgeError("tradingcore_bridge_process_failed")
        result_payload = self._decode_result(stdout)
        try:
            result = CanonicalBacktestRuleResult.model_validate(result_payload)
        except ValueError as exc:
            raise TradingCoreBridgeError("tradingcore_bridge_result_invalid") from exc
        self._assert_request_binding(request, result)
        return result

    def _run_bounded(self, payload: bytes) -> tuple[int, bytes, bytes]:
        try:
            process = subprocess.Popen(
                list(self._argv),
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                shell=False,
            )
        except (OSError, ValueError) as exc:
            raise TradingCoreBridgeError("tradingcore_bridge_process_unavailable") from exc
        assert process.stdin is not None and process.stdout is not None and process.stderr is not None
        buffers = {"stdout": bytearray(), "stderr": bytearray()}
        overflow = threading.Event()
        failures: list[BaseException] = []

        def read_stream(name: str, stream: Any) -> None:
            try:
                while chunk := stream.read(65_536):
                    target = buffers[name]
                    if len(target) + len(chunk) > self._max_output:
                        overflow.set()
                        return
                    target.extend(chunk)
            except BaseException as exc:  # pragma: no cover - defensive OS boundary
                failures.append(exc)

        def write_input() -> None:
            try:
                process.stdin.write(payload)
                process.stdin.close()
            except BrokenPipeError:
                pass
            except BaseException as exc:  # pragma: no cover - defensive OS boundary
                failures.append(exc)

        threads = [
            threading.Thread(target=read_stream, args=("stdout", process.stdout), daemon=True),
            threading.Thread(target=read_stream, args=("stderr", process.stderr), daemon=True),
            threading.Thread(target=write_input, daemon=True),
        ]
        for thread in threads:
            thread.start()
        deadline = time.monotonic() + self._timeout
        try:
            while process.poll() is None:
                if overflow.is_set():
                    raise TradingCoreBridgeError("tradingcore_bridge_output_too_large")
                if time.monotonic() >= deadline:
                    raise TradingCoreBridgeError("tradingcore_bridge_timeout")
                time.sleep(0.005)
            remaining = max(0.0, deadline - time.monotonic())
            for thread in threads:
                thread.join(remaining)
                remaining = max(0.0, deadline - time.monotonic())
            if overflow.is_set():
                raise TradingCoreBridgeError("tradingcore_bridge_output_too_large")
            if any(thread.is_alive() for thread in threads):
                raise TradingCoreBridgeError("tradingcore_bridge_timeout")
            if failures:
                raise TradingCoreBridgeError("tradingcore_bridge_io_failed")
            return process.returncode, bytes(buffers["stdout"]), bytes(buffers["stderr"])
        except TradingCoreBridgeError:
            process.kill()
            process.wait()
            for stream in (process.stdin, process.stdout, process.stderr):
                try:
                    stream.close()
                except OSError:
                    pass
            for thread in threads:
                thread.join(1.0)
            raise
        finally:
            if process.poll() is not None:
                process.wait()
                for stream in (process.stdin, process.stdout, process.stderr):
                    try:
                        stream.close()
                    except OSError:
                        pass

    def _decode_result(self, stdout: bytes) -> Mapping[str, Any]:
        if not stdout or len(stdout) > self._max_output:
            raise TradingCoreBridgeError("tradingcore_bridge_result_invalid")
        try:
            text = stdout.decode("utf-8")
            decoded = json.loads(
                text,
                object_pairs_hook=self._unique_object,
                parse_constant=lambda _value: (_ for _ in ()).throw(ValueError()),
            )
        except (UnicodeDecodeError, json.JSONDecodeError, ValueError) as exc:
            raise TradingCoreBridgeError("tradingcore_bridge_result_invalid") from exc
        if not isinstance(decoded, Mapping):
            raise TradingCoreBridgeError("tradingcore_bridge_result_invalid")
        return decoded

    @staticmethod
    def _unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate")
            result[key] = value
        return result

    @staticmethod
    def _assert_request_binding(
        request: CanonicalBacktestRuleRequest,
        result: CanonicalBacktestRuleResult,
    ) -> None:
        snapshot = request.effective_config_snapshot
        expected = {
            "request_id": request.request_id,
            "mode_id": snapshot.request.mode_id,
            "mode_version": snapshot.request.mode_version,
            "setup_id": snapshot.request.setup_id,
            "setup_version": snapshot.request.setup_version,
            "side": snapshot.request.side,
            "exchange": "fake",
            "environment": snapshot.request.environment,
            "market_type": request.market_type,
            "symbol": request.symbol,
            "config_hash": snapshot.config_hash,
            "condition_catalog_hash": snapshot.condition_catalog_hash,
            "snapshot_hash": snapshot.snapshot_hash,
            "evaluated_at": request.evaluated_at,
            "input_hash": request.input_hash(),
        }
        for field, value in expected.items():
            if getattr(result, field) != value:
                raise TradingCoreBridgeError("tradingcore_bridge_result_identity_mismatch")


__all__ = [
    "BacktestTradingCoreBridge",
    "CanonicalBacktestRuleRequest",
    "CanonicalBacktestRuleResult",
    "CanonicalIndicatorSnapshot",
    "TradingCoreBridgeError",
]
