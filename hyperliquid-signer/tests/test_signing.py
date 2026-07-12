import asyncio
import json
import time
from typing import Any

import httpx
import pytest
from eth_account import Account
from pydantic import SecretStr

from app.config import SignerConfig, TESTNET_URI
from app.contracts import ExchangeRequest
from app.signing import (
    HyperliquidTestnetSigner,
    RequestsTransport,
    TransportError,
    _sign_l1_testnet_action,
)


FIXTURE_KEY = (
    "0x0123456789012345678901234567890123456789012345678901234567890123"
)
FIXTURE_ADDRESS = Account.from_key(FIXTURE_KEY).address.lower()
TOKEN = "test-auth-token"


def config(**overrides: Any) -> SignerConfig:
    values: dict[str, Any] = {
        "environment": "testnet",
        "network": "testnet",
        "api_base_uri": TESTNET_URI,
        "agent_private_key": SecretStr(FIXTURE_KEY),
        "agent_address": FIXTURE_ADDRESS,
        "auth_token": SecretStr(TOKEN),
        "broadcast_enabled": True,
    }
    values.update(overrides)
    return SignerConfig(**values)


def exchange_request(**overrides: Any) -> ExchangeRequest:
    values: dict[str, Any] = {
        "schema_version": "1",
        "environment": "testnet",
        "network": "testnet",
        "nonce": 1_700_000_000_001,
        "account_address": "0x1111111111111111111111111111111111111111",
        "agent_address": FIXTURE_ADDRESS,
        "action": {"type": "order", "orders": []},
        "correlation_id": "corr-1",
    }
    values.update(overrides)
    return ExchangeRequest(**values)


class FakeTransport:
    def __init__(
        self,
        payload: dict[str, Any] | None = None,
        error: Exception | None = None,
    ) -> None:
        self.payload = payload or {
            "status": "ok",
            "response": {
                "type": "order",
                "data": {"statuses": [{"resting": {"oid": 42}}]},
            },
        }
        self.error = error
        self.calls: list[tuple[str, dict[str, Any], tuple[float, float]]] = []

    async def post_json(
        self,
        url: str,
        *,
        json_body: dict[str, Any],
        timeout: tuple[float, float],
    ) -> dict[str, Any]:
        self.calls.append((url, json_body, timeout))
        if self.error is not None:
            raise self.error
        return self.payload


def test_official_sdk_testnet_signing_vector() -> None:
    wallet = Account.from_key(FIXTURE_KEY)

    signature = _sign_l1_testnet_action(
        wallet,
        {"type": "dummy", "num": 100000000000},
        nonce=0,
        expires_after=None,
    )

    assert signature == {
        "r": "0x542af61ef1f429707e3c76c5293c80d01f74ef853e34b76efffcb57e574f9510",
        "s": "0x17b8b32f086e8cdede991f1e2c529f5dd5297cbe8128500e00cbaf766204a613",
        "v": 28,
    }


def test_signer_requires_private_key_to_match_configured_agent() -> None:
    with pytest.raises(
        ValueError, match="^agent_private_key_address_mismatch$"
    ):
        HyperliquidTestnetSigner(
            config(agent_address="0x2222222222222222222222222222222222222222"),
            FakeTransport(),
        )


