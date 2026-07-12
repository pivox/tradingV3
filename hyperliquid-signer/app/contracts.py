import json
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator


ALLOWED_ACTIONS = frozenset(
    {"order", "cancel", "cancelByCloid", "updateLeverage"}
)
MAX_ACTION_BYTES = 64 * 1024
ADDRESS_PATTERN = r"^0x[0-9a-fA-F]{40}$"
STABLE_REASON_PATTERN = r"^[a-z][a-z0-9_]{0,127}$"


class ExchangeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    schema_version: Literal["1"]
    environment: Literal["testnet"]
    network: Literal["testnet"]
    nonce: int = Field(gt=0)
    account_address: str = Field(pattern=ADDRESS_PATTERN)
    agent_address: str = Field(pattern=ADDRESS_PATTERN)
    action: dict[str, Any]
    correlation_id: str = Field(min_length=1, max_length=128)
    expires_after: int | None = Field(default=None, gt=0)

    @model_validator(mode="after")
    def validate_action(self) -> "ExchangeRequest":
        action_type = self.action.get("type")
        if not isinstance(action_type, str) or action_type not in ALLOWED_ACTIONS:
            raise ValueError("action_not_allowed")
        try:
            encoded = json.dumps(
                self.action,
                ensure_ascii=True,
                separators=(",", ":"),
                sort_keys=True,
            ).encode("ascii")
        except (TypeError, ValueError) as error:
            raise ValueError("action_not_json_serializable") from error
        if len(encoded) > MAX_ACTION_BYTES:
            raise ValueError("action_too_large")
        return self


class ExchangeResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    schema_version: Literal["1"]
    outcome: Literal["accepted", "rejected", "ambiguous"]
    statuses: list[dict[str, Any]] = Field(max_length=20)
    reason: str | None = Field(
        default=None,
        pattern=STABLE_REASON_PATTERN,
        max_length=128,
    )
    correlation_id: str = Field(min_length=1, max_length=128)

    @model_validator(mode="after")
    def reject_sensitive_status_fields(self) -> "ExchangeResponse":
        if _contains_sensitive_field(self.statuses):
            raise ValueError("sensitive_response_field")
        return self


def _contains_sensitive_field(value: Any) -> bool:
    if isinstance(value, dict):
        for key, child in value.items():
            normalized_key = "".join(
                character for character in key.lower() if character.isalnum()
            )
            if normalized_key == "signature" or normalized_key.endswith("privatekey"):
                return True
            if _contains_sensitive_field(child):
                return True
        return False
    if isinstance(value, list):
        return any(_contains_sensitive_field(child) for child in value)
    return False
