from __future__ import annotations

from copy import deepcopy
from dataclasses import replace
from decimal import Decimal
import hashlib
import json
from pathlib import Path
import sys

import pytest
from pydantic import ValidationError

from app.backtesting.backtrader_contracts import CanonicalBacktestPlan, _php_plan_hash
from app.backtesting.partial_fill_cost_bridge import (
    CanonicalPartialFillCostRequest,
    CanonicalPartialFillCostResult,
    PartialFillCostBridge,
    PartialFillCostBridgeError,
    _ordered_json,
    canonical_partial_fill_cost_request,
)
from app.backtesting.staged_fill_execution import execute_plan_from_staged_visible_fills
from tests.test_backtesting_backtrader_runtime import _feed, _v2_plan
from tests.test_backtesting_staged_fill_execution import _evidence


FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"


def _plan_payload() -> dict:
    payload = json.loads(FIXTURE.read_bytes())
    original = payload["plan"]
    plan = {}
    for key, value in original.items():
        plan[key] = value
        if key == "orderType":
            plan["marketFallback"] = False
    plan["planHash"] = _php_plan_hash(
        {key: value for key, value in plan.items() if key != "planHash"}
    )
    return plan


def _request_payload() -> dict:
    return {
        "schema_version": "canonical-partial-fill-cost-request.v1",
        "dataset_id": "backtest-dataset-" + "a" * 64,
        "dataset_checksum": "sha256:" + "a" * 64,
        "plan": _plan_payload(),
        "maker_fill_result_hash": "sha256:" + "b" * 64,
        "maker_fill_trace_hash": "sha256:" + "c" * 64,
        "filled_quantity_base": "1",
        "terminal_kind": "target_filled",
        "target_id": "tp1",
    }


def _request() -> CanonicalPartialFillCostRequest:
    return CanonicalPartialFillCostRequest.model_validate(_request_payload())


def _result_payload(request: CanonicalPartialFillCostRequest) -> dict:
    plan = request.plan
    result = {
        "schema_version": "canonical-partial-fill-cost-result.v1",
        "cost_policy_version": "canonical-plan-partial-quantity.v1",
        "cost_evidence": "canonical_plan_partial_quantity",
        "costs_are_certified": False,
        "dataset_id": request.dataset_id,
        "dataset_checksum": request.dataset_checksum,
        "plan_hash": plan.plan_hash,
        "config_hash": plan.config_hash,
        "cost_input_hash": plan.cost_input_hash,
        "maker_fill_result_hash": request.maker_fill_result_hash,
        "maker_fill_trace_hash": request.maker_fill_trace_hash,
        "mode_id": plan.mode_id,
        "mode_version": plan.mode_version,
        "setup_id": plan.setup_id,
        "setup_version": plan.setup_version,
        "symbol": plan.symbol,
        "market_type": plan.market_type,
        "side": plan.side,
        "planned_quantity_base": "2.497",
        "filled_quantity_base": "1",
        "remaining_quantity_base": "1.497",
        "is_partial_fill": True,
        "terminal_kind": request.terminal_kind,
        "target_id": request.target_id,
        "gross_pnl_quote": "2.5",
        "entry_fee_quote": "0.05005",
        "exit_fee_quote": "0.0513",
        "entry_spread_cost_quote": "0.01001",
        "exit_spread_cost_quote": "0.01026",
        "entry_slippage_cost_quote": "0.01001",
        "exit_slippage_cost_quote": "0.01026",
        "planned_adverse_funding_cost_quote": "0.01001",
        "total_planned_cost_quote": "0.1519",
        "gross_stop_risk_quote": "1.7",
        "total_stop_risk_quote": "1.84896",
        "net_pnl_quote": "2.3481",
        "net_r": "1.2699571651090342",
        "result_is_live_proof": False,
        "request_hash": request.request_hash(),
    }
    result["result_hash"] = "sha256:" + hashlib.sha256(_ordered_json(result)).hexdigest()
    return result


def _bridge(argv: tuple[str, ...], **bounds) -> PartialFillCostBridge:
    bridge = PartialFillCostBridge(**bounds)
    bridge._argv = argv
    return bridge


