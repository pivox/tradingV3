from typing import Any

import pytest
from pydantic import ValidationError

from app.contracts import ExchangeRequest, ExchangeResponse


ACCOUNT = "0x1111111111111111111111111111111111111111"
AGENT = "0x2222222222222222222222222222222222222222"


def request_data(**overrides: Any) -> dict[str, Any]:
    data: dict[str, Any] = {
        "schema_version": "1",
        "environment": "testnet",
        "network": "testnet",
        "nonce": 1_700_000_000_000,
        "account_address": ACCOUNT,
        "agent_address": AGENT,
        "action": {"type": "order", "orders": []},
        "correlation_id": "corr-1",
    }
    data.update(overrides)
    return data


def response_data(**overrides: Any) -> dict[str, Any]:
    data: dict[str, Any] = {
        "schema_version": "1",
        "outcome": "accepted",
        "statuses": [{"kind": "resting", "oid": "42"}],
        "correlation_id": "corr-1",
    }
    data.update(overrides)
    return data


@pytest.mark.parametrize(
    "action_type", ["order", "cancel", "cancelByCloid", "updateLeverage"]
)
def test_request_accepts_exact_allowlisted_actions(action_type: str) -> None:
    request = ExchangeRequest(**request_data(action={"type": action_type}))

    assert request.action["type"] == action_type
    assert request.expires_after is None


@pytest.mark.parametrize(
    "action",
    [{}, {"type": ""}, {"type": "withdraw3"}, {"type": ["order"]}],
)
def test_request_rejects_blank_or_non_allowlisted_action(
    action: dict[str, Any],
) -> None:
    with pytest.raises(ValidationError, match="action_not_allowed"):
        ExchangeRequest(**request_data(action=action))


def test_request_rejects_oversized_action() -> None:
    action = {"type": "order", "payload": "x" * (64 * 1024)}

    with pytest.raises(ValidationError, match="action_too_large"):
        ExchangeRequest(**request_data(action=action))


@pytest.mark.parametrize("field", ["schema_version", "environment", "network"])
def test_request_rejects_wrong_literal(field: str) -> None:
    with pytest.raises(ValidationError):
        ExchangeRequest(**request_data(**{field: "mainnet"}))


@pytest.mark.parametrize("nonce", [0, -1])
def test_request_requires_positive_nonce(nonce: int) -> None:
    with pytest.raises(ValidationError):
        ExchangeRequest(**request_data(nonce=nonce))


@pytest.mark.parametrize("field", ["account_address", "agent_address"])
@pytest.mark.parametrize("address", ["0x1234", "not-an-address", "0X" + "1" * 40])
def test_request_requires_hex_addresses(field: str, address: str) -> None:
    with pytest.raises(ValidationError):
        ExchangeRequest(**request_data(**{field: address}))


@pytest.mark.parametrize("correlation_id", ["", "x" * 129])
def test_request_bounds_correlation_id(correlation_id: str) -> None:
    with pytest.raises(ValidationError):
        ExchangeRequest(**request_data(correlation_id=correlation_id))


@pytest.mark.parametrize("expires_after", [0, -1])
def test_request_requires_positive_expiry(expires_after: int) -> None:
    with pytest.raises(ValidationError):
        ExchangeRequest(**request_data(expires_after=expires_after))


def test_request_forbids_extra_fields() -> None:
    with pytest.raises(ValidationError):
        ExchangeRequest(**request_data(signature="forbidden"))


@pytest.mark.parametrize("outcome", ["accepted", "rejected", "ambiguous"])
def test_response_accepts_bounded_redacted_envelope(outcome: str) -> None:
    response = ExchangeResponse(
        **response_data(outcome=outcome, reason="exchange_rejected")
    )

    assert response.outcome == outcome
    assert response.reason == "exchange_rejected"


def test_response_allows_omitted_reason_and_empty_statuses() -> None:
    response = ExchangeResponse(**response_data(statuses=[]))

    assert response.reason is None
    assert response.statuses == []


@pytest.mark.parametrize("field", ["signature", "private_key", "agent_private_key"])
def test_response_forbids_sensitive_top_level_fields(field: str) -> None:
    with pytest.raises(ValidationError):
        ExchangeResponse(**response_data(**{field: "forbidden"}))


@pytest.mark.parametrize("field", ["signature", "privateKey", "agent_private_key"])
def test_response_forbids_sensitive_status_fields(field: str) -> None:
    with pytest.raises(ValidationError, match="sensitive_response_field"):
        ExchangeResponse(**response_data(statuses=[{"nested": {field: "forbidden"}}]))


def test_response_limits_status_rows_to_twenty() -> None:
    with pytest.raises(ValidationError):
        ExchangeResponse(**response_data(statuses=[{"kind": "ok"}] * 21))


@pytest.mark.parametrize("value", ["", "Upper_Case", "has-dash", "x" * 129])
def test_response_requires_optional_stable_reason(value: str) -> None:
    with pytest.raises(ValidationError):
        ExchangeResponse(**response_data(reason=value))


@pytest.mark.parametrize("correlation_id", ["", "x" * 129])
def test_response_bounds_correlation_id(correlation_id: str) -> None:
    with pytest.raises(ValidationError):
        ExchangeResponse(**response_data(correlation_id=correlation_id))


@pytest.mark.parametrize(
    ("field", "value"),
    [("schema_version", "2"), ("outcome", "unknown"), ("unexpected", True)],
)
def test_response_rejects_unknown_contract_values(field: str, value: Any) -> None:
    with pytest.raises(ValidationError):
        ExchangeResponse(**response_data(**{field: value}))
