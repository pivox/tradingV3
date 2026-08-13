"""Backtrader time adapter for canonical single-plan execution."""

from __future__ import annotations

import hashlib
import json
import math
from datetime import timezone
from decimal import Decimal
from typing import Any

import backtrader as bt

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_execution import execute_plan
from app.backtesting.backtrader_feed import VerifiedBacktraderBar, VerifiedBacktraderFeedAdapter
from app.backtesting.backtrader_net_outcome import project_plan_bound_net_outcome
from app.backtesting.historical_funding import VerifiedHistoricalFundingSchedule
from app.backtesting.historical_funding_bridge import (
    HistoricalFundingBridge,
    canonical_historical_funding_request,
)


_ENGINE_VERSION = "backtrader-1.9.78.123+canonical-runtime.v1"


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
    ) -> str:
        wire_plan = plan.model_dump(mode="json", by_alias=True)
        for optional_key in ("cancelAfterAt", "holdingExpiresAt", "orderBookInputHash"):
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

        outcome = execute_plan(plan, tuple(feed.bars[index] for index in delivered))
        funding_settlement = None
        if funding_schedule is not None or funding_bridge is not None:
            if funding_schedule is None or funding_bridge is None:
                raise ValueError("backtrader_runtime_historical_funding_evidence_required")
            if type(funding_bridge) is not HistoricalFundingBridge:
                raise ValueError("backtrader_runtime_historical_funding_authority_invalid")
            if outcome.status == "closed":
                funding_settlement = funding_bridge.settle(
                    canonical_historical_funding_request(plan, outcome, funding_schedule)
                )
        net_outcome = (
            json.loads(
                project_plan_bound_net_outcome(
                    plan,
                    outcome,
                    feed,
                    funding_schedule=funding_schedule,
                    funding_settlement=funding_settlement,
                ),
                parse_float=Decimal,
                parse_int=Decimal,
            )
            if outcome.status == "closed"
            else None
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
                "source_record_id": event.source_record_id,
                "stop_price": event.stop_price,
            }
            for event in outcome.events
        ]
        input_payload = {
            "dataset_checksum": feed.dataset_checksum,
            "dataset_id": feed.dataset_id,
            "engine_version": _ENGINE_VERSION,
            "plan_hash": plan.plan.plan_hash,
            "source_record_ids": [bar.source_record_id for bar in feed.bars],
            "timeframe": feed.timeframe,
            **({"funding_schedule_checksum": funding_schedule.schedule_checksum} if funding_schedule is not None else {}),
        }
        result = {
            "engine_version": _ENGINE_VERSION,
            "events": events,
            "input_hash": _hash(input_payload),
            "net_outcome": net_outcome,
            **({"funding_schedule_checksum": funding_schedule.schedule_checksum} if funding_schedule is not None else {}),
            "reason_code": outcome.reason_code,
            "result_is_live_proof": False,
            "schema_version": "canonical-backtrader-result.v1",
            "status": outcome.status,
        }
        result["result_hash"] = _hash(result)
        return _canonical(result) + "\n"


def _backtrader_float(value: Any) -> float:
    converted = float(value)
    if not math.isfinite(converted):
        raise ValueError("backtrader_runtime_number_out_of_range")
    return converted
