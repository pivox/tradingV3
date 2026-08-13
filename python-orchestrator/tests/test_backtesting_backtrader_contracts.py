from __future__ import annotations

from copy import deepcopy
import hashlib
import json
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan, CanonicalBacktestPlan, _php_plan_hash


FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"


def _payload() -> dict:
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def _rehash(plan: dict) -> None:
    unsigned = {key: value for key, value in plan.items() if key != "planHash"}
    plan["planHash"] = _php_plan_hash(unsigned)


def test_php_golden_is_strict_frozen_and_hash_bound() -> None:
    payload = _payload()
    projected = CanonicalBacktestOrderPlan.model_validate(payload)

    assert projected.plan.plan_hash == payload["plan"]["planHash"]
    assert projected.dataset_id.endswith(projected.dataset_checksum.removeprefix("sha256:"))
    with pytest.raises(ValidationError):
        projected.timeframe = "1m"  # type: ignore[misc]


@pytest.mark.parametrize(
    "mutate",
    [
        lambda value: value.update(extra=True),
        lambda value: value["plan"].pop("stopPrice"),
        lambda value: value["plan"].update(exchange="okx"),
        lambda value: value["plan"].update(environment="mainnet"),
        lambda value: value["plan"].update(planHash="sha256:" + "f" * 64),
        lambda value: value["plan"].update(stopPrice=101.0),
        lambda value: value["plan"].update(zoneLowerPrice=100.2),
        lambda value: value["plan"]["targets"][0].update(price=98.0),
        lambda value: value["plan"].update(entryPrice=float("inf")),
        lambda value: value["plan"].update(inputHashes=[]),
        lambda value: value["plan"].update(planHash=1),
    ],
)
def test_rejects_incomplete_forged_or_non_finite_plan(mutate) -> None:
    payload = deepcopy(_payload())
    mutate(payload)

    with pytest.raises(ValidationError):
        CanonicalBacktestOrderPlan.model_validate(payload)


@pytest.mark.parametrize(
    "change",
    [
        {"quantity": 0.0},
        {"zoneLowerPrice": 101.0},
        {"stopPrice": 101.0},
        {"expiresAt": "2026-08-10T12:00:00.000000+00:00"},
        {"costInputHash": "sha256:" + "f" * 64},
        {"targets": []},
    ],
)
def test_rejects_rehashed_semantic_contract_breaches(change: dict) -> None:
    payload = _payload()
    payload["plan"].update(change)
    _rehash(payload["plan"])
    with pytest.raises(ValidationError):
        CanonicalBacktestOrderPlan.model_validate(payload)


def test_low_level_strict_validators_reject_coercions() -> None:
    for callback, value in (
        (CanonicalBacktestPlan._strings, 1),
        (CanonicalBacktestPlan._numbers, "1"),
        (CanonicalBacktestPlan._integers, True),
        (CanonicalBacktestPlan._times, "2026-08-10T12:00:00Z"),
        (CanonicalBacktestPlan._hashes, []),
        (CanonicalBacktestPlan._tuples, "not-a-list"),
    ):
        with pytest.raises(ValueError):
            callback(value)


def test_php_plan_hash_matches_preserve_zero_fraction_scientific_encoding() -> None:
    expected = "sha256:" + hashlib.sha256(b'{"small":1.0e-7,"one":1.0}').hexdigest()
    assert _php_plan_hash({"small": 1e-7, "one": 1.0}) == expected


def test_rejects_invalid_target_id_and_dataset_binding() -> None:
    payload = _payload()
    payload["plan"]["targets"][0]["id"] = ""
    _rehash(payload["plan"])
    with pytest.raises(ValidationError):
        CanonicalBacktestOrderPlan.model_validate(payload)

    payload = _payload()
    payload["dataset_id"] = "backtest-dataset-" + "b" * 64
    with pytest.raises(ValidationError, match="dataset_invalid"):
        CanonicalBacktestOrderPlan.model_validate(payload)
