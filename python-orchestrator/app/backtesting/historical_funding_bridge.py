"""Bounded bridge to the canonical PHP historical funding authority."""

from __future__ import annotations

import hashlib
import json
import math
import subprocess
import threading
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Literal
from decimal import Decimal

from pydantic import BaseModel, ConfigDict, Field, field_serializer, field_validator, model_validator


_HASH = r"^sha256:[0-9a-f]{64}$"
_DATASET = r"^backtest-dataset-[0-9a-f]{64}$"
_MAX_BYTES = 8 * 1024 * 1024


def _time(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() != timedelta(0):
        raise ValueError("canonical_historical_funding_time_invalid")
    return value.astimezone(timezone.utc)


def _json_time(value: datetime) -> str:
    return _time(value).isoformat(timespec="microseconds").replace("+00:00", "Z")


def _ordered_json(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode()


class CanonicalFundingRecord(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    source_record_id: str = Field(min_length=1, max_length=256)
    funding_at: datetime
    available_at: datetime
    funding_rate: str
    mark_price: str
    interval_seconds: int = Field(gt=0, le=604_800)

    @field_validator("funding_at", "available_at")
    @classmethod
    def _times(cls, value: datetime) -> datetime: return _time(value)

    @field_serializer("funding_at", "available_at")
    def _serialize_times(self, value: datetime) -> str: return _json_time(value)


class CanonicalHistoricalFundingRequest(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    schema_version: Literal["canonical-historical-funding-request.v1"]
    dataset_id: str = Field(pattern=_DATASET)
    dataset_checksum: str = Field(pattern=_HASH)
    schedule_checksum: str = Field(pattern=_HASH)
    plan_hash: str = Field(pattern=_HASH)
    config_hash: str = Field(pattern=_HASH)
    cost_input_hash: str = Field(pattern=_HASH)
    symbol: str = Field(pattern=r"^[A-Z0-9]{2,32}$")
    side: Literal["long", "short"]
    quantity: str
    contract_size: str
    entry_at: datetime
    exit_at: datetime
    coverage_start: datetime
    coverage_end: datetime
    records: tuple[CanonicalFundingRecord, ...] = Field(min_length=1, max_length=100_000)

    @field_validator("records", mode="before")
    @classmethod
    def _records(cls, value: Any) -> tuple[Any, ...]:
        if not isinstance(value, (list, tuple)):
            raise ValueError("canonical_historical_funding_records_invalid")
        return tuple(value)

    @field_validator("entry_at", "exit_at", "coverage_start", "coverage_end")
    @classmethod
    def _times(cls, value: datetime) -> datetime: return _time(value)

    @field_serializer("entry_at", "exit_at", "coverage_start", "coverage_end")
    def _serialize_times(self, value: datetime) -> str: return _json_time(value)

    @model_validator(mode="after")
    def _binding(self) -> "CanonicalHistoricalFundingRequest":
        if self.dataset_id != "backtest-dataset-" + self.dataset_checksum.removeprefix("sha256:"):
            raise ValueError("canonical_historical_funding_dataset_invalid")
        return self

    def wire(self) -> dict[str, Any]: return self.model_dump(mode="json")
    def request_hash(self) -> str: return "sha256:" + hashlib.sha256(_ordered_json(self.wire())).hexdigest()


class CanonicalHistoricalFundingResult(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    schema_version: Literal["canonical-historical-funding-result.v1"]
    dataset_id: str = Field(pattern=_DATASET)
    dataset_checksum: str = Field(pattern=_HASH)
    schedule_checksum: str = Field(pattern=_HASH)
    plan_hash: str = Field(pattern=_HASH)
    config_hash: str = Field(pattern=_HASH)
    cost_input_hash: str = Field(pattern=_HASH)
    symbol: str
    side: Literal["long", "short"]
    quantity: str
    contract_size: str
    entry_at: str
    exit_at: str
    applied_source_record_ids: tuple[str, ...]
    applied_record_count: int = Field(ge=0)
    funding_cashflow_quote: str
    request_hash: str = Field(pattern=_HASH)
    result_hash: str = Field(pattern=_HASH)

    @field_validator("applied_source_record_ids", mode="before")
    @classmethod
    def _ids(cls, value: Any) -> tuple[str, ...]:
        if not isinstance(value, (list, tuple)):
            raise ValueError("canonical_historical_funding_result_ids_invalid")
        return tuple(value)

    @model_validator(mode="after")
    def _hash(self) -> "CanonicalHistoricalFundingResult":
        wire = self.model_dump(mode="json", exclude={"result_hash"})
        expected = "sha256:" + hashlib.sha256(_ordered_json(wire)).hexdigest()
        if expected != self.result_hash or self.applied_record_count != len(self.applied_source_record_ids):
            raise ValueError("canonical_historical_funding_result_hash_invalid")
        return self


class HistoricalFundingBridgeError(RuntimeError): pass


def canonical_historical_funding_request(envelope: Any, execution: Any, schedule: Any) -> CanonicalHistoricalFundingRequest:
    plan = envelope.plan
    entry, terminal = execution.events
    return CanonicalHistoricalFundingRequest(
        schema_version="canonical-historical-funding-request.v1",
        dataset_id=envelope.dataset_id, dataset_checksum=envelope.dataset_checksum,
        schedule_checksum=schedule.schedule_checksum, plan_hash=plan.plan_hash,
        config_hash=plan.config_hash, cost_input_hash=plan.cost_input_hash,
        symbol=plan.symbol, side=plan.side,
        quantity=_decimal_string(plan.quantity), contract_size=_decimal_string(plan.contract_size),
        entry_at=entry.happened_at, exit_at=terminal.happened_at,
        coverage_start=schedule.coverage_start, coverage_end=schedule.coverage_end,
        records=tuple({
            "source_record_id": record.source_record_id, "funding_at": record.funding_at,
            "available_at": record.available_at, "funding_rate": record.funding_rate,
            "mark_price": record.mark_price, "interval_seconds": record.interval_seconds,
        } for record in schedule.records),
    )


def settlement_matches_request(
    result: CanonicalHistoricalFundingResult,
    request: CanonicalHistoricalFundingRequest,
) -> bool:
    return all(
        getattr(result, field) == getattr(request, field)
        for field in (
            "dataset_id", "dataset_checksum", "schedule_checksum", "plan_hash",
            "config_hash", "cost_input_hash", "symbol", "side", "quantity",
            "contract_size",
        )
    ) and result.entry_at == _json_time(request.entry_at) and result.exit_at == _json_time(request.exit_at)


def _decimal_string(value: Any) -> str:
    rendered = format(Decimal(str(value)), "f")
    return rendered.rstrip("0").rstrip(".") if "." in rendered else rendered


class HistoricalFundingBridge:
    def __init__(self, argv: tuple[str, ...] | None = None, *, timeout_seconds: float = 15.0, max_output_bytes: int = _MAX_BYTES) -> None:
        if argv is None:
            root = Path(__file__).resolve().parents[3]
            argv = ("php", str(root / "trading-app/bin/console"), "app:backtest:funding:settle", "--no-interaction", "--no-ansi")
        if not argv or any(type(item) is not str or not item for item in argv): raise ValueError("historical_funding_bridge_argv_invalid")
        if type(timeout_seconds) not in (int, float) or not math.isfinite(timeout_seconds) or timeout_seconds <= 0 or type(max_output_bytes) is not int or not 1 <= max_output_bytes <= _MAX_BYTES:
            raise ValueError("historical_funding_bridge_bounds_invalid")
        self._argv, self._timeout, self._max_output = argv, float(timeout_seconds), max_output_bytes

    def settle(self, request: CanonicalHistoricalFundingRequest) -> CanonicalHistoricalFundingResult:
        if not isinstance(request, CanonicalHistoricalFundingRequest): raise TypeError("canonical_historical_funding_request_required")
        code, stdout = self._run(_ordered_json(request.wire()))
        if code != 0: raise HistoricalFundingBridgeError("historical_funding_bridge_process_failed")
        try:
            raw = json.loads(stdout, object_pairs_hook=self._unique)
            result = CanonicalHistoricalFundingResult.model_validate(raw)
        except Exception as exc:
            raise HistoricalFundingBridgeError("historical_funding_bridge_result_invalid") from exc
        for field in ("dataset_id", "dataset_checksum", "schedule_checksum", "plan_hash", "config_hash", "cost_input_hash", "symbol", "side", "quantity", "contract_size"):
            if getattr(result, field) != getattr(request, field): raise HistoricalFundingBridgeError("historical_funding_bridge_result_identity_mismatch")
        if result.entry_at != _json_time(request.entry_at) or result.exit_at != _json_time(request.exit_at) or result.request_hash != request.request_hash():
            raise HistoricalFundingBridgeError("historical_funding_bridge_result_identity_mismatch")
        return result

    def _run(self, payload: bytes) -> tuple[int, bytes]:
        try: process = subprocess.Popen(self._argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, shell=False)
        except (OSError, ValueError) as exc: raise HistoricalFundingBridgeError("historical_funding_bridge_process_unavailable") from exc
        assert process.stdin and process.stdout and process.stderr
        output = bytearray(); overflow = threading.Event()
        def read_stdout() -> None:
            while chunk := process.stdout.read(65536):
                if len(output) + len(chunk) > self._max_output: overflow.set(); return
                output.extend(chunk)
        def drain_stderr() -> None:
            total = 0
            while chunk := process.stderr.read(65536):
                total += len(chunk)
                if total > self._max_output: overflow.set(); return
        threads = [threading.Thread(target=read_stdout, daemon=True), threading.Thread(target=drain_stderr, daemon=True)]
        for thread in threads: thread.start()
        try:
            process.stdin.write(payload); process.stdin.close()
            deadline = time.monotonic() + self._timeout
            while process.poll() is None:
                if overflow.is_set(): raise HistoricalFundingBridgeError("historical_funding_bridge_output_too_large")
                if time.monotonic() >= deadline: raise HistoricalFundingBridgeError("historical_funding_bridge_timeout")
                time.sleep(0.005)
            for thread in threads: thread.join(max(0.0, deadline - time.monotonic()))
            if overflow.is_set(): raise HistoricalFundingBridgeError("historical_funding_bridge_output_too_large")
            if any(thread.is_alive() for thread in threads): raise HistoricalFundingBridgeError("historical_funding_bridge_timeout")
            return process.returncode, bytes(output)
        except HistoricalFundingBridgeError:
            process.kill(); process.wait(); raise
        finally:
            if process.poll() is not None: process.wait()
            for stream in (process.stdin, process.stdout, process.stderr):
                try: stream.close()
                except OSError: pass

    @staticmethod
    def _unique(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result = {}
        for key, value in pairs:
            if key in result: raise ValueError("duplicate")
            result[key] = value
        return result
