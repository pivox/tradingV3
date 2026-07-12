import asyncio
import json
import logging
from typing import Any

import pytest
from eth_account import Account
from fastapi.testclient import TestClient
from pydantic import SecretStr

from app.config import SignerConfig, TESTNET_URI
from app.main import create_app


FIXTURE_KEY = (
    "0x0123456789012345678901234567890123456789012345678901234567890123"
)
FIXTURE_ADDRESS = Account.from_key(FIXTURE_KEY).address.lower()
TOKEN = "sidecar-test-token"
AUTH = {"Authorization": f"Bearer {TOKEN}"}


class FakeTransport:
    def __init__(self) -> None:
        self.calls: list[dict[str, Any]] = []

    def post_json(
        self,
        url: str,
        *,
        json_body: dict[str, Any],
        timeout: tuple[float, float],
    ) -> dict[str, Any]:
        self.calls.append(
            {"url": url, "json_body": json_body, "timeout": timeout}
        )
        return {
            "status": "ok",
            "response": {
                "data": {"statuses": [{"resting": {"oid": 42}}]}
            },
        }


def signer_config(**overrides: Any) -> SignerConfig:
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


def request_json(**overrides: Any) -> dict[str, Any]:
    values: dict[str, Any] = {
        "schema_version": "1",
        "environment": "testnet",
        "network": "testnet",
        "nonce": 1_700_000_000_001,
        "account_address": "0x1111111111111111111111111111111111111111",
        "agent_address": FIXTURE_ADDRESS,
        "action": {"type": "order", "orders": []},
        "correlation_id": "corr-api-1",
    }
    values.update(overrides)
    return values


def asgi_post_chunks(
    application: Any, chunks: list[bytes]
) -> tuple[int, dict[str, Any], int]:
    async def invoke() -> tuple[int, dict[str, Any], int]:
        delivered = 0
        messages: list[dict[str, Any]] = []

        async def receive() -> dict[str, Any]:
            nonlocal delivered
            if delivered >= len(chunks):
                return {"type": "http.disconnect"}
            chunk = chunks[delivered]
            delivered += 1
            return {
                "type": "http.request",
                "body": chunk,
                "more_body": delivered < len(chunks),
            }

        async def send(message: dict[str, Any]) -> None:
            messages.append(message)

        await application(
            {
                "type": "http",
                "asgi": {"version": "3.0"},
                "http_version": "1.1",
                "method": "POST",
                "scheme": "http",
                "path": "/v1/exchange",
                "raw_path": b"/v1/exchange",
                "query_string": b"",
                "root_path": "",
                "headers": [
                    (b"authorization", f"Bearer {TOKEN}".encode("ascii")),
                    (b"content-type", b"application/json"),
                ],
                "client": ("127.0.0.1", 1234),
                "server": ("testserver", 80),
                "state": {},
            },
            receive,
            send,
        )
        start = next(
            message
            for message in messages
            if message["type"] == "http.response.start"
        )
        body = b"".join(
            message.get("body", b"")
            for message in messages
            if message["type"] == "http.response.body"
        )
        return start["status"], json.loads(body), delivered

    return asyncio.run(invoke())


@pytest.fixture
def transport() -> FakeTransport:
    return FakeTransport()


@pytest.fixture
def client(transport: FakeTransport) -> TestClient:
    return TestClient(create_app(signer_config(), transport=transport))


@pytest.mark.parametrize("path", ["/v1/health", "/v1/exchange"])
def test_all_endpoints_require_exact_bearer_auth(
    client: TestClient, path: str
) -> None:
    method = client.get if path.endswith("health") else client.post

    for headers in (
        {},
        {"Authorization": TOKEN},
        {"Authorization": f"bearer {TOKEN}"},
        {"Authorization": "Bearer wrong"},
        {"Authorization": f"Bearer {TOKEN} "},
    ):
        response = method(path, headers=headers)
        assert response.status_code == 401
        assert response.json() == {"detail": "unauthorized"}


def test_health_returns_only_nonsensitive_readiness_fields(
    client: TestClient,
) -> None:
    response = client.get("/v1/health", headers=AUTH)

    assert response.status_code == 200
    assert response.json() == {
        "schema_version": "1",
        "ready": True,
        "environment": "testnet",
        "agent_address": FIXTURE_ADDRESS,
        "broadcast_enabled": True,
    }


