"""Strict contracts for deterministic Backtrader execution evidence."""

from __future__ import annotations

import hashlib
import json
import math
import re
from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.modern_trading_contracts import ModernTradingIdentity, _encode_php_float


_HASH = r"^sha256:[0-9a-f]{64}$"
_DATASET_ID = r"^backtest-dataset-[0-9a-f]{64}$"
_TIME = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$")


def _finite(value: Any) -> float:
    if type(value) not in (int, float) or not math.isfinite(value):
        raise ValueError("canonical_backtest_plan_number_invalid")
    return float(value)


def _parse_time(value: str) -> datetime:
    if type(value) is not str or _TIME.fullmatch(value) is None:
        raise ValueError("canonical_backtest_plan_time_invalid")
    try:
        return datetime.fromisoformat(value)
    except ValueError as exc:
        raise ValueError("canonical_backtest_plan_time_invalid") from exc


def _php_plan_hash(payload: dict[str, Any]) -> str:
    normalized: dict[str, Any] = {}
    for key, value in payload.items():
        normalized[key] = value
        if key == "expiresAt":
            normalized.setdefault("cancelAfterAt", None)
            normalized.setdefault("holdingExpiresAt", None)
    encoded = _encode_php_plan_value(normalized).encode()
    return "sha256:" + hashlib.sha256(encoded).hexdigest()


def _encode_php_plan_value(value: Any) -> str:
    """Match CanonicalOrderPlan's ordered PHP JSON encoding exactly."""

    if isinstance(value, dict):
        return "{" + ",".join(
            json.dumps(key, ensure_ascii=True, separators=(",", ":"))
            + ":"
            + _encode_php_plan_value(item)
            for key, item in value.items()
        ) + "}"
    if isinstance(value, (list, tuple)):
        return "[" + ",".join(_encode_php_plan_value(item) for item in value) + "]"
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        if not math.isfinite(value):
            raise ValueError("canonical_backtest_plan_number_invalid")
        return _encode_php_float(value)
    if isinstance(value, str):
        return json.dumps(value, ensure_ascii=True, separators=(",", ":"))
    raise ValueError("canonical_backtest_plan_hash_value_invalid")


