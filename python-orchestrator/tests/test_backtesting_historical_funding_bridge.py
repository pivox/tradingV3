from datetime import datetime, timezone
import json
from pathlib import Path

import pytest

from app.backtesting.historical_funding_bridge import (
    CanonicalHistoricalFundingRequest,
    HistoricalFundingBridge,
    HistoricalFundingBridgeError,
)


UTC = timezone.utc
FIXTURES = Path(__file__).parent / "fixtures/backtesting"


def _request() -> CanonicalHistoricalFundingRequest:
    return CanonicalHistoricalFundingRequest.model_validate({
        "schema_version": "canonical-historical-funding-request.v1",
        "dataset_id": "backtest-dataset-" + "a" * 64,
        "dataset_checksum": "sha256:" + "a" * 64,
        "schedule_checksum": "sha256:" + "b" * 64,
        "plan_hash": "sha256:" + "c" * 64,
        "config_hash": "sha256:" + "d" * 64,
        "cost_input_hash": "sha256:" + "e" * 64,
        "symbol": "BTCUSDT", "side": "long", "quantity": "1", "contract_size": "1",
        "entry_at": datetime(2026, 8, 10, 7, tzinfo=UTC),
        "exit_at": datetime(2026, 8, 10, 8, tzinfo=UTC),
        "coverage_start": datetime(2026, 8, 10, 0, tzinfo=UTC),
        "coverage_end": datetime(2026, 8, 10, 8, tzinfo=UTC),
        "records": [{
            "source_record_id": "funding-1", "funding_at": datetime(2026, 8, 10, 8, tzinfo=UTC),
            "available_at": datetime(2026, 8, 10, 8, tzinfo=UTC), "funding_rate": "0.0001",
            "mark_price": "100", "interval_seconds": 28800,
        }],
    })


def test_real_php_bridge_returns_hash_bound_settlement() -> None:
    result = HistoricalFundingBridge().settle(_request())
    assert result.funding_cashflow_quote == "-0.01"
    assert result.applied_source_record_ids == ("funding-1",)
    assert result.dataset_id == _request().dataset_id


def test_bridge_rejects_child_failure_and_forged_result(tmp_path) -> None:
    failing = tmp_path / "fail.py"
    failing.write_text("import sys; sys.exit(2)\n")
    with pytest.raises(HistoricalFundingBridgeError, match="process_failed"):
        HistoricalFundingBridge(("python3", str(failing))).settle(_request())

    forged = tmp_path / "forged.py"
    forged.write_text(
        "import json,sys\n"
        "r=json.load(sys.stdin)\n"
        "print(json.dumps({'schema_version':'canonical-historical-funding-result.v1',"
        "'dataset_id':r['dataset_id'],'dataset_checksum':r['dataset_checksum'],"
        "'schedule_checksum':r['schedule_checksum'],'plan_hash':r['plan_hash'],"
        "'config_hash':r['config_hash'],'cost_input_hash':r['cost_input_hash'],"
        "'symbol':r['symbol'],'side':r['side'],'quantity':r['quantity'],"
        "'contract_size':r['contract_size'],'entry_at':r['entry_at'],'exit_at':r['exit_at'],"
        "'applied_source_record_ids':[],'applied_record_count':0,"
        "'funding_cashflow_quote':'0','request_hash':'sha256:'+'f'*64,'result_hash':'sha256:'+'f'*64}))\n"
    )
    with pytest.raises(HistoricalFundingBridgeError, match="result_invalid"):
        HistoricalFundingBridge(("python3", str(forged))).settle(_request())


def test_bridge_is_bounded(tmp_path) -> None:
    sleeper = tmp_path / "sleep.py"
    sleeper.write_text("import time; time.sleep(10)\n")
    with pytest.raises(HistoricalFundingBridgeError, match="timeout"):
        HistoricalFundingBridge(("python3", str(sleeper)), timeout_seconds=0.02).settle(_request())

    noisy = tmp_path / "noisy.py"
    noisy.write_text("print('x'*10000)\n")
    with pytest.raises(HistoricalFundingBridgeError, match="output_too_large"):
        HistoricalFundingBridge(("python3", str(noisy)), max_output_bytes=128).settle(_request())


def test_php_generated_settlement_fixture_is_strict_and_hash_valid() -> None:
    from app.backtesting.historical_funding_bridge import CanonicalHistoricalFundingResult
    result = CanonicalHistoricalFundingResult.model_validate_json(
        (FIXTURES / "php-historical-funding-settlement.json").read_bytes()
    )
    assert result.funding_cashflow_quote == "-0.02497"
    assert result.applied_source_record_ids == ("golden-funding-2",)


@pytest.mark.parametrize(
    ("field", "value"),
    (("quantity", "1e0"), ("contract_size", "1.0")),
)
def test_request_rejects_noncanonical_positive_decimals(field: str, value: str) -> None:
    payload = _request().model_dump()
    payload[field] = value
    with pytest.raises(ValueError):
        CanonicalHistoricalFundingRequest.model_validate(payload)


def test_result_rejects_noncanonical_cashflow_even_with_rebound_hash() -> None:
    from app.backtesting.historical_funding_bridge import CanonicalHistoricalFundingResult, _ordered_json
    import hashlib
    payload = json.loads((FIXTURES / "php-historical-funding-settlement.json").read_bytes())
    payload["funding_cashflow_quote"] = "-0.024970"
    payload["result_hash"] = "sha256:" + hashlib.sha256(
        _ordered_json({key: value for key, value in payload.items() if key != "result_hash"})
    ).hexdigest()
    with pytest.raises(ValueError):
        CanonicalHistoricalFundingResult.model_validate(payload)