def _authority_script(path: Path, *, mutation: str = "") -> None:
    path.write_text(
        "import hashlib,json,sys\n"
        "r=json.load(sys.stdin)\n"
        "p=r['plan']\n"
        "o={'schema_version':'canonical-partial-fill-cost-result.v1',"
        "'cost_policy_version':'canonical-plan-partial-quantity.v1',"
        "'cost_evidence':'canonical_plan_partial_quantity','costs_are_certified':False,"
        "'dataset_id':r['dataset_id'],'dataset_checksum':r['dataset_checksum'],"
        "'plan_hash':p['planHash'],'config_hash':p['configHash'],"
        "'cost_input_hash':p['costInputHash'],"
        "'maker_fill_result_hash':r['maker_fill_result_hash'],"
        "'maker_fill_trace_hash':r['maker_fill_trace_hash'],"
        "'mode_id':p['modeId'],'mode_version':p['modeVersion'],"
        "'setup_id':p['setupId'],'setup_version':p['setupVersion'],"
        "'symbol':p['symbol'],'market_type':p['marketType'],'side':p['side'],"
        "'planned_quantity_base':'2.497','filled_quantity_base':r['filled_quantity_base'],"
        "'remaining_quantity_base':'1.497','is_partial_fill':True,"
        "'terminal_kind':r['terminal_kind'],'target_id':r['target_id'],"
        "'gross_pnl_quote':'2.5','entry_fee_quote':'0.05005',"
        "'exit_fee_quote':'0.0513','entry_spread_cost_quote':'0.01001',"
        "'exit_spread_cost_quote':'0.01026','entry_slippage_cost_quote':'0.01001',"
        "'exit_slippage_cost_quote':'0.01026',"
        "'planned_adverse_funding_cost_quote':'0.01001',"
        "'total_planned_cost_quote':'0.1519','gross_stop_risk_quote':'1.7',"
        "'total_stop_risk_quote':'1.84896','net_pnl_quote':'2.3481',"
        "'net_r':'1.2699571651090342','result_is_live_proof':False,"
        "'request_hash':'sha256:'+hashlib.sha256(json.dumps(r,separators=(',',':')).encode()).hexdigest()}\n"
        + mutation
        + "o['result_hash']='sha256:'+hashlib.sha256(json.dumps(o,separators=(',',':')).encode()).hexdigest()\n"
        "print(json.dumps(o,separators=(',',':')))\n",
        encoding="utf-8",
    )


def test_request_is_strict_hash_bound_v2_and_uses_php_field_order() -> None:
    request = _request()

    assert request.plan.market_fallback is False
    assert list(request.wire()) == [
        "schema_version", "dataset_id", "dataset_checksum", "plan",
        "maker_fill_result_hash", "maker_fill_trace_hash",
        "filled_quantity_base", "terminal_kind", "target_id",
    ]
    assert "cancelAfterAt" not in request.wire()["plan"]
    assert "holdingExpiresAt" not in request.wire()["plan"]
    assert "orderBookInputHash" not in request.wire()["plan"]
    assert request.request_hash() == "sha256:" + hashlib.sha256(
        _ordered_json(request.wire())
    ).hexdigest()

    for mutation in (
        lambda value: value["plan"].pop("marketFallback"),
        lambda value: value["plan"].update(marketFallback=True),
        lambda value: value.update(dataset_id="backtest-dataset-" + "f" * 64),
        lambda value: value.update(filled_quantity_base="1.0"),
        lambda value: value.update(filled_quantity_base="0"),
        lambda value: value.update(filled_quantity_base="3"),
        lambda value: value.update(target_id=None),
        lambda value: value.update(target_id="missing"),
        lambda value: value.update(target_id=1),
        lambda value: value.update(extra=True),
    ):
        payload = _request_payload()
        mutation(payload)
        if "plan" in payload:
            payload["plan"]["planHash"] = _php_plan_hash(
                {key: item for key, item in payload["plan"].items() if key != "planHash"}
            )
        with pytest.raises(ValidationError):
            CanonicalPartialFillCostRequest.model_validate(payload)


def test_ordered_json_matches_php_float_encoding() -> None:
    assert _ordered_json({"small": 1e-7, "one": 1.0}) == b'{"small":1.0e-7,"one":1.0}'