class CanonicalBacktestOrderPlanTarget(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    id: str
    price: float
    risk_multiple: float = Field(alias="riskMultiple")
    liquidity_role: Literal["maker", "taker"] = Field(alias="liquidityRole")
    spread_rate: float = Field(alias="spreadRate")
    slippage_rate: float = Field(alias="slippageRate")
    gross_reward: float = Field(alias="grossReward")
    entry_fee: float = Field(alias="entryFee")
    target_fee: float = Field(alias="targetFee")
    entry_spread_cost: float = Field(alias="entrySpreadCost")
    entry_slippage_cost: float = Field(alias="entrySlippageCost")
    target_spread_cost: float = Field(alias="targetSpreadCost")
    target_slippage_cost: float = Field(alias="targetSlippageCost")
    funding_cost: float = Field(alias="fundingCost")
    net_reward: float = Field(alias="netReward")
    net_risk: float = Field(alias="netRisk")
    net_r: float = Field(alias="netR")

    @field_validator("id", mode="before")
    @classmethod
    def _strict_id(cls, value: Any) -> Any:
        if type(value) is not str or not value:
            raise ValueError("canonical_backtest_plan_target_invalid")
        return value

    @field_validator(
        "price", "risk_multiple", "spread_rate", "slippage_rate", "gross_reward",
        "entry_fee", "target_fee", "entry_spread_cost", "entry_slippage_cost",
        "target_spread_cost", "target_slippage_cost", "funding_cost", "net_reward",
        "net_risk", "net_r", mode="before",
    )
    @classmethod
    def _numbers(cls, value: Any) -> float:
        return _finite(value)


class CanonicalBacktestPlan(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    mode_id: str = Field(alias="modeId")
    mode_version: str = Field(alias="modeVersion")
    setup_id: str = Field(alias="setupId")
    setup_version: str = Field(alias="setupVersion")
    exchange: Literal["fake"]
    environment: Literal["local", "test"]
    symbol: str
    market_type: Literal["perpetual"] = Field(alias="marketType")
    quote_currency: str = Field(alias="quoteCurrency")
    side: Literal["long", "short"]
    order_type: Literal["limit"] = Field(alias="orderType")
    market_fallback: bool | None = Field(alias="marketFallback", default=None)
    quantity: float
    quantity_step: float = Field(alias="quantityStep")
    contract_size: float = Field(alias="contractSize")
    entry_price: float = Field(alias="entryPrice")
    stop_price: float = Field(alias="stopPrice")
    tick_size: float = Field(alias="tickSize")
    zone_lower_price: float = Field(alias="zoneLowerPrice")
    zone_upper_price: float = Field(alias="zoneUpperPrice")
    targets: tuple[CanonicalBacktestOrderPlanTarget, ...]
    minimum_net_r: float = Field(alias="minimumNetR")
    equity_quote: float = Field(alias="equityQuote")
    available_balance_quote: float = Field(alias="availableBalanceQuote")
    risk_rate: float = Field(alias="riskRate")
    risk_budget_quote: float = Field(alias="riskBudgetQuote")
    gross_stop_loss: float = Field(alias="grossStopLoss")
    total_stop_loss: float = Field(alias="totalStopLoss")
    position_notional: float = Field(alias="positionNotional")
    final_leverage: int = Field(alias="finalLeverage")
    effective_leverage_cap: int = Field(alias="effectiveLeverageCap")
    mode_leverage_cap: float = Field(alias="modeLeverageCap")
    exchange_leverage_cap: float = Field(alias="exchangeLeverageCap")
    symbol_leverage_cap: float | None = Field(alias="symbolLeverageCap", default=None)
    min_quantity: float = Field(alias="minQuantity")
    max_quantity: float = Field(alias="maxQuantity")
    market_max_quantity: float | None = Field(alias="marketMaxQuantity", default=None)
    exchange_min_notional: float = Field(alias="exchangeMinNotional")
    exchange_max_notional: float = Field(alias="exchangeMaxNotional")
    environment_max_notional: float = Field(alias="environmentMaxNotional")
    caps_applied: tuple[str, ...] = Field(alias="capsApplied")
    maker_fee_rate: float = Field(alias="makerFeeRate")
    taker_fee_rate: float = Field(alias="takerFeeRate")
    entry_liquidity_role: Literal["maker", "taker"] = Field(alias="entryLiquidityRole")
    stop_liquidity_role: Literal["maker", "taker"] = Field(alias="stopLiquidityRole")
    entry_spread_rate: float = Field(alias="entrySpreadRate")
    stop_spread_rate: float = Field(alias="stopSpreadRate")
    entry_slippage_rate: float = Field(alias="entrySlippageRate")
    stop_slippage_rate: float = Field(alias="stopSlippageRate")
    funding_rate: float = Field(alias="fundingRate")
    entry_fee: float = Field(alias="entryFee")
    stop_exit_fee: float = Field(alias="stopExitFee")
    entry_spread_cost: float = Field(alias="entrySpreadCost")
    stop_spread_cost: float = Field(alias="stopSpreadCost")
    entry_slippage_cost: float = Field(alias="entrySlippageCost")
    stop_slippage_cost: float = Field(alias="stopSlippageCost")
    funding_cost: float = Field(alias="fundingCost")
    funding_intervals: int = Field(alias="fundingIntervals")
    maximum_input_age_seconds: int = Field(alias="maximumInputAgeSeconds")
    input_observed_at: str = Field(alias="inputObservedAt")
    observed_at: str = Field(alias="observedAt")
    cost_observed_at: str = Field(alias="costObservedAt")
    zone_computed_at: str = Field(alias="zoneComputedAt")
    created_at: str = Field(alias="createdAt")
    expires_at: str = Field(alias="expiresAt")
    cancel_after_at: str | None = Field(alias="cancelAfterAt", default=None)
    holding_expires_at: str | None = Field(alias="holdingExpiresAt", default=None)
    config_hash: str = Field(alias="configHash", pattern=_HASH)
    cost_input_hash: str = Field(alias="costInputHash", pattern=_HASH)
    order_book_input_hash: str | None = Field(alias="orderBookInputHash", pattern=_HASH, default=None)
    input_hashes: tuple[str, ...] = Field(alias="inputHashes", min_length=1)
    plan_hash: str = Field(alias="planHash", pattern=_HASH)

    @model_validator(mode="before")
    @classmethod
    def _verify_raw_php_hash(cls, value: Any) -> Any:
        if not isinstance(value, dict) or type(value.get("planHash")) is not str:
            raise ValueError("canonical_backtest_plan_hash_invalid")
        unsigned = {key: item for key, item in value.items() if key != "planHash"}
        if _php_plan_hash(unsigned) != value["planHash"]:
            raise ValueError("canonical_backtest_plan_hash_mismatch")
        return value

    @field_validator(
        "mode_id", "mode_version", "setup_id", "setup_version", "symbol",
        "quote_currency", "config_hash", "cost_input_hash", "order_book_input_hash",
        "plan_hash", mode="before",
    )
    @classmethod
    def _strings(cls, value: Any) -> Any:
        if value is not None and type(value) is not str:
            raise ValueError("canonical_backtest_plan_string_invalid")
        return value

    @field_validator(
        "quantity", "quantity_step", "contract_size", "entry_price", "stop_price",
        "tick_size", "zone_lower_price", "zone_upper_price", "minimum_net_r",
        "equity_quote", "available_balance_quote", "risk_rate", "risk_budget_quote",
        "gross_stop_loss", "total_stop_loss", "position_notional", "mode_leverage_cap",
        "exchange_leverage_cap", "symbol_leverage_cap", "min_quantity", "max_quantity",
        "market_max_quantity", "exchange_min_notional", "exchange_max_notional",
        "environment_max_notional", "maker_fee_rate", "taker_fee_rate",
        "entry_spread_rate", "stop_spread_rate", "entry_slippage_rate",
        "stop_slippage_rate", "funding_rate", "entry_fee", "stop_exit_fee",
        "entry_spread_cost", "stop_spread_cost", "entry_slippage_cost",
        "stop_slippage_cost", "funding_cost", mode="before",
    )
    @classmethod
    def _numbers(cls, value: Any) -> Any:
        if value is None:
            return None
        return _finite(value)

    @field_validator("final_leverage", "effective_leverage_cap", "funding_intervals", "maximum_input_age_seconds", mode="before")
    @classmethod
    def _integers(cls, value: Any) -> Any:
        if type(value) is not int:
            raise ValueError("canonical_backtest_plan_integer_invalid")
        return value

    @field_validator("input_observed_at", "observed_at", "cost_observed_at", "zone_computed_at", "created_at", "expires_at", "cancel_after_at", "holding_expires_at", mode="before")
    @classmethod
    def _times(cls, value: Any) -> Any:
        if value is not None:
            _parse_time(value)
        return value

    @field_validator("input_hashes", mode="before")
    @classmethod
    def _hashes(cls, value: Any) -> Any:
        if not isinstance(value, (list, tuple)) or not value or any(
            type(item) is not str or re.fullmatch(_HASH, item) is None for item in value
        ) or len(set(value)) != len(value):
            raise ValueError("canonical_backtest_plan_input_hashes_invalid")
        return tuple(value)

    @field_validator("targets", "caps_applied", mode="before")
    @classmethod
    def _tuples(cls, value: Any) -> Any:
        if not isinstance(value, (list, tuple)):
            raise ValueError("canonical_backtest_plan_sequence_invalid")
        return tuple(value)

    @model_validator(mode="after")
    def _validate_contract(self) -> "CanonicalBacktestPlan":
        ModernTradingIdentity(
            mode_id=self.mode_id,
            mode_version=self.mode_version,
            setup_id=self.setup_id,
            setup_version=self.setup_version,
            exchange=self.exchange,
            environment=self.environment,
            side=self.side,
        )
        positive = (
            self.quantity, self.quantity_step, self.contract_size, self.entry_price,
            self.stop_price, self.tick_size, self.risk_budget_quote,
            self.position_notional, self.min_quantity, self.max_quantity,
        )
        if any(value <= 0 for value in positive):
            raise ValueError("canonical_backtest_plan_value_invalid")
        if not self.zone_lower_price <= self.entry_price <= self.zone_upper_price:
            raise ValueError("canonical_backtest_plan_entry_outside_zone")
        if (self.side == "long" and self.stop_price >= self.entry_price) or (
            self.side == "short" and self.stop_price <= self.entry_price
        ):
            raise ValueError("canonical_backtest_plan_stop_invalid")
        if not self.targets or any(
            (self.side == "long" and item.price <= self.entry_price)
            or (self.side == "short" and item.price >= self.entry_price)
            for item in self.targets
        ):
            raise ValueError("canonical_backtest_plan_target_invalid")
        created = _parse_time(self.created_at)
        expires = _parse_time(self.expires_at)
        if expires <= created or any(
            _parse_time(value) < created or _parse_time(value) > expires
            for value in (self.cancel_after_at,)
            if value is not None
        ):
            raise ValueError("canonical_backtest_plan_deadline_invalid")
        if self.cost_input_hash not in self.input_hashes or (
            self.order_book_input_hash is not None
            and self.order_book_input_hash not in self.input_hashes
        ):
            raise ValueError("canonical_backtest_plan_lineage_invalid")
        return self


class CanonicalBacktestOrderPlan(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    schema_version: Literal[
        "canonical-backtest-order-plan.v1", "canonical-backtest-order-plan.v2"
    ]
    dataset_id: str = Field(pattern=_DATASET_ID)
    dataset_checksum: str = Field(pattern=_HASH)
    timeframe: Literal["1m", "5m", "15m", "1h", "4h"]
    plan: CanonicalBacktestPlan

    @field_validator("schema_version", "dataset_id", "dataset_checksum", "timeframe", mode="before")
    @classmethod
    def _strings(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("canonical_backtest_order_plan_string_invalid")
        return value

    @model_validator(mode="after")
    def _bind_dataset(self) -> "CanonicalBacktestOrderPlan":
        if self.dataset_id != "backtest-dataset-" + self.dataset_checksum.removeprefix("sha256:"):
            raise ValueError("canonical_backtest_order_plan_dataset_invalid")
        if (
            self.schema_version == "canonical-backtest-order-plan.v1"
            and self.plan.market_fallback is not None
        ) or (
            self.schema_version == "canonical-backtest-order-plan.v2"
            and self.plan.market_fallback is not False
        ):
            raise ValueError("canonical_backtest_order_plan_fallback_policy_invalid")
        return self
