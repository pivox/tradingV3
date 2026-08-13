from __future__ import annotations

from copy import deepcopy
import json
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan


FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"


def _payload() -> dict:
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


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
    ],
)
def test_rejects_incomplete_forged_or_non_finite_plan(mutate) -> None:
    payload = deepcopy(_payload())
    mutate(payload)

    with pytest.raises(ValidationError):
        CanonicalBacktestOrderPlan.model_validate(payload)