def test_php_generated_result_fixture_is_strict_hash_valid_and_request_bound() -> None:
    result = CanonicalPartialFillCostResult.model_validate_json(
        (FIXTURE.parent / "php-partial-fill-cost-settlement.json").read_bytes()
    )

    assert result.request_hash == _request().request_hash()
    assert result.plan_hash == _request().plan.plan_hash
    assert result.net_pnl_quote == "2.3481"


def test_result_reconciles_hash_quantity_costs_and_terminal_semantics() -> None:
    request = _request()
    result = CanonicalPartialFillCostResult.model_validate(_result_payload(request))

    assert result.net_pnl_quote == "2.3481"
    assert result.remaining_quantity_base == "1.497"

    for change in (
        {"result_hash": "sha256:" + "f" * 64},
        {"remaining_quantity_base": "1.5"},
        {"is_partial_fill": False},
        {"net_pnl_quote": "2"},
        {"target_id": None},
        {"target_id": ""},
        {"gross_pnl_quote": "2.500"},
        {"entry_fee_quote": "-0"},
        {"entry_fee_quote": "-1"},
        {"dataset_id": "backtest-dataset-" + "f" * 64},
    ):
        payload = _result_payload(request)
        payload.update(change)
        if "result_hash" not in change:
            payload["result_hash"] = "sha256:" + hashlib.sha256(
                _ordered_json({key: value for key, value in payload.items() if key != "result_hash"})
            ).hexdigest()
        with pytest.raises(ValidationError):
            CanonicalPartialFillCostResult.model_validate(payload)


def test_stop_result_requires_exact_stop_reconciliation() -> None:
    request = CanonicalPartialFillCostRequest.model_validate(
        {**_request_payload(), "terminal_kind": "stop_filled", "target_id": None}
    )
    payload = _result_payload(request)
    payload.update(
        gross_pnl_quote="-1.7",
        net_pnl_quote="-1.84896",
        net_r="-1",
    )
    payload["result_hash"] = "sha256:" + hashlib.sha256(
        _ordered_json({key: value for key, value in payload.items() if key != "result_hash"})
    ).hexdigest()

    assert CanonicalPartialFillCostResult.model_validate(payload).net_r == "-1"

    payload["gross_pnl_quote"] = "-1.6"
    payload["result_hash"] = "sha256:" + hashlib.sha256(
        _ordered_json({key: value for key, value in payload.items() if key != "result_hash"})
    ).hexdigest()
    with pytest.raises(ValidationError):
        CanonicalPartialFillCostResult.model_validate(payload)


def test_child_process_bridge_returns_identity_bound_result(tmp_path: Path) -> None:
    authority = tmp_path / "authority.py"
    _authority_script(authority)

    result = _bridge((sys.executable, str(authority))).settle(_request())

    assert result.plan_hash == _request().plan.plan_hash
    assert result.filled_quantity_base == "1"


def test_bridge_rejects_child_failure_forgery_and_duplicate_json(tmp_path: Path) -> None:
    failing = tmp_path / "fail.py"
    failing.write_text("import sys; sys.exit(2)\n", encoding="utf-8")
    with pytest.raises(PartialFillCostBridgeError, match="process_failed"):
        _bridge((sys.executable, str(failing))).settle(_request())

    forged = tmp_path / "forged.py"
    _authority_script(forged, mutation="o['symbol']='ETHUSDT'\n")
    with pytest.raises(PartialFillCostBridgeError, match="identity_mismatch"):
        _bridge((sys.executable, str(forged))).settle(_request())

    duplicate = tmp_path / "duplicate.py"
    duplicate.write_text('print(\'{"a":1,"a":2}\')\n', encoding="utf-8")
    with pytest.raises(PartialFillCostBridgeError, match="result_invalid"):
        _bridge((sys.executable, str(duplicate))).settle(_request())


