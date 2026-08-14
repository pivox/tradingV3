"""Bounded bridge to the canonical PHP partial-fill cost authority."""

from __future__ import annotations

import hashlib
import json
import math
import re
import subprocess
import threading
import time
from decimal import Decimal
from datetime import datetime
from pathlib import Path
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.backtesting.backtrader_contracts import (
    CanonicalBacktestOrderPlan,
    CanonicalBacktestPlan,
    _encode_php_plan_value,
)
from app.backtesting.backtrader_execution import BacktestExecutionResult
from app.backtesting.visible_queue_depletion import VisibleQueueDepletionResult


_HASH = r"^sha256:[0-9a-f]{64}$"
_DATASET = r"^backtest-dataset-[0-9a-f]{64}$"
_DECIMAL = re.compile(r"^-?(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$")
_POSITIVE_DECIMAL = re.compile(r"^(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$")
_MAX_BYTES = 1024 * 1024


def _ordered_json(value: Any) -> bytes:
    """Encode values exactly like the ordered PHP canonical JSON authority."""

    return _encode_php_plan_value(value).encode()


def _decimal(value: Any, *, positive: bool = False) -> str:
    pattern = _POSITIVE_DECIMAL if positive else _DECIMAL
    if type(value) is not str or pattern.fullmatch(value) is None:
        raise ValueError("canonical_partial_fill_cost_decimal_invalid")
    number = Decimal(value)
    if (positive and number <= 0) or (number == 0 and value.startswith("-")):
        raise ValueError("canonical_partial_fill_cost_decimal_invalid")
    return value


def _plan_wire(plan: CanonicalBacktestPlan) -> dict[str, Any]:
    wire = plan.model_dump(mode="json", by_alias=True)
    for key in ("cancelAfterAt", "holdingExpiresAt", "orderBookInputHash"):
        if wire.get(key) is None:
            wire.pop(key)
    return wire


def _plan_base_quantity(plan: CanonicalBacktestPlan) -> Decimal:
    return Decimal(str(plan.quantity)) * Decimal(str(plan.contract_size))