def test_broadcast_disabled_rejects_before_signing_or_transport(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    transport = FakeTransport()
    signer = HyperliquidTestnetSigner(
        config(broadcast_enabled=False), transport
    )
    monkeypatch.setattr(
        "app.signing._sign_l1_testnet_action",
        lambda *args, **kwargs: pytest.fail("signing must not run"),
    )

    response = asyncio.run(
        signer.submit(
            exchange_request(
                agent_address="0x2222222222222222222222222222222222222222"
            )
        )
    )

    assert response.model_dump() == {
        "schema_version": "1",
        "outcome": "rejected",
        "statuses": [],
        "reason": "broadcast_disabled",
        "correlation_id": "corr-1",
    }
    assert transport.calls == []


def test_request_agent_mismatch_rejects_before_signing_or_transport(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    transport = FakeTransport()
    signer = HyperliquidTestnetSigner(config(), transport)
    monkeypatch.setattr(
        "app.signing._sign_l1_testnet_action",
        lambda *args, **kwargs: pytest.fail("signing must not run"),
    )

    response = asyncio.run(
        signer.submit(
            exchange_request(
                agent_address="0x2222222222222222222222222222222222222222"
            )
        )
    )

    assert response.outcome == "rejected"
    assert response.reason == "agent_address_mismatch"
    assert response.statuses == []
    assert transport.calls == []


def test_submit_posts_only_exact_testnet_body_without_returning_signature() -> None:
    transport = FakeTransport()
    signer = HyperliquidTestnetSigner(config(), transport)

    response = asyncio.run(
        signer.submit(
            exchange_request(expires_after=1_700_000_030_000)
        )
    )

    assert response.outcome == "accepted"
    assert response.statuses == [{"resting": {"oid": 42}}]
    assert "signature" not in response.model_dump()
    assert len(transport.calls) == 1
    url, body, timeout = transport.calls[0]
    assert url == "https://api.hyperliquid-testnet.xyz/exchange"
    assert timeout == (5.0, 5.0)
    assert set(body) == {
        "action",
        "nonce",
        "signature",
        "vaultAddress",
        "expiresAfter",
    }
    assert body["action"] == {"type": "order", "orders": []}
    assert body["nonce"] == 1_700_000_000_001
    assert body["vaultAddress"] is None
    assert body["expiresAfter"] == 1_700_000_030_000


@pytest.mark.parametrize(
    ("payload", "outcome", "reason", "statuses"),
    [
        (
            {
                "status": "ok",
                "response": {"data": {"statuses": [{"filled": {"oid": 7}}]}},
            },
            "accepted",
            None,
            [{"filled": {"oid": 7}}],
        ),
        (
            {
                "status": "ok",
                "response": {"data": {"statuses": [{"error": "bad order"}]}},
            },
            "rejected",
            "exchange_status_error",
            [{"error": "bad order"}],
        ),
        (
            {"status": "err", "response": "bad request"},
            "rejected",
            "exchange_error",
            [],
        ),
        (
            {"error": "bad request"},
            "rejected",
            "exchange_error",
            [],
        ),
        (
            {"err": "bad request"},
            "rejected",
            "exchange_error",
            [],
        ),
        (
            {"status": "ok", "response": {"data": {"statuses": []}}},
            "ambiguous",
            "empty_exchange_statuses",
            [],
        ),
        (
            {
                "status": "ok",
                "response": {"data": {"statuses": [{"waiting": {}}]}},
            },
            "ambiguous",
            "unknown_exchange_status",
            [{"waiting": {}}],
        ),
        (
            {
                "status": "ok",
                "response": {
                    "data": {
                        "statuses": [
                            {"resting": {"oid": 8}},
                            {"error": "second row failed"},
                        ]
                    }
                },
            },
            "ambiguous",
            "mixed_exchange_statuses",
            [{"resting": {"oid": 8}}, {"error": "second row failed"}],
        ),
        (
            {
                "status": "ok",
                "response": {
                    "data": {
                        "statuses": [
                            {"resting": {"oid": 8}, "error": "conflict"}
                        ]
                    }
                },
            },
            "ambiguous",
            "mixed_exchange_statuses",
            [{"resting": {"oid": 8}, "error": "conflict"}],
        ),
        (
            {"status": "ok", "response": {"data": {"statuses": "invalid"}}},
            "ambiguous",
            "invalid_exchange_response",
            [],
        ),
    ],
)
def test_normalizes_exchange_payloads(
    payload: dict[str, Any],
    outcome: str,
    reason: str | None,
    statuses: list[dict[str, Any]],
) -> None:
    response = asyncio.run(
        HyperliquidTestnetSigner(config(), FakeTransport(payload)).submit(
            exchange_request()
        )
    )

    assert response.outcome == outcome
    assert response.reason == reason
    assert response.statuses == statuses


def test_transport_timeout_is_ambiguous() -> None:
    response = asyncio.run(
        HyperliquidTestnetSigner(
            config(), FakeTransport(error=TransportError("exchange_timeout"))
        ).submit(exchange_request())
    )

    assert response.outcome == "ambiguous"
    assert response.reason == "exchange_timeout"
    assert response.statuses == []


def run_transport(
    handler: Any,
    *,
    url: str = TESTNET_URI + "/exchange",
    json_body: dict[str, Any] | None = None,
) -> dict[str, Any]:
    async def run() -> dict[str, Any]:
        async with httpx.AsyncClient(
            transport=httpx.MockTransport(handler), follow_redirects=True
        ) as client:
            return await RequestsTransport(client=client).post_json(
                url,
                json_body=json_body or {},
                timeout=(5.0, 5.0),
            )

    return asyncio.run(run())


def test_requests_transport_posts_exact_body_with_http_timeouts() -> None:
    requests_seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests_seen.append(request)
        return httpx.Response(200, json={"status": "ok"})

    payload = run_transport(handler, json_body={"action": {}})

    assert payload == {"status": "ok"}
    assert len(requests_seen) == 1
    request = requests_seen[0]
    assert request.method == "POST"
    assert str(request.url) == TESTNET_URI + "/exchange"
    assert json.loads(request.content) == {"action": {}}
    assert request.extensions["timeout"] == {
        "connect": 5.0,
        "read": 5.0,
        "write": 5.0,
        "pool": 5.0,
    }


def test_requests_transport_rejects_redirect_without_following() -> None:
    requests_seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests_seen.append(request)
        return httpx.Response(
            302, headers={"Location": "https://api.hyperliquid.xyz/exchange"}
        )

    with pytest.raises(TransportError, match="^exchange_redirect_rejected$"):
        run_transport(handler)

    assert len(requests_seen) == 1


@pytest.mark.parametrize(
    ("body", "error", "reason"),
    [
        (b"[]", None, "exchange_response_not_object"),
        (b"not-json", None, "exchange_response_invalid_json"),
        (b"x" * (64 * 1024 + 1), None, "exchange_response_too_large"),
        (b"", httpx.ReadTimeout("slow response"), "exchange_timeout"),
    ],
)
def test_requests_transport_has_stable_bounded_failures(
    body: bytes, error: Exception | None, reason: str
) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        if error is not None:
            raise error
        return httpx.Response(200, content=body, request=request)

    with pytest.raises(TransportError, match=f"^{reason}$"):
        run_transport(handler)


def test_requests_transport_rejects_wrong_url_without_network() -> None:
    requests_seen: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests_seen.append(request)
        return httpx.Response(200, json={"status": "ok"})

    with pytest.raises(TransportError, match="^testnet_endpoint_required$"):
        run_transport(handler, url="https://api.hyperliquid.xyz/exchange")

    assert requests_seen == []


class CancellableSlowStream(httpx.AsyncByteStream):
    def __init__(self, delay: float) -> None:
        self.cancelled = False
        self._delay = delay

    async def __aiter__(self) -> Any:
        try:
            await asyncio.sleep(self._delay)
            yield b'{"status":"ok"}'
        except asyncio.CancelledError:
            self.cancelled = True
            raise


def test_async_transport_interrupts_entire_exchange_at_total_deadline() -> None:
    stream = CancellableSlowStream(delay=0.03)

    async def run() -> float:
        async def handler(request: httpx.Request) -> httpx.Response:
            await asyncio.sleep(0.03)
            return httpx.Response(200, stream=stream, request=request)

        client = httpx.AsyncClient(
            transport=httpx.MockTransport(handler)
        )
        transport = RequestsTransport(client=client, total_timeout=0.05)
        started = time.monotonic()
        try:
            with pytest.raises(TransportError, match="^exchange_timeout$"):
                await transport.post_json(
                    TESTNET_URI + "/exchange",
                    json_body={},
                    timeout=(5.0, 5.0),
                )
        finally:
            await client.aclose()
        return time.monotonic() - started

    elapsed = asyncio.run(run())

    assert 0.04 <= elapsed < 0.25
    assert stream.cancelled is True
