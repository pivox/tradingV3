import asyncio
import json
from decimal import Decimal, InvalidOperation
from typing import Any, Protocol

import httpx
from eth_account import Account
from eth_account.signers.local import LocalAccount
from hyperliquid.utils.signing import sign_l1_action
from pydantic import ValidationError

from app.config import SignerConfig, TESTNET_URI
from app.contracts import MAX_INT64, ExchangeRequest, ExchangeResponse


EXCHANGE_URL = TESTNET_URI + "/exchange"
HTTP_TIMEOUT = (5.0, 5.0)
MAX_RESPONSE_BYTES = 64 * 1024
MAX_STATUS_ROWS = 20
TOTAL_TIMEOUT_SECONDS = 5.0


class TransportError(Exception):
    pass


class Transport(Protocol):
    async def post_json(
        self,
        url: str,
        *,
        json_body: dict[str, Any],
        timeout: tuple[float, float],
    ) -> dict[str, Any]: ...


class RequestsTransport:
    def __init__(
        self,
        client: httpx.AsyncClient | None = None,
        total_timeout: float = TOTAL_TIMEOUT_SECONDS,
    ) -> None:
        self._client = client
        self._owns_client = client is None
        self._total_timeout = total_timeout

    async def post_json(
        self,
        url: str,
        *,
        json_body: dict[str, Any],
        timeout: tuple[float, float],
    ) -> dict[str, Any]:
        if url != EXCHANGE_URL:
            raise TransportError("testnet_endpoint_required")
        client = self._get_client()
        try:
            async with asyncio.timeout(self._total_timeout):
                async with client.stream(
                    "POST",
                    url,
                    json=json_body,
                    timeout=httpx.Timeout(
                        connect=timeout[0],
                        read=timeout[1],
                        write=timeout[1],
                        pool=timeout[0],
                    ),
                    follow_redirects=False,
                ) as response:
                    if 300 <= response.status_code < 400:
                        raise TransportError("exchange_redirect_rejected")
                    response.raise_for_status()
                    body = await _read_bounded_body(response)
        except (TimeoutError, httpx.TimeoutException) as error:
            raise TransportError("exchange_timeout") from error
        except httpx.HTTPError as error:
            raise TransportError("exchange_transport_error") from error

        try:
            payload = json.loads(body)
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise TransportError("exchange_response_invalid_json") from error
        if not isinstance(payload, dict):
            raise TransportError("exchange_response_not_object")
        return payload

    def _get_client(self) -> httpx.AsyncClient:
        if self._client is None:
            self._client = httpx.AsyncClient(follow_redirects=False)
        return self._client

    async def aclose(self) -> None:
        if self._owns_client and self._client is not None:
            await self._client.aclose()
            self._client = None


async def _read_bounded_body(response: httpx.Response) -> bytes:
    content_length = response.headers.get("Content-Length")
    if content_length is not None:
        try:
            if int(content_length) > MAX_RESPONSE_BYTES:
                raise TransportError("exchange_response_too_large")
        except ValueError as error:
            raise TransportError("exchange_response_invalid_length") from error

    body = bytearray()
    async for chunk in response.aiter_bytes(chunk_size=8192):
        if len(body) + len(chunk) > MAX_RESPONSE_BYTES:
            raise TransportError("exchange_response_too_large")
        body.extend(chunk)
    return bytes(body)


def _sign_l1_testnet_action(
    wallet: LocalAccount,
    action: dict[str, Any],
    *,
    nonce: int,
    expires_after: int | None,
) -> dict[str, Any]:
    return sign_l1_action(
        wallet,
        action,
        None,
        nonce,
        expires_after,
        False,
    )


