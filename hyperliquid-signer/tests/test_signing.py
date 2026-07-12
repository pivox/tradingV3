import json
from typing import Any

import pytest
import requests
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

    def post_json(
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

    response = signer.submit(exchange_request())

    assert response.model_dump() == {
        "schema_version": "1",
        "outcome": "rejected",
        "statuses": [],
        "reason": "broadcast_disabled",
        "correlation_id": "corr-1",
    }
    assert transport.calls == []


def test_submit_posts_only_exact_testnet_body_without_returning_signature() -> None:
    transport = FakeTransport()
    signer = HyperliquidTestnetSigner(config(), transport)

    response = signer.submit(exchange_request(expires_after=1_700_000_030_000))

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
    response = HyperliquidTestnetSigner(config(), FakeTransport(payload)).submit(
        exchange_request()
    )

    assert response.outcome == outcome
    assert response.reason == reason
    assert response.statuses == statuses


def test_transport_timeout_is_ambiguous() -> None:
    response = HyperliquidTestnetSigner(
        config(), FakeTransport(error=TransportError("exchange_timeout"))
    ).submit(exchange_request())

    assert response.outcome == "ambiguous"
    assert response.reason == "exchange_timeout"
    assert response.statuses == []


class FakeResponse:
    def __init__(self, body: bytes) -> None:
        self.headers: dict[str, str] = {}
        self._body = body

    def raise_for_status(self) -> None:
        return None

    def iter_content(self, chunk_size: int) -> Any:
        del chunk_size
        yield self._body

    def close(self) -> None:
        return None


class FakeSession:
    def __init__(self, response: FakeResponse | Exception) -> None:
        self.response = response
        self.calls: list[dict[str, Any]] = []

    def post(self, url: str, **kwargs: Any) -> FakeResponse:
        self.calls.append({"url": url, **kwargs})
        if isinstance(self.response, Exception):
            raise self.response
        return self.response


def test_requests_transport_uses_streaming_and_connect_read_timeout() -> None:
    session = FakeSession(FakeResponse(b'{"status":"ok"}'))
    transport = RequestsTransport(session=session)

    payload = transport.post_json(
        TESTNET_URI + "/exchange",
        json_body={"action": {}},
        timeout=(5.0, 5.0),
    )

    assert payload == {"status": "ok"}
    assert session.calls == [
        {
            "url": TESTNET_URI + "/exchange",
            "json": {"action": {}},
            "timeout": (5.0, 5.0),
            "stream": True,
        }
    ]


@pytest.mark.parametrize(
    ("response", "reason"),
    [
        (FakeResponse(b"[]"), "exchange_response_not_object"),
        (FakeResponse(b"not-json"), "exchange_response_invalid_json"),
        (FakeResponse(b"x" * (64 * 1024 + 1)), "exchange_response_too_large"),
        (requests.Timeout(), "exchange_timeout"),
    ],
)
def test_requests_transport_has_stable_bounded_failures(
    response: FakeResponse | Exception, reason: str
) -> None:
    transport = RequestsTransport(session=FakeSession(response))

    with pytest.raises(TransportError, match=f"^{reason}$"):
        transport.post_json(
            TESTNET_URI + "/exchange",
            json_body={},
            timeout=(5.0, 5.0),
        )


def test_requests_transport_rejects_wrong_url_without_network() -> None:
    session = FakeSession(FakeResponse(json.dumps({"status": "ok"}).encode()))

    with pytest.raises(TransportError, match="^testnet_endpoint_required$"):
        RequestsTransport(session=session).post_json(
            "https://api.hyperliquid.xyz/exchange",
            json_body={},
            timeout=(5.0, 5.0),
        )

    assert session.calls == []
