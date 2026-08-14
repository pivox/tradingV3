"""Backtrader time adapter for canonical single-plan execution."""

from __future__ import annotations

import hashlib
import json
import math
from datetime import datetime, timezone
from decimal import Decimal
from typing import Any

import backtrader as bt

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_execution import (
    BacktestExecutionResult,
    execute_plan,
    execute_plan_from_visible_fill,
)
from app.backtesting.backtrader_feed import VerifiedBacktraderBar, VerifiedBacktraderFeedAdapter
from app.backtesting.backtrader_net_outcome import project_plan_bound_net_outcome
from app.backtesting.historical_funding import VerifiedHistoricalFundingSchedule
from app.backtesting.historical_funding_bridge import (
    HistoricalFundingBridge,
    canonical_historical_funding_request,
)
from app.backtesting.partial_fill_cost_bridge import (
    CanonicalPartialFillCostResult,
    PartialFillCostBridge,
    canonical_partial_fill_cost_request,
)
from app.backtesting.partial_fill_net_outcome import project_partial_fill_net_outcome
from app.backtesting.staged_fill_execution import (
    execute_plan_from_staged_visible_fills,
)
from app.backtesting.visible_queue_depletion import (
    VisibleQueueDepletionResult,
    requires_partial_fill_authority,
)


_ENGINE_VERSION = "backtrader-1.9.78.123+canonical-runtime.v1"
_VISIBLE_FILL_ENGINE_VERSION = "backtrader-1.9.78.123+canonical-runtime.v2"
_STAGED_FILL_ENGINE_VERSION = "backtrader-1.9.78.123+canonical-runtime.v3"


def _canonical(value: Any) -> str:
    if isinstance(value, dict):
        return "{" + ",".join(
            json.dumps(key, ensure_ascii=False, separators=(",", ":"))
            + ":"
            + _canonical(value[key])
            for key in sorted(value)
        ) + "}"
    if isinstance(value, (list, tuple)):
        return "[" + ",".join(_canonical(item) for item in value) + "]"
    if isinstance(value, Decimal):
        if not value.is_finite():
            raise ValueError("backtrader_runtime_number_out_of_range")
        return str(value)
    return json.dumps(value, ensure_ascii=False, allow_nan=False, separators=(",", ":"))


def _hash(value: Any) -> str:
    return "sha256:" + hashlib.sha256(_canonical(value).encode()).hexdigest()


class _VerifiedBars(bt.feed.DataBase):
    params = (("bars", ()),)

    def start(self) -> None:
        super().start()
        self._index = 0

    def _load(self) -> bool:
        if self._index >= len(self.p.bars):
            return False
        bar = self.p.bars[self._index]
        self.lines.datetime[0] = bt.date2num(bar.available_at.astimezone(timezone.utc))
        self.lines.open[0] = _backtrader_float(bar.open)
        self.lines.high[0] = _backtrader_float(bar.high)
        self.lines.low[0] = _backtrader_float(bar.low)
        self.lines.close[0] = _backtrader_float(bar.close)
        self.lines.volume[0] = _backtrader_float(bar.volume)
        self.lines.openinterest[0] = 0.0
        self._index += 1
        return True