def test_exchange_returns_structured_redacted_outcome(
    client: TestClient, transport: FakeTransport
) -> None:
    response = client.post("/v1/exchange", headers=AUTH, json=request_json())

    assert response.status_code == 200
    assert response.json() == {
        "schema_version": "1",
        "outcome": "accepted",
        "statuses": [{"resting": {"oid": 42}}],
        "reason": None,
        "correlation_id": "corr-api-1",
    }
    serialized = response.text.lower()
    assert "signature" not in serialized
    assert FIXTURE_KEY.lower() not in serialized
    assert TOKEN.lower() not in serialized
    assert len(transport.calls) == 1


def test_broadcast_disabled_is_structured_200_without_transport() -> None:
    transport = FakeTransport()
    client = TestClient(
        create_app(
            signer_config(broadcast_enabled=False), transport=transport
        )
    )

    response = client.post("/v1/exchange", headers=AUTH, json=request_json())

    assert response.status_code == 200
    assert response.json()["outcome"] == "rejected"
    assert response.json()["reason"] == "broadcast_disabled"
    assert transport.calls == []


def test_request_over_64_kib_is_rejected_before_json_parsing(
    client: TestClient,
) -> None:
    invalid_json = b"{" + b"x" * (64 * 1024)

    response = client.post(
        "/v1/exchange",
        headers={**AUTH, "Content-Type": "application/json"},
        content=invalid_json,
    )

    assert response.status_code == 413
    assert response.json() == {"detail": "request_too_large"}


def test_chunked_request_over_64_kib_stops_stream_consumption() -> None:
    application = create_app(signer_config(), transport=FakeTransport())

    status, body, delivered = asgi_post_chunks(
        application, [b"x" * (32 * 1024)] * 4
    )

    assert status == 413
    assert body == {"detail": "request_too_large"}
    assert delivered == 3


def test_bounded_chunked_request_is_replayed_to_validation() -> None:
    application = create_app(signer_config(), transport=FakeTransport())
    encoded = json.dumps(request_json()).encode("utf-8")

    status, body, delivered = asgi_post_chunks(
        application, [encoded[:30], encoded[30:]]
    )

    assert status == 200
    assert body["outcome"] == "accepted"
    assert delivered == 2


def test_unauthorized_oversized_request_is_rejected_as_unauthorized(
    client: TestClient,
) -> None:
    response = client.post("/v1/exchange", content=b"x" * (64 * 1024 + 1))

    assert response.status_code == 401
    assert response.json() == {"detail": "unauthorized"}


@pytest.mark.parametrize("field", ["signature", "auth_token"])
def test_validation_error_is_fixed_and_does_not_echo_input(
    client: TestClient, field: str
) -> None:
    sentinel = f"sentinel-{field}-must-not-appear"
    response = client.post(
        "/v1/exchange",
        headers=AUTH,
        json=request_json(**{field: sentinel}),
    )

    assert response.status_code == 422
    assert response.json() == {"detail": "invalid_request"}
    assert sentinel not in response.text


def test_config_or_signer_failure_returns_503(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.delenv("HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY", raising=False)
    monkeypatch.delenv("HYPERLIQUID_TESTNET_AGENT_ADDRESS", raising=False)
    monkeypatch.setenv("HYPERLIQUID_SIGNER_AUTH_TOKEN", TOKEN)
    client = TestClient(create_app())

    health = client.get("/v1/health", headers=AUTH)
    exchange = client.post(
        "/v1/exchange", headers=AUTH, json=request_json()
    )

    assert health.status_code == 503
    assert health.json() == {"detail": "signer_unavailable"}
    assert exchange.status_code == 503
    assert exchange.json() == {"detail": "signer_unavailable"}


def test_logging_contains_only_allowed_exchange_metadata(
    client: TestClient, caplog: pytest.LogCaptureFixture
) -> None:
    caplog.set_level(logging.INFO, logger="hyperliquid_signer")

    response = client.post("/v1/exchange", headers=AUTH, json=request_json())

    assert response.status_code == 200
    records = [
        record for record in caplog.records
        if record.name == "hyperliquid_signer"
    ]
    assert len(records) == 1
    record = records[0]
    assert record.correlation_id == "corr-api-1"
    assert record.action_type == "order"
    assert record.outcome == "accepted"
    assert record.reason is None
    serialized = json.dumps(record.__dict__, default=str).lower()
    assert FIXTURE_KEY.lower() not in serialized
    assert TOKEN.lower() not in serialized
    assert "signature" not in serialized
