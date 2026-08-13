"""Test doubles for funding protocol consumers, without a PHP runtime dependency."""

from __future__ import annotations

import hashlib

from app.backtesting.historical_funding_bridge import (
    CanonicalHistoricalFundingRequest,
    CanonicalHistoricalFundingResult,
    _ordered_json,
)


def settlement_for(
    request: CanonicalHistoricalFundingRequest,
    *,
    cashflow: str = "-0.02497",
    applied_ids: tuple[str, ...] = ("funding-2",),
) -> CanonicalHistoricalFundingResult:
    """Return integrity-valid authority evidence without reproducing settlement math."""
    request_wire = request.wire()
    payload = {
        "schema_version": "canonical-historical-funding-result.v1",
        "dataset_id": request.dataset_id,
        "dataset_checksum": request.dataset_checksum,
        "schedule_checksum": request.schedule_checksum,
        "plan_hash": request.plan_hash,
        "config_hash": request.config_hash,
        "cost_input_hash": request.cost_input_hash,
        "symbol": request.symbol,
        "side": request.side,
        "quantity": request.quantity,
        "contract_size": request.contract_size,
        "entry_at": request_wire["entry_at"],
        "exit_at": request_wire["exit_at"],
        "applied_source_record_ids": list(applied_ids),
        "applied_record_count": len(applied_ids),
        "funding_cashflow_quote": cashflow,
        "request_hash": request.request_hash(),
    }
    payload["result_hash"] = "sha256:" + hashlib.sha256(_ordered_json(payload)).hexdigest()
    return CanonicalHistoricalFundingResult.model_validate(payload)


class DeterministicHistoricalFundingBridge:
    """Protocol-compatible test bridge whose evidence is bound to each request."""

    def __init__(
        self,
        *,
        cashflow: str = "-0.02497",
        applied_ids: tuple[str, ...] = ("funding-2",),
    ) -> None:
        self._cashflow = cashflow
        self._applied_ids = applied_ids

    def settle(self, request: CanonicalHistoricalFundingRequest) -> CanonicalHistoricalFundingResult:
        return settlement_for(
            request,
            cashflow=self._cashflow,
            applied_ids=self._applied_ids,
        )
