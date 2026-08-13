"""Test doubles for funding protocol consumers, without a PHP runtime dependency."""

from __future__ import annotations

import hashlib
import json

from app.backtesting.historical_funding_bridge import (
    CanonicalHistoricalFundingRequest,
    CanonicalHistoricalFundingResult,
    HistoricalFundingBridge,
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


def trusted_bridge_for(
    *,
    cashflow: str = "-0.02497",
    applied_ids: tuple[str, ...] = ("funding-2",),
) -> HistoricalFundingBridge:
    """Patch only transport I/O while preserving the exact trusted bridge type."""
    bridge = HistoricalFundingBridge()

    def run(payload: bytes) -> tuple[int, bytes]:
        request = json.loads(payload)
        result = {
            "schema_version": "canonical-historical-funding-result.v1",
            **{
                key: request[key]
                for key in (
                    "dataset_id", "dataset_checksum", "schedule_checksum", "plan_hash",
                    "config_hash", "cost_input_hash", "symbol", "side", "quantity",
                    "contract_size", "entry_at", "exit_at",
                )
            },
            "applied_source_record_ids": list(applied_ids),
            "applied_record_count": len(applied_ids),
            "funding_cashflow_quote": cashflow,
            "request_hash": "sha256:" + hashlib.sha256(payload).hexdigest(),
        }
        result["result_hash"] = "sha256:" + hashlib.sha256(_ordered_json(result)).hexdigest()
        return 0, _ordered_json(result)

    bridge._run = run  # type: ignore[method-assign]
    return bridge