class CanonicalPartialFillCostRequest(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    schema_version: Literal["canonical-partial-fill-cost-request.v1"]
    dataset_id: str = Field(pattern=_DATASET)
    dataset_checksum: str = Field(pattern=_HASH)
    plan: CanonicalBacktestPlan
    maker_fill_result_hash: str = Field(pattern=_HASH)
    maker_fill_trace_hash: str = Field(pattern=_HASH)
    filled_quantity_base: str
    terminal_kind: Literal["stop_filled", "target_filled"]
    target_id: str | None

    @field_validator("filled_quantity_base", mode="before")
    @classmethod
    def _filled(cls, value: Any) -> str:
        return _decimal(value, positive=True)

    @field_validator("target_id", mode="before")
    @classmethod
    def _target_id(cls, value: Any) -> Any:
        if value is not None and (type(value) is not str or not value):
            raise ValueError("canonical_partial_fill_cost_target_invalid")
        return value

    @model_validator(mode="after")
    def _binding(self) -> "CanonicalPartialFillCostRequest":
        if self.dataset_id != "backtest-dataset-" + self.dataset_checksum.removeprefix("sha256:"):
            raise ValueError("canonical_partial_fill_cost_dataset_invalid")
        if self.plan.market_fallback is not False:
            raise ValueError("canonical_partial_fill_cost_v2_plan_required")
        if Decimal(self.filled_quantity_base) > _plan_base_quantity(self.plan):
            raise ValueError("canonical_partial_fill_cost_quantity_invalid")
        if (self.terminal_kind == "stop_filled") != (self.target_id is None):
            raise ValueError("canonical_partial_fill_cost_target_invalid")
        if self.target_id is not None and self.target_id not in {
            target.id for target in self.plan.targets
        }:
            raise ValueError("canonical_partial_fill_cost_target_invalid")
        return self

    def wire(self) -> dict[str, Any]:
        return {
            "schema_version": self.schema_version,
            "dataset_id": self.dataset_id,
            "dataset_checksum": self.dataset_checksum,
            "plan": _plan_wire(self.plan),
            "maker_fill_result_hash": self.maker_fill_result_hash,
            "maker_fill_trace_hash": self.maker_fill_trace_hash,
            "filled_quantity_base": self.filled_quantity_base,
            "terminal_kind": self.terminal_kind,
            "target_id": self.target_id,
        }

    def request_hash(self) -> str:
        return "sha256:" + hashlib.sha256(_ordered_json(self.wire())).hexdigest()


class CanonicalPartialFillCostResult(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    schema_version: Literal["canonical-partial-fill-cost-result.v1"]
    cost_policy_version: Literal["canonical-plan-partial-quantity.v1"]
    cost_evidence: Literal["canonical_plan_partial_quantity"]
    costs_are_certified: Literal[False]
    dataset_id: str = Field(pattern=_DATASET)
    dataset_checksum: str = Field(pattern=_HASH)
    plan_hash: str = Field(pattern=_HASH)
    config_hash: str = Field(pattern=_HASH)
    cost_input_hash: str = Field(pattern=_HASH)
    maker_fill_result_hash: str = Field(pattern=_HASH)
    maker_fill_trace_hash: str = Field(pattern=_HASH)
    mode_id: str = Field(min_length=1)
    mode_version: str = Field(min_length=1)
    setup_id: str = Field(min_length=1)
    setup_version: str = Field(min_length=1)
    symbol: str = Field(min_length=1)
    market_type: str = Field(min_length=1)
    side: Literal["long", "short"]
    planned_quantity_base: str
    filled_quantity_base: str
    remaining_quantity_base: str
    is_partial_fill: bool
    terminal_kind: Literal["stop_filled", "target_filled"]
    target_id: str | None
    gross_pnl_quote: str
    entry_fee_quote: str
    exit_fee_quote: str
    entry_spread_cost_quote: str
    exit_spread_cost_quote: str
    entry_slippage_cost_quote: str
    exit_slippage_cost_quote: str
    planned_adverse_funding_cost_quote: str
    total_planned_cost_quote: str
    gross_stop_risk_quote: str
    total_stop_risk_quote: str
    net_pnl_quote: str
    net_r: str
    result_is_live_proof: Literal[False]
    request_hash: str = Field(pattern=_HASH)
    result_hash: str = Field(pattern=_HASH)

    @field_validator(
        "planned_quantity_base",
        "filled_quantity_base",
        "remaining_quantity_base",
        "entry_fee_quote",
        "exit_fee_quote",
        "entry_spread_cost_quote",
        "exit_spread_cost_quote",
        "entry_slippage_cost_quote",
        "exit_slippage_cost_quote",
        "planned_adverse_funding_cost_quote",
        "total_planned_cost_quote",
        "gross_stop_risk_quote",
        "total_stop_risk_quote",
        mode="before",
    )
    @classmethod
    def _nonnegative_decimals(cls, value: Any) -> str:
        rendered = _decimal(value)
        if Decimal(rendered) < 0:
            raise ValueError("canonical_partial_fill_cost_decimal_invalid")
        return rendered

    @field_validator("gross_pnl_quote", "net_pnl_quote", "net_r", mode="before")
    @classmethod
    def _signed_decimals(cls, value: Any) -> str:
        return _decimal(value)

    @field_validator("target_id", mode="before")
    @classmethod
    def _result_target_id(cls, value: Any) -> Any:
        if value is not None and (type(value) is not str or not value):
            raise ValueError("canonical_partial_fill_cost_result_invalid")
        return value

    @model_validator(mode="after")
    def _reconcile(self) -> "CanonicalPartialFillCostResult":
        unsigned = self.model_dump(mode="json", exclude={"result_hash"})
        expected_hash = "sha256:" + hashlib.sha256(_ordered_json(unsigned)).hexdigest()
        planned = Decimal(self.planned_quantity_base)
        filled = Decimal(self.filled_quantity_base)
        remaining = Decimal(self.remaining_quantity_base)
        gross = Decimal(self.gross_pnl_quote)
        cost = Decimal(self.total_planned_cost_quote)
        net = Decimal(self.net_pnl_quote)
        cost_components = sum(
            (
                Decimal(value)
                for value in (
                    self.entry_fee_quote,
                    self.exit_fee_quote,
                    self.entry_spread_cost_quote,
                    self.exit_spread_cost_quote,
                    self.entry_slippage_cost_quote,
                    self.exit_slippage_cost_quote,
                    self.planned_adverse_funding_cost_quote,
                )
            ),
            Decimal(0),
        )
        if (
            expected_hash != self.result_hash
            or self.dataset_id
            != "backtest-dataset-" + self.dataset_checksum.removeprefix("sha256:")
            or planned <= 0
            or filled <= 0
            or filled > planned
            or remaining < 0
            or filled + remaining != planned
            or self.is_partial_fill != (filled < planned)
            or (self.terminal_kind == "stop_filled") != (self.target_id is None)
            or cost != cost_components
        ):
            raise ValueError("canonical_partial_fill_cost_result_invalid")
        if self.terminal_kind == "target_filled":
            if gross <= 0 or gross - cost != net:
                raise ValueError("canonical_partial_fill_cost_result_invalid")
        elif (
            Decimal(self.net_r) != -1
            or gross != -Decimal(self.gross_stop_risk_quote)
            or net != -Decimal(self.total_stop_risk_quote)
        ):
            raise ValueError("canonical_partial_fill_cost_result_invalid")
        return self


class PartialFillCostBridgeError(RuntimeError):
    """Raised when the local PHP authority fails closed."""


def canonical_partial_fill_cost_request(
    envelope: CanonicalBacktestOrderPlan,
    evidence: VisibleQueueDepletionResult,
    execution: BacktestExecutionResult,
) -> CanonicalPartialFillCostRequest:
    try:
        evidence = VisibleQueueDepletionResult.model_validate(
            evidence.model_dump(mode="json")
        )
    except Exception as exc:
        raise ValueError("canonical_partial_fill_cost_execution_invalid") from exc
    if (
        type(envelope) is not CanonicalBacktestOrderPlan
        or type(execution) is not BacktestExecutionResult
        or execution.status != "closed"
        or len(execution.events) < 2
        or execution.events[-1].kind not in ("stop_filled", "target_filled")
    ):
        raise ValueError("canonical_partial_fill_cost_execution_invalid")
    plan = envelope.plan
    if (
        evidence.dataset_id != envelope.dataset_id
        or evidence.dataset_checksum != envelope.dataset_checksum
        or evidence.plan_hash != plan.plan_hash
        or evidence.config_hash != plan.config_hash
        or evidence.symbol != plan.symbol
        or evidence.market_type != plan.market_type
        or evidence.side != plan.side
    ):
        raise ValueError("canonical_partial_fill_cost_execution_invalid")

    positive_fills = tuple(
        item for item in evidence.trace if Decimal(item.fill_quantity_base) > 0
    )
    count = execution.consumed_fill_count
    entry_events = execution.events[:-1]
    if count <= 0 or count > len(positive_fills) or len(entry_events) != count:
        raise ValueError("canonical_partial_fill_cost_execution_invalid")
    cumulative = Decimal(0)
    contract_size = Decimal(str(plan.contract_size))
    total = Decimal(evidence.order_quantity_base)
    for event, item in zip(entry_events, positive_fills[:count], strict=True):
        increment = Decimal(item.fill_quantity_base)
        cumulative += increment
        expected_kind = (
            "entry_filled" if cumulative == total else "entry_partially_filled"
        )
        if (
            event.kind != expected_kind
            or event.source_record_id != item.source_record_id
            or event.happened_at != datetime.fromisoformat(item.available_at)
            or event.price != Decimal(evidence.entry_price)
            or event.quantity_base != increment
            or Decimal(str(event.quantity)) != increment / contract_size
            or event.stop_price != plan.stop_price
            or event.plan_hash != plan.plan_hash
            or event.config_hash != plan.config_hash
            or event.dataset_id != envelope.dataset_id
        ):
            raise ValueError("canonical_partial_fill_cost_execution_invalid")
    terminal = execution.events[-1]
    if (
        execution.filled_quantity_base != cumulative
        or execution.cancelled_residual_quantity_base != total - cumulative
        or terminal.quantity_base != cumulative
        or Decimal(str(terminal.quantity)) != cumulative / contract_size
        or terminal.happened_at < entry_events[-1].happened_at
        or terminal.stop_price != plan.stop_price
        or terminal.plan_hash != plan.plan_hash
        or terminal.config_hash != plan.config_hash
        or terminal.dataset_id != envelope.dataset_id
    ):
        raise ValueError("canonical_partial_fill_cost_execution_invalid")
    target_id = None
    if terminal.kind == "target_filled":
        target_id = next(
            (
                target.id
                for target in plan.targets
                if Decimal(str(target.price)) == terminal.price
            ),
            None,
        )
        if target_id is None:
            raise ValueError("canonical_partial_fill_cost_execution_invalid")
    elif terminal.price != Decimal(str(plan.stop_price)):
        raise ValueError("canonical_partial_fill_cost_execution_invalid")
    return CanonicalPartialFillCostRequest(
        schema_version="canonical-partial-fill-cost-request.v1",
        dataset_id=envelope.dataset_id,
        dataset_checksum=envelope.dataset_checksum,
        plan=plan,
        maker_fill_result_hash=evidence.result_hash,
        maker_fill_trace_hash=evidence.trace_hash,
        filled_quantity_base=_canonical_decimal(cumulative),
        terminal_kind=terminal.kind,
        target_id=target_id,
    )


def partial_fill_settlement_matches_request(
    result: CanonicalPartialFillCostResult,
    request: CanonicalPartialFillCostRequest,
) -> bool:
    plan = request.plan
    return all(
        (
            result.dataset_id == request.dataset_id,
            result.dataset_checksum == request.dataset_checksum,
            result.plan_hash == plan.plan_hash,
            result.config_hash == plan.config_hash,
            result.cost_input_hash == plan.cost_input_hash,
            result.maker_fill_result_hash == request.maker_fill_result_hash,
            result.maker_fill_trace_hash == request.maker_fill_trace_hash,
            result.mode_id == plan.mode_id,
            result.mode_version == plan.mode_version,
            result.setup_id == plan.setup_id,
            result.setup_version == plan.setup_version,
            result.symbol == plan.symbol,
            result.market_type == plan.market_type,
            result.side == plan.side,
            Decimal(result.planned_quantity_base) == _plan_base_quantity(plan),
            result.filled_quantity_base == request.filled_quantity_base,
            result.terminal_kind == request.terminal_kind,
            result.target_id == request.target_id,
            result.request_hash == request.request_hash(),
            request.target_id is None
            or Decimal(result.net_r)
            == Decimal(
                str(
                    next(
                        target.net_r
                        for target in plan.targets
                        if target.id == request.target_id
                    )
                )
            ),
        )
    )


def _canonical_decimal(value: Decimal) -> str:
    rendered = format(value, "f")
    if "." in rendered:
        rendered = rendered.rstrip("0").rstrip(".")
    return rendered or "0"


class PartialFillCostBridge:
    def __init__(
        self,
        *,
        timeout_seconds: float = 15.0,
        max_output_bytes: int = _MAX_BYTES,
    ) -> None:
        if (
            type(timeout_seconds) not in (int, float)
            or not math.isfinite(timeout_seconds)
            or timeout_seconds <= 0
            or type(max_output_bytes) is not int
            or not 1 <= max_output_bytes <= _MAX_BYTES
        ):
            raise ValueError("partial_fill_cost_bridge_bounds_invalid")
        root = Path(__file__).resolve().parents[3]
        self._argv = (
            "php",
            str(root / "trading-app/bin/console"),
            "app:backtest:partial-fill-cost:settle",
            "--no-interaction",
            "--no-ansi",
        )
        self._timeout = float(timeout_seconds)
        self._max_output = max_output_bytes

    def settle(
        self,
        request: CanonicalPartialFillCostRequest,
    ) -> CanonicalPartialFillCostResult:
        if type(request) is not CanonicalPartialFillCostRequest:
            raise TypeError("canonical_partial_fill_cost_request_required")
        code, stdout = self._run(_ordered_json(request.wire()))
        if code != 0:
            raise PartialFillCostBridgeError("partial_fill_cost_bridge_process_failed")
        try:
            raw = json.loads(stdout, object_pairs_hook=self._unique)
            result = CanonicalPartialFillCostResult.model_validate(raw)
        except Exception as exc:
            raise PartialFillCostBridgeError("partial_fill_cost_bridge_result_invalid") from exc
        if not partial_fill_settlement_matches_request(result, request):
            raise PartialFillCostBridgeError("partial_fill_cost_bridge_identity_mismatch")
        return result

    @staticmethod
    def _matches_request(
        result: CanonicalPartialFillCostResult,
        request: CanonicalPartialFillCostRequest,
    ) -> bool:
        return partial_fill_settlement_matches_request(result, request)

    def _run(self, payload: bytes) -> tuple[int, bytes]:
        deadline = time.monotonic() + self._timeout
        try:
            process = subprocess.Popen(
                self._argv,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                shell=False,
            )
        except (OSError, ValueError) as exc:
            raise PartialFillCostBridgeError(
                "partial_fill_cost_bridge_process_unavailable"
            ) from exc
        assert process.stdin and process.stdout and process.stderr
        output = bytearray()
        overflow = threading.Event()

        def write_stdin() -> None:
            try:
                process.stdin.write(payload)
                process.stdin.close()
            except (BrokenPipeError, OSError, ValueError):
                pass

        def read_stdout() -> None:
            while chunk := process.stdout.read(65536):
                if len(output) + len(chunk) > self._max_output:
                    overflow.set()
                    return
                output.extend(chunk)

        def drain_stderr() -> None:
            total = 0
            while chunk := process.stderr.read(65536):
                total += len(chunk)
                if total > self._max_output:
                    overflow.set()
                    return

        threads = [
            threading.Thread(target=write_stdin, daemon=True),
            threading.Thread(target=read_stdout, daemon=True),
            threading.Thread(target=drain_stderr, daemon=True),
        ]
        for thread in threads:
            thread.start()
        try:
            while process.poll() is None:
                if overflow.is_set():
                    raise PartialFillCostBridgeError(
                        "partial_fill_cost_bridge_output_too_large"
                    )
                if time.monotonic() >= deadline:
                    raise PartialFillCostBridgeError("partial_fill_cost_bridge_timeout")
                time.sleep(0.005)
            for thread in threads:
                thread.join(max(0.0, deadline - time.monotonic()))
            if overflow.is_set():
                raise PartialFillCostBridgeError(
                    "partial_fill_cost_bridge_output_too_large"
                )
            if any(thread.is_alive() for thread in threads):
                raise PartialFillCostBridgeError("partial_fill_cost_bridge_timeout")
            return process.returncode, bytes(output)
        except PartialFillCostBridgeError:
            if process.poll() is None:
                process.kill()
            process.wait()
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

    @staticmethod
    def _unique(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate")
            result[key] = value
        return result