class CanonicalBacktraderRuntime:
    def run(
        self,
        plan: CanonicalBacktestOrderPlan,
        feed: VerifiedBacktraderFeedAdapter,
        *,
        funding_schedule: VerifiedHistoricalFundingSchedule | None = None,
        funding_bridge: HistoricalFundingBridge | None = None,
        maker_fill_evidence: VisibleQueueDepletionResult | None = None,
        partial_fill_cost_bridge: PartialFillCostBridge | None = None,
    ) -> str:
        wire_plan = plan.model_dump(mode="json", by_alias=True)
        for optional_key in (
            "marketFallback", "cancelAfterAt", "holdingExpiresAt", "orderBookInputHash",
        ):
            if wire_plan["plan"].get(optional_key) is None:
                wire_plan["plan"].pop(optional_key)
        plan = CanonicalBacktestOrderPlan.model_validate_json(
            json.dumps(
                wire_plan,
                ensure_ascii=False,
                allow_nan=False,
                separators=(",", ":"),
            )
        )
        if (
            plan.dataset_id != feed.dataset_id
            or plan.dataset_checksum != feed.dataset_checksum
            or plan.timeframe != feed.timeframe
            or plan.plan.symbol != feed.symbol
            or plan.plan.market_type != feed.market_type
        ):
            raise ValueError("backtrader_runtime_identity_mismatch")
        uses_visible_fill = plan.schema_version == "canonical-backtest-order-plan.v2"
        if uses_visible_fill and maker_fill_evidence is None:
            raise ValueError("backtrader_runtime_visible_fill_evidence_required")
        if not uses_visible_fill and maker_fill_evidence is not None:
            raise ValueError("backtrader_runtime_visible_fill_evidence_forbidden")
        uses_staged_fill = False
        if maker_fill_evidence is not None:
            maker_fill_evidence = _validated_visible_fill_evidence(
                plan, feed, maker_fill_evidence
            )
            uses_staged_fill = requires_partial_fill_authority(maker_fill_evidence)
            if uses_staged_fill and partial_fill_cost_bridge is None:
                raise ValueError(
                    "backtrader_runtime_partial_fill_cost_authority_missing"
                )
            if uses_staged_fill and type(partial_fill_cost_bridge) is not PartialFillCostBridge:
                raise ValueError(
                    "backtrader_runtime_partial_fill_cost_authority_invalid"
                )
        if not uses_staged_fill and partial_fill_cost_bridge is not None:
            raise ValueError(
                "backtrader_runtime_partial_fill_cost_authority_forbidden"
            )
        if uses_staged_fill and (
            funding_schedule is not None or funding_bridge is not None
        ):
            raise ValueError(
                "backtrader_runtime_staged_historical_funding_forbidden"
            )

        # Cerebro remains the deterministic temporal driver. The strategy only
        # records delivery order; all execution semantics live in the pure state
        # machine and all trading authorities stay in the PHP plan.
        delivered: list[int] = []

        class DeliveryStrategy(bt.Strategy):
            def next(self) -> None:
                delivered.append(len(self) - 1)

        cerebro = bt.Cerebro(stdstats=False, maxcpus=1)
        cerebro.adddata(_VerifiedBars(bars=feed.bars))
        cerebro.addstrategy(DeliveryStrategy)
        cerebro.run(runonce=False, preload=False, exactbars=True)
        if delivered != list(range(len(feed.bars))):
            raise ValueError("backtrader_runtime_delivery_invalid")

        delivered_bars = tuple(feed.bars[index] for index in delivered)
        if maker_fill_evidence is None:
            outcome = execute_plan(plan, delivered_bars)
        elif maker_fill_evidence.status == "unfilled":
            outcome = BacktestExecutionResult(
                "not_executed", "visible_queue_unfilled", ()
            )
        elif uses_staged_fill:
            outcome = execute_plan_from_staged_visible_fills(
                plan, delivered_bars, maker_fill_evidence
            )
        else:
            outcome = execute_plan_from_visible_fill(
                plan, delivered_bars, maker_fill_evidence
            )
        partial_cost_settlement: CanonicalPartialFillCostResult | None = None
        if uses_staged_fill and outcome.status == "closed":
            assert partial_fill_cost_bridge is not None
            partial_cost_settlement = partial_fill_cost_bridge.settle(
                canonical_partial_fill_cost_request(
                    plan, maker_fill_evidence, outcome
                )
            )
        funding_settlement = None
        if funding_schedule is not None or funding_bridge is not None:
            if funding_schedule is None or funding_bridge is None:
                raise ValueError("backtrader_runtime_historical_funding_evidence_required")
            if type(funding_bridge) is not HistoricalFundingBridge:
                raise ValueError("backtrader_runtime_historical_funding_authority_invalid")
            try:
                funding_schedule = VerifiedHistoricalFundingSchedule(funding_schedule.artifacts)
            except Exception as exc:
                raise ValueError("backtrader_runtime_historical_funding_schedule_binding_invalid") from exc
            if (
                funding_schedule.dataset_id != feed.dataset_id
                or funding_schedule.dataset_checksum != feed.dataset_checksum
                or funding_schedule.source_network != feed.source_network
                or funding_schedule.market_data_venue != feed.market_data_venue
                or funding_schedule.market_type != feed.market_type
                or funding_schedule.symbol != feed.symbol
            ):
                raise ValueError("backtrader_runtime_historical_funding_schedule_binding_invalid")
            if outcome.status == "closed":
                funding_settlement = funding_bridge.settle(
                    canonical_historical_funding_request(plan, outcome, funding_schedule)
                )
        if outcome.status != "closed":
            net_outcome = None
        elif uses_staged_fill:
            assert maker_fill_evidence is not None
            assert partial_cost_settlement is not None
            net_outcome = json.loads(
                project_partial_fill_net_outcome(
                    plan,
                    outcome,
                    feed,
                    maker_fill_evidence,
                    partial_cost_settlement,
                ),
                parse_float=Decimal,
                parse_int=Decimal,
            )
        else:
            net_outcome = json.loads(
                project_plan_bound_net_outcome(
                    plan,
                    outcome,
                    feed,
                    funding_schedule=funding_schedule,
                    funding_settlement=funding_settlement,
                    maker_fill_evidence=maker_fill_evidence,
                ),
                parse_float=Decimal,
                parse_int=Decimal,
            )
        events = [
            {
                "config_hash": event.config_hash,
                "dataset_id": event.dataset_id,
                "happened_at": event.happened_at.isoformat(timespec="microseconds").replace("+00:00", "Z"),
                "kind": event.kind,
                "plan_hash": event.plan_hash,
                "price": event.price,
                "quantity": event.quantity,
                **(
                    {"quantity_base": event.quantity_base}
                    if event.quantity_base is not None
                    else {}
                ),
                "source_record_id": event.source_record_id,
                "stop_price": event.stop_price,
            }
            for event in outcome.events
        ]
        engine_version = (
            _STAGED_FILL_ENGINE_VERSION
            if uses_staged_fill
            else _VISIBLE_FILL_ENGINE_VERSION
            if uses_visible_fill
            else _ENGINE_VERSION
        )
        input_payload = {
            "dataset_checksum": feed.dataset_checksum,
            "dataset_id": feed.dataset_id,
            "engine_version": engine_version,
            "plan_hash": plan.plan.plan_hash,
            "source_record_ids": [bar.source_record_id for bar in feed.bars],
            "timeframe": feed.timeframe,
            **({"funding_schedule_checksum": funding_schedule.schedule_checksum} if funding_schedule is not None else {}),
            **(
                {"maker_fill_result_hash": maker_fill_evidence.result_hash}
                if maker_fill_evidence is not None
                else {}
            ),
            **(
                {
                    "partial_fill_cost_request_hash": partial_cost_settlement.request_hash,
                    "partial_fill_cost_result_hash": partial_cost_settlement.result_hash,
                }
                if partial_cost_settlement is not None
                else {}
            ),
        }
        result = {
            "engine_version": engine_version,
            "events": events,
            "input_hash": _hash(input_payload),
            "net_outcome": net_outcome,
            **(
                {
                    "cancelled_residual_quantity_base": outcome.cancelled_residual_quantity_base,
                    "consumed_fill_count": outcome.consumed_fill_count,
                    "filled_quantity_base": outcome.filled_quantity_base,
                    "partial_fill_cost_request_hash": partial_cost_settlement.request_hash,
                    "partial_fill_cost_result_hash": partial_cost_settlement.result_hash,
                }
                if partial_cost_settlement is not None
                else {}
            ),
            **({"funding_schedule_checksum": funding_schedule.schedule_checksum} if funding_schedule is not None else {}),
            **(
                {
                    "fills_are_certified": False,
                    "maker_fill_policy_version": maker_fill_evidence.policy_version,
                    "maker_fill_result_hash": maker_fill_evidence.result_hash,
                    "public_book_tape_checksum": maker_fill_evidence.public_book_tape_checksum,
                    "public_execution_tape_checksum": maker_fill_evidence.public_execution_tape_checksum,
                    "quantity_conversion_tape_checksum": maker_fill_evidence.quantity_conversion_tape_checksum,
                }
                if maker_fill_evidence is not None
                else {}
            ),
            "reason_code": outcome.reason_code,
            "result_is_live_proof": False,
            "schema_version": (
                "canonical-backtrader-result.v3"
                if uses_staged_fill
                else "canonical-backtrader-result.v2"
                if uses_visible_fill
                else "canonical-backtrader-result.v1"
            ),
            "status": outcome.status,
        }
        result["result_hash"] = _hash(result)
        return _canonical(result) + "\n"


