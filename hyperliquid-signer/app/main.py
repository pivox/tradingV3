import hmac
import logging
import os
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.config import SignerConfig
from app.contracts import ExchangeRequest, ExchangeResponse
from app.signing import HyperliquidTestnetSigner, RequestsTransport, Transport


MAX_REQUEST_BYTES = 64 * 1024
logger = logging.getLogger("hyperliquid_signer")


def create_app(
    config: SignerConfig | None = None,
    *,
    transport: Transport | None = None,
) -> FastAPI:
    signer: HyperliquidTestnetSigner | None = None
    resolved_config = config
    resolved_transport = transport
    auth_token = (
        config.auth_token.get_secret_value()
        if config is not None
        else os.getenv("HYPERLIQUID_SIGNER_AUTH_TOKEN", "").strip()
    )
    try:
        resolved_config = resolved_config or SignerConfig.from_env()
        resolved_transport = resolved_transport or RequestsTransport()
        signer = HyperliquidTestnetSigner(
            resolved_config, resolved_transport
        )
    except (TypeError, ValueError):
        if not _broadcast_is_disabled(resolved_config):
            raise
        signer = None

    @asynccontextmanager
    async def lifespan(application: FastAPI) -> Any:
        del application
        try:
            yield
        finally:
            close = getattr(resolved_transport, "aclose", None)
            if callable(close):
                await close()

    application = FastAPI(lifespan=lifespan)

    @application.exception_handler(RequestValidationError)
    async def redact_validation_error(
        request: Request, error: RequestValidationError
    ) -> JSONResponse:
        del request, error
        return JSONResponse(
            status_code=422, content={"detail": "invalid_request"}
        )

    @application.middleware("http")
    async def authenticate_and_bound_body(
        request: Request, call_next: Any
    ) -> JSONResponse:
        if request.url.path in {"/v1/health", "/v1/exchange"}:
            if not _authorized(
                request.headers.get("Authorization"), auth_token
            ):
                return JSONResponse(
                    status_code=401, content={"detail": "unauthorized"}
                )
            content_length = request.headers.get("Content-Length")
            if content_length is not None:
                try:
                    if int(content_length) > MAX_REQUEST_BYTES:
                        return _too_large()
                except ValueError:
                    return _too_large()
            body = bytearray()
            async for chunk in request.stream():
                if len(body) + len(chunk) > MAX_REQUEST_BYTES:
                    return _too_large()
                body.extend(chunk)
            request._body = bytes(body)
        return await call_next(request)

    @application.get("/v1/health")
    async def health() -> dict[str, Any]:
        if signer is None or resolved_config is None:
            raise HTTPException(status_code=503, detail="signer_unavailable")
        return {
            "schema_version": "1",
            "ready": True,
            "environment": "testnet",
            "agent_address": resolved_config.agent_address,
            "broadcast_enabled": resolved_config.broadcast_enabled,
        }

    @application.post("/v1/exchange", response_model=ExchangeResponse)
    async def exchange(exchange_request: ExchangeRequest) -> ExchangeResponse:
        if signer is None:
            raise HTTPException(status_code=503, detail="signer_unavailable")
        response = await signer.submit(exchange_request)
        logger.info(
            "exchange_outcome",
            extra={
                "correlation_id": exchange_request.correlation_id,
                "action_type": exchange_request.action["type"],
                "outcome": response.outcome,
                "reason": response.reason,
            },
        )
        return response

    return application


def _authorized(header: str | None, expected_token: str) -> bool:
    if header is None or not header.startswith("Bearer "):
        return False
    presented_token = header[len("Bearer ") :]
    return bool(expected_token) and hmac.compare_digest(
        presented_token.encode("utf-8"), expected_token.encode("utf-8")
    )


def _broadcast_is_disabled(config: SignerConfig | None) -> bool:
    if config is not None:
        return not config.broadcast_enabled
    return os.getenv(
        "HYPERLIQUID_SIGNER_BROADCAST_ENABLED", "0"
    ).strip().lower() in {"0", "false", "no", "off"}


def _too_large() -> JSONResponse:
    return JSONResponse(
        status_code=413, content={"detail": "request_too_large"}
    )


app = create_app()