class HyperliquidTestnetSigner:
    def __init__(self, config: SignerConfig, transport: Transport) -> None:
        self._config = config
        self._transport = transport
        self._wallet = Account.from_key(
            config.agent_private_key.get_secret_value()
        )
        if self._wallet.address.lower() != config.agent_address:
            raise ValueError("agent_private_key_address_mismatch")

    async def submit(self, request: ExchangeRequest) -> ExchangeResponse:
        if not self._config.broadcast_enabled:
            return _response(
                request,
                outcome="rejected",
                reason="broadcast_disabled",
            )
        if request.agent_address.lower() != self._config.agent_address:
            return _response(
                request,
                outcome="rejected",
                reason="agent_address_mismatch",
            )

        signature = _sign_l1_testnet_action(
            self._wallet,
            request.action,
            nonce=request.nonce,
            expires_after=request.expires_after,
        )
        body: dict[str, Any] = {
            "action": request.action,
            "nonce": request.nonce,
            "signature": signature,
            "vaultAddress": None,
        }
        if request.expires_after is not None:
            body["expiresAfter"] = request.expires_after

        try:
            payload = await self._transport.post_json(
                EXCHANGE_URL,
                json_body=body,
                timeout=HTTP_TIMEOUT,
            )
        except TransportError as error:
            return _response(
                request,
                outcome="ambiguous",
                reason=_transport_reason(error),
            )
        return _normalize_exchange_payload(payload, request)


def _normalize_exchange_payload(
    payload: dict[str, Any], request: ExchangeRequest
) -> ExchangeResponse:
    status = payload.get("status")
    if status is not None and not isinstance(status, str):
        return _response(
            request,
            outcome="ambiguous",
            reason="unknown_exchange_response",
        )
    if status in {"err", "error"} or "err" in payload or "error" in payload:
        return _response(
            request, outcome="rejected", reason="exchange_error"
        )
    if status != "ok":
        return _response(
            request,
            outcome="ambiguous",
            reason="unknown_exchange_response",
        )

    response = payload.get("response")
    action_type = request.action["type"]
    if action_type == "order":
        return _normalize_order_response(response, request)
    if action_type in {"cancel", "cancelByCloid"}:
        return _normalize_cancel_response(response, request)
    if action_type == "updateLeverage":
        return _normalize_default_response(response, request)
    return _response(
        request,
        outcome="ambiguous",
        reason="unknown_exchange_response",
    )


def _normalize_order_response(
    response: Any, request: ExchangeRequest
) -> ExchangeResponse:
    statuses = _extract_statuses(response, "order")
    if statuses is None:
        return _invalid_exchange_response(response, request, "order")
    if not statuses:
        return _response(
            request,
            outcome="ambiguous",
            reason="empty_exchange_statuses",
        )
    if len(statuses) > MAX_STATUS_ROWS:
        return _response(
            request,
            outcome="ambiguous",
            reason="too_many_exchange_statuses",
        )

    normalized: list[dict[str, Any]] = []
    kinds: set[str] = set()
    for row in statuses:
        normalized_row = _normalize_order_row(row)
        if normalized_row is None:
            return _response(
                request,
                outcome="ambiguous",
                reason="invalid_exchange_statuses",
            )
        normalized.append(normalized_row)
        kinds.add(normalized_row["kind"])

    if kinds <= {"resting", "filled"}:
        return _response(request, outcome="accepted", statuses=normalized)
    if kinds == {"error"}:
        return _response(
            request,
            outcome="rejected",
            statuses=normalized,
            reason="exchange_status_error",
        )
    if "error" in kinds:
        return _response(
            request,
            outcome="ambiguous",
            statuses=normalized,
            reason="mixed_exchange_statuses",
        )
    return _response(
        request,
        outcome="ambiguous",
        statuses=[],
        reason="unknown_exchange_status",
    )


def _normalize_cancel_response(
    response: Any, request: ExchangeRequest
) -> ExchangeResponse:
    statuses = _extract_statuses(response, "cancel")
    if statuses is None:
        return _invalid_exchange_response(response, request, "cancel")
    if not statuses:
        return _response(
            request,
            outcome="ambiguous",
            reason="empty_exchange_statuses",
        )
    if len(statuses) > MAX_STATUS_ROWS:
        return _response(
            request,
            outcome="ambiguous",
            reason="too_many_exchange_statuses",
        )

    normalized: list[dict[str, Any]] = []
    kinds: set[str] = set()
    for row in statuses:
        if row == "success":
            normalized_row = {"kind": "success"}
        elif (
            isinstance(row, dict)
            and set(row) == {"error"}
            and isinstance(row["error"], str)
        ):
            normalized_row = {"kind": "error"}
        else:
            return _response(
                request,
                outcome="ambiguous",
                reason="invalid_exchange_statuses",
            )
        normalized.append(normalized_row)
        kinds.add(normalized_row["kind"])

    if kinds == {"success"}:
        return _response(request, outcome="accepted", statuses=normalized)
    if kinds == {"error"}:
        return _response(
            request,
            outcome="rejected",
            statuses=normalized,
            reason="exchange_status_error",
        )
    return _response(
        request,
        outcome="ambiguous",
        statuses=normalized,
        reason="mixed_exchange_statuses",
    )