def _backtrader_float(value: Any) -> float:
    converted = float(value)
    if not math.isfinite(converted):
        raise ValueError("backtrader_runtime_number_out_of_range")
    return converted


def _validated_visible_fill_evidence(
    plan: CanonicalBacktestOrderPlan,
    feed: VerifiedBacktraderFeedAdapter,
    evidence: VisibleQueueDepletionResult,
) -> VisibleQueueDepletionResult:
    try:
        evidence = VisibleQueueDepletionResult.model_validate(
            evidence.model_dump(mode="json")
        )
    except Exception as exc:
        raise ValueError("backtrader_runtime_visible_fill_evidence_invalid") from exc
    deadline = min(
        datetime.fromisoformat(plan.plan.expires_at),
        datetime.fromisoformat(plan.plan.cancel_after_at)
        if plan.plan.cancel_after_at is not None
        else datetime.fromisoformat(plan.plan.expires_at),
    )
    if (
        evidence.dataset_id != plan.dataset_id
        or evidence.dataset_checksum != plan.dataset_checksum
        or evidence.plan_hash != plan.plan.plan_hash
        or evidence.config_hash != plan.plan.config_hash
        or evidence.source_network != feed.source_network
        or evidence.market_data_venue != feed.market_data_venue
        or evidence.market_type != plan.plan.market_type
        or evidence.symbol != plan.plan.symbol
        or evidence.side != plan.plan.side
        or Decimal(evidence.entry_price) != Decimal(str(plan.plan.entry_price))
        or datetime.fromisoformat(evidence.order_live_at)
        != datetime.fromisoformat(plan.plan.created_at)
        or datetime.fromisoformat(evidence.effective_deadline_at) != deadline
        or Decimal(evidence.order_quantity_base)
        != Decimal(str(plan.plan.quantity)) * Decimal(str(plan.plan.contract_size))
    ):
        raise ValueError("backtrader_runtime_visible_fill_evidence_invalid")
    return evidence
