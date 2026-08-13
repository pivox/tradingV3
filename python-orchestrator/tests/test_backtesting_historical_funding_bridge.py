from datetime import datetime, timedelta, timezone
from decimal import Decimal
import json
from pathlib import Path
import sys

import pytest

from app.backtesting.historical_funding_bridge import (
    CanonicalHistoricalFundingRequest,
    HistoricalFundingBridge,
    HistoricalFundingBridgeError,
)


UTC = timezone.utc
FIXTURES = Path(__file__).parent / "fixtures/backtesting"


def _bridge(argv: tuple[str, ...], **bounds) -> HistoricalFundingBridge:
    bridge = HistoricalFundingBridge(**bounds)
    bridge._argv = argv
    return bridge


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


def test_child_process_bridge_returns_hash_bound_settlement_without_php_runtime(tmp_path) -> None:
    authority = tmp_path / "authority.py"
    authority.write_text(
        "import hashlib,json,sys\n"
        "r=json.load(sys.stdin)\n"
        "o={'schema_version':'canonical-historical-funding-result.v1',"
        "'dataset_id':r['dataset_id'],'dataset_checksum':r['dataset_checksum'],"
        "'schedule_checksum':r['schedule_checksum'],'plan_hash':r['plan_hash'],"
        "'config_hash':r['config_hash'],'cost_input_hash':r['cost_input_hash'],"
        "'symbol':r['symbol'],'side':r['side'],'quantity':r['quantity'],"
        "'contract_size':r['contract_size'],'entry_at':r['entry_at'],'exit_at':r['exit_at'],"
        "'applied_source_record_ids':['funding-1'],'applied_record_count':1,"
        "'funding_cashflow_quote':'-0.01','request_hash':'sha256:'+hashlib.sha256("
        "json.dumps(r,separators=(',',':')).encode()).hexdigest()}\n"
        "o['result_hash']='sha256:'+hashlib.sha256(json.dumps(o,separators=(',',':')).encode()).hexdigest()\n"
        "print(json.dumps(o,separators=(',',':')))\n"
    )
    result = _bridge((sys.executable, str(authority))).settle(_request())
    assert result.funding_cashflow_quote == "-0.01"
    assert result.applied_source_record_ids == ("funding-1",)
    assert result.dataset_id == _request().dataset_id


def test_bridge_rejects_child_failure_and_forged_result(tmp_path) -> None:
    failing = tmp_path / "fail.py"
    failing.write_text("import sys; sys.exit(2)\n")
    with pytest.raises(HistoricalFundingBridgeError, match="process_failed"):
        _bridge((sys.executable, str(failing))).settle(_request())

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
        _bridge((sys.executable, str(forged))).settle(_request())


def test_bridge_is_bounded(tmp_path) -> None:
    sleeper = tmp_path / "sleep.py"
    sleeper.write_text("import time; time.sleep(10)\n")
    with pytest.raises(HistoricalFundingBridgeError, match="timeout"):
        _bridge((sys.executable, str(sleeper)), timeout_seconds=0.02).settle(_request())

    noisy = tmp_path / "noisy.py"
    noisy.write_text("print('x'*10000)\n")
    with pytest.raises(HistoricalFundingBridgeError, match="output_too_large"):
        _bridge((sys.executable, str(noisy)), max_output_bytes=128).settle(_request())


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


def test_bridge_contract_guards_reject_invalid_types_times_and_identity() -> None:
    from app.backtesting.historical_funding_bridge import CanonicalHistoricalFundingResult
    payload = _request().model_dump()
    payload["records"] = "invalid"
    with pytest.raises(ValueError, match="records_invalid"):
        CanonicalHistoricalFundingRequest.model_validate(payload)
    payload = _request().model_dump()
    payload["records"] = payload["records"] * 10_001
    with pytest.raises(ValueError):
        CanonicalHistoricalFundingRequest.model_validate(payload)
    for field in ("entry_at", "exit_at"):
        payload = dict(_request().__dict__)
        payload[field] = datetime(2026, 8, 10)
        with pytest.raises(ValueError, match="time_invalid"):
            CanonicalHistoricalFundingRequest.model_validate(payload)
    payload = dict(_request().__dict__)
    payload["dataset_id"] = "backtest-dataset-" + "f" * 64
    with pytest.raises(ValueError, match="dataset_invalid"):
        CanonicalHistoricalFundingRequest.model_validate(payload)

    result = json.loads((FIXTURES / "php-historical-funding-settlement.json").read_bytes())
    result["applied_source_record_ids"] = "invalid"
    with pytest.raises(ValueError, match="result_ids_invalid"):
        CanonicalHistoricalFundingResult.model_validate(result)
    for value in ("2026-08-10T12:02:00Z", "2026-99-99T12:02:00.000000Z"):
        result = json.loads((FIXTURES / "php-historical-funding-settlement.json").read_bytes())
        result["exit_at"] = value
        with pytest.raises(ValueError):
            CanonicalHistoricalFundingResult.model_validate(result)