def _normalize_default_response(
    response: Any, request: ExchangeRequest
) -> ExchangeResponse:
    if not isinstance(response, dict) or response != {"type": "default"}:
        return _invalid_exchange_response(response, request, "default")
    return _response(request, outcome="accepted")


def _extract_statuses(response: Any, expected_type: str) -> list[Any] | None:
    if not isinstance(response, dict) or set(response) != {"type", "data"}:
        return None
    if response.get("type") != expected_type:
        return None
    data = response.get("data")
    if not isinstance(data, dict) or set(data) != {"statuses"}:
        return None
    statuses = data.get("statuses")
    return statuses if isinstance(statuses, list) else None


def _invalid_exchange_response(
    response: Any, request: ExchangeRequest, expected_type: str
) -> ExchangeResponse:
    reason = (
        "unexpected_exchange_response_type"
        if isinstance(response, dict) and response.get("type") != expected_type
        else "invalid_exchange_response"
    )
    return _response(request, outcome="ambiguous", reason=reason)


def _normalize_order_row(row: Any) -> dict[str, Any] | None:
    if not isinstance(row, dict):
        return None
    if set(row) == {"error"} and isinstance(row["error"], str):
        return {"kind": "error"}
    if set(row) == {"resting"}:
        resting = row["resting"]
        if (
            not isinstance(resting, dict)
            or set(resting) != {"oid"}
            or not _is_valid_oid(resting["oid"])
        ):
            return None
        return {"kind": "resting", "oid": resting["oid"]}
    if set(row) == {"filled"}:
        filled = row["filled"]
        if not isinstance(filled, dict):
            return None
        allowed_keys = {"oid", "totalSz", "avgPx"}
        if (
            "oid" not in filled
            or not set(filled) <= allowed_keys
            or not _is_valid_oid(filled["oid"])
        ):
            return None
        normalized: dict[str, Any] = {
            "kind": "filled",
            "oid": filled["oid"],
        }
        for source, target in (
            ("totalSz", "total_size"),
            ("avgPx", "average_price"),
        ):
            if source in filled:
                value = _normalize_positive_decimal(filled[source])
                if value is None:
                    return None
                normalized[target] = value
        return normalized
    return None


def _is_valid_oid(value: Any) -> bool:
    return (
        isinstance(value, int)
        and not isinstance(value, bool)
        and 0 < value <= MAX_INT64
    )


def _normalize_positive_decimal(value: Any) -> str | None:
    if isinstance(value, bool) or not isinstance(value, (str, int, float)):
        return None
    try:
        decimal = Decimal(str(value))
    except InvalidOperation:
        return None
    if not decimal.is_finite() or decimal <= 0:
        return None
    return str(value)


def _response(
    request: ExchangeRequest,
    *,
    outcome: str,
    statuses: list[dict[str, Any]] | None = None,
    reason: str | None = None,
) -> ExchangeResponse:
    try:
        return ExchangeResponse(
            schema_version="1",
            outcome=outcome,
            statuses=statuses or [],
            reason=reason,
            correlation_id=request.correlation_id,
        )
    except ValidationError:
        return ExchangeResponse(
            schema_version="1",
            outcome="ambiguous",
            statuses=[],
            reason="invalid_exchange_statuses",
            correlation_id=request.correlation_id,
        )


def _transport_reason(error: TransportError) -> str:
    reason = str(error)
    allowed = {
        "exchange_timeout",
        "exchange_transport_error",
        "exchange_response_too_large",
        "exchange_response_invalid_length",
        "exchange_response_invalid_json",
        "exchange_response_not_object",
        "exchange_redirect_rejected",
        "testnet_endpoint_required",
    }
    return reason if reason in allowed else "exchange_transport_error"
