import asyncio
import json
from typing import Any, Protocol

import httpx
from eth_account import Account
from eth_account.signers.local import LocalAccount
from hyperliquid.utils.signing import sign_l1_action
from pydantic import ValidationError

from app.config import SignerConfig, TESTNET_URI
from app.contracts import ExchangeRequest, ExchangeResponse


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
        self._client = client or httpx.AsyncClient(follow_redirects=False)
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
        try:
            async with asyncio.timeout(self._total_timeout):
                async with self._client.stream(
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
    if (
        payload.get("status") in {"err", "error"}
        or "err" in payload
        or "error" in payload
    ):
        return _response(
            request, outcome="rejected", reason="exchange_error"
        )
    if payload.get("status") != "ok":
        return _response(
            request,
            outcome="ambiguous",
            reason="unknown_exchange_response",
        )

    response = payload.get("response")
    data = response.get("data") if isinstance(response, dict) else None
    statuses = data.get("statuses") if isinstance(data, dict) else None
    if not isinstance(statuses, list) or any(
        not isinstance(status, dict) for status in statuses
    ):
        return _response(
            request,
            outcome="ambiguous",
            reason="invalid_exchange_response",
        )
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

    row_kinds = {_status_kind(status) for status in statuses}
    if row_kinds == {"accepted"}:
        return _response(request, outcome="accepted", statuses=statuses)
    if row_kinds == {"rejected"}:
        return _response(
            request,
            outcome="rejected",
            statuses=statuses,
            reason="exchange_status_error",
        )
    if len(row_kinds) > 1 or "conflict" in row_kinds:
        return _response(
            request,
            outcome="ambiguous",
            statuses=statuses,
            reason="mixed_exchange_statuses",
        )
    return _response(
        request,
        outcome="ambiguous",
        statuses=statuses,
        reason="unknown_exchange_status",
    )


def _status_kind(status: dict[str, Any]) -> str:
    has_accepted = "resting" in status or "filled" in status
    has_error = "error" in status
    if has_accepted and has_error:
        return "conflict"
    if has_accepted:
        return "accepted"
    if has_error:
        return "rejected"
    return "unknown"


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