def test_bridge_constructor_and_process_guards(tmp_path) -> None:
    for bounds in ({"timeout_seconds": 0}, {"max_output_bytes": 0}):
        with pytest.raises(ValueError):
            HistoricalFundingBridge(**bounds)
    with pytest.raises(TypeError, match="request_required"):
        HistoricalFundingBridge().settle(object())  # type: ignore[arg-type]
    with pytest.raises(HistoricalFundingBridgeError, match="process_unavailable"):
        _bridge((str(tmp_path / "missing-command"),)).settle(_request())

    duplicate = tmp_path / "duplicate.py"
    duplicate.write_text("print('{\"a\":1,\"a\":2}')\n")
    with pytest.raises(HistoricalFundingBridgeError, match="result_invalid"):
        _bridge((sys.executable, str(duplicate))).settle(_request())


def test_result_count_and_hash_must_reconcile() -> None:
    from app.backtesting.historical_funding_bridge import CanonicalHistoricalFundingResult, _ordered_json
    import hashlib
    for change in ({"applied_record_count": 0}, {"result_hash": "sha256:" + "f" * 64}):
        payload = json.loads((FIXTURES / "php-historical-funding-settlement.json").read_bytes())
        payload.update(change)
        if "result_hash" not in change:
            payload["result_hash"] = "sha256:" + hashlib.sha256(
                _ordered_json({key: value for key, value in payload.items() if key != "result_hash"})
            ).hexdigest()
        with pytest.raises(ValueError, match="result_hash_invalid"):
            CanonicalHistoricalFundingResult.model_validate(payload)


def test_bridge_rejects_valid_but_wrong_result_identity(tmp_path) -> None:
    script = tmp_path / "wrong_identity.py"
    script.write_text(
        "import hashlib,json,sys\n"
        "r=json.load(sys.stdin)\n"
        "o={'schema_version':'canonical-historical-funding-result.v1',"
        "'dataset_id':r['dataset_id'],'dataset_checksum':r['dataset_checksum'],"
        "'schedule_checksum':r['schedule_checksum'],'plan_hash':r['plan_hash'],"
        "'config_hash':r['config_hash'],'cost_input_hash':r['cost_input_hash'],"
        "'symbol':'ETHUSDT','side':r['side'],'quantity':r['quantity'],"
        "'contract_size':r['contract_size'],'entry_at':r['entry_at'],'exit_at':r['exit_at'],"
        "'applied_source_record_ids':[],'applied_record_count':0,"
        "'funding_cashflow_quote':'0','request_hash':'sha256:'+hashlib.sha256("
        "json.dumps(r,separators=(',',':')).encode()).hexdigest()}\n"
        "o['result_hash']='sha256:'+hashlib.sha256(json.dumps(o,separators=(',',':')).encode()).hexdigest()\n"
        "print(json.dumps(o,separators=(',',':')))\n"
    )
    with pytest.raises(HistoricalFundingBridgeError, match="identity_mismatch"):
        _bridge((sys.executable, str(script))).settle(_request())


def test_bridge_bounds_stderr_and_rejects_empty_stdout(tmp_path) -> None:
    noisy = tmp_path / "noisy-stderr.py"
    noisy.write_text("import sys; sys.stderr.write('x'*10000)\n")
    with pytest.raises(HistoricalFundingBridgeError, match="output_too_large"):
        _bridge((sys.executable, str(noisy)), max_output_bytes=128).settle(_request())

    empty = tmp_path / "empty.py"
    empty.write_text("pass\n")
    with pytest.raises(HistoricalFundingBridgeError, match="result_invalid"):
        _bridge((sys.executable, str(empty))).settle(_request())


def test_bridge_timeout_covers_a_child_that_never_reads_stdin(tmp_path) -> None:
    sleeper = tmp_path / "never-read.py"
    sleeper.write_text("import time; time.sleep(10)\n")
    request = _request().model_copy(update={
        "records": tuple(_request().records[0] for _ in range(8000)),
    })
    with pytest.raises(HistoricalFundingBridgeError, match="timeout"):
        _bridge((sys.executable, str(sleeper)), timeout_seconds=0.02).settle(request)


def test_decimal_and_request_matching_helpers_cover_both_branches() -> None:
    from app.backtesting.historical_funding_bridge import (
        CanonicalHistoricalFundingResult,
        _decimal,
        _decimal_string,
        settlement_matches_request,
    )
    with pytest.raises(ValueError, match="decimal_invalid"):
        _decimal("-0")
    assert _decimal_string(Decimal("1.2500")) == "1.25"
    assert _decimal_string(2) == "2"
    result = CanonicalHistoricalFundingResult.model_validate_json(
        (FIXTURES / "php-historical-funding-settlement.json").read_bytes()
    )
    assert not settlement_matches_request(result, _request())