def test_bridge_enforces_process_and_output_bounds(tmp_path: Path) -> None:
    sleeper = tmp_path / "sleep.py"
    sleeper.write_text("import time; time.sleep(10)\n", encoding="utf-8")
    with pytest.raises(PartialFillCostBridgeError, match="timeout"):
        _bridge((sys.executable, str(sleeper)), timeout_seconds=0.02).settle(_request())

    for name, source in (
        ("stdout.py", "print('x'*10000)\n"),
        ("stderr.py", "import sys; sys.stderr.write('x'*10000)\n"),
    ):
        noisy = tmp_path / name
        noisy.write_text(source, encoding="utf-8")
        with pytest.raises(PartialFillCostBridgeError, match="output_too_large"):
            _bridge((sys.executable, str(noisy)), max_output_bytes=128).settle(_request())

    with pytest.raises(PartialFillCostBridgeError, match="process_unavailable"):
        _bridge((str(tmp_path / "missing"),)).settle(_request())


def test_bridge_rejects_invalid_constructor_and_request_types() -> None:
    for bounds in (
        {"timeout_seconds": 0},
        {"timeout_seconds": float("nan")},
        {"max_output_bytes": 0},
        {"max_output_bytes": 1024 * 1024 + 1},
    ):
        with pytest.raises(ValueError, match="bounds_invalid"):
            PartialFillCostBridge(**bounds)
    with pytest.raises(TypeError, match="request_required"):
        PartialFillCostBridge().settle(object())  # type: ignore[arg-type]


def test_bridge_rejects_every_result_identity_substitution(tmp_path: Path) -> None:
    mutations = (
        "o['mode_id']='scalping'\n",
        "o['mode_version']='9.9.9'\n",
        "o['setup_id']='forged'\n",
        "o['setup_version']='9.9.9'\n",
        "o['market_type']='spot'\n",
        "o['side']='short'\n",
    )
    for index, mutation in enumerate(mutations):
        forged = tmp_path / f"forged-{index}.py"
        _authority_script(forged, mutation=mutation)
        with pytest.raises(PartialFillCostBridgeError, match="identity_mismatch"):
            _bridge((sys.executable, str(forged))).settle(_request())


def test_result_requires_positive_planned_and_filled_quantities() -> None:
    request = _request()
    for change in (
        {"planned_quantity_base": "0", "remaining_quantity_base": "-1"},
        {"filled_quantity_base": "0", "remaining_quantity_base": "2.497"},
    ):
        payload = deepcopy(_result_payload(request))
        payload.update(change)
        payload["result_hash"] = "sha256:" + hashlib.sha256(
            _ordered_json({key: value for key, value in payload.items() if key != "result_hash"})
        ).hexdigest()
        with pytest.raises(ValidationError):
            CanonicalPartialFillCostResult.model_validate(payload)


def test_request_builder_binds_consumed_partial_prefix_and_terminal_branch() -> None:
    feed = _feed()
    plan = _v2_plan(feed)
    evidence = _evidence(plan, (("a", 0, 45, Decimal("1")),))
    execution = execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)

    request = canonical_partial_fill_cost_request(plan, evidence, execution)

    assert request.filled_quantity_base == "1"
    assert request.maker_fill_result_hash == evidence.result_hash
    assert request.maker_fill_trace_hash == evidence.trace_hash
    assert request.terminal_kind == "target_filled"
    assert request.target_id == "tp1"


def test_request_builder_uses_only_prefix_exposed_before_stop() -> None:
    feed = _feed(fill_bar_stop=True)
    plan = _v2_plan(feed)
    evidence = _evidence(
        plan,
        (("a", 0, 30, Decimal("1")), ("c", 1, 30, Decimal("1.497"))),
    )
    execution = execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)

    request = canonical_partial_fill_cost_request(plan, evidence, execution)

    assert request.filled_quantity_base == "1"
    assert request.terminal_kind == "stop_filled"
    assert request.target_id is None


def test_request_builder_rejects_forged_fill_prefix() -> None:
    feed = _feed()
    plan = _v2_plan(feed)
    evidence = _evidence(plan, (("a", 0, 45, Decimal("1")),))
    execution = execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)
    fill, terminal = execution.events

    for forged_fill in (
        replace(fill, source_record_id="f" * 64),
        replace(fill, happened_at=terminal.happened_at),
        replace(fill, quantity_base=Decimal("0.5")),
    ):
        with pytest.raises(ValueError, match="execution_invalid"):
            canonical_partial_fill_cost_request(
                plan,
                evidence,
                replace(execution, events=(forged_fill, terminal)),
            )
