from __future__ import annotations

import os
from dataclasses import dataclass, field
from typing import List, Optional


DEFAULT_WS_URL = "wss://openapi-ws-v2.bitmart.com/api?protocol=1.1"
DEFAULT_REST_URL = "https://api-cloud-v2.bitmart.com"
DEFAULT_POLL_SECONDS = 120
DEFAULT_WS_PING_SECONDS = 20
DEFAULT_WS_LOGIN_PAYLOAD = "login"
DEFAULT_WS_CHANNELS = ("futures/position",)
DEFAULT_API_HOST = "0.0.0.0"
DEFAULT_API_PORT = 9000


def _getbool(key: str, default: bool) -> bool:
    value = _getenv(key)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def _getenv(key: str, default: Optional[str] = None) -> Optional[str]:
    value = os.getenv(key)
    if value is None or value == "":
        return default
    return value


def _get_channels(raw: Optional[str]) -> List[str]:
    if not raw:
        return list(DEFAULT_WS_CHANNELS)
    return [item.strip() for item in raw.split(",") if item.strip()]


@dataclass(frozen=True)
class DatabaseConfig:
    host: str
    port: int
    username: str
    password: str
    name: str
    charset: str = "utf8mb4"


@dataclass(frozen=True)
class BitmartConfig:
    api_key: str
    api_secret: str
    api_memo: str
    ws_url: str = DEFAULT_WS_URL
    rest_url: str = DEFAULT_REST_URL
    ws_login_payload: str = DEFAULT_WS_LOGIN_PAYLOAD
    ws_ping_interval: int = DEFAULT_WS_PING_SECONDS
    poll_interval: int = DEFAULT_POLL_SECONDS
    rest_timeout: float = 10.0
    ws_channels: List[str] = field(default_factory=list)


@dataclass(frozen=True)
class AppConfig:
    database: DatabaseConfig
    bitmart: BitmartConfig
    log_level: str = "INFO"
    api_host: str = DEFAULT_API_HOST
    api_port: int = DEFAULT_API_PORT
    auto_start: bool = True

    @staticmethod
    def from_env() -> "AppConfig":
        db = DatabaseConfig(
            host=_getenv("DB_HOST", "db"),
            port=int(_getenv("DB_PORT", "3306")),
            username=_getenv("DB_USER", "symfony"),
            password=_getenv("DB_PASSWORD", "symfony"),
            name=_getenv("DB_NAME", "symfony_db"),
        )

        channels = _get_channels(_getenv("BITMART_WS_CHANNELS"))

        bitmart = BitmartConfig(
            api_key=_getenv("BITMART_API_KEY", ""),
            api_secret=_getenv("BITMART_SECRET_KEY", ""),
            api_memo=_getenv("BITMART_API_MEMO", ""),
            ws_url=_getenv("BITMART_WS_URL", DEFAULT_WS_URL),
            rest_url=_getenv("BITMART_REST_URL", DEFAULT_REST_URL),
            ws_login_payload=_getenv("BITMART_WS_LOGIN_PAYLOAD", DEFAULT_WS_LOGIN_PAYLOAD),
            ws_ping_interval=int(_getenv("BITMART_WS_PING_SECONDS", str(DEFAULT_WS_PING_SECONDS))),
            poll_interval=int(_getenv("BITMART_POLL_SECONDS", str(DEFAULT_POLL_SECONDS))),
            rest_timeout=float(_getenv("BITMART_REST_TIMEOUT", "10")),
            ws_channels=channels,
        )

        log_level = _getenv("LOG_LEVEL", "INFO")
        api_host = _getenv("BITMART_SYNC_HOST", DEFAULT_API_HOST)
        api_port = int(_getenv("BITMART_SYNC_PORT", str(DEFAULT_API_PORT)))
        auto_start = _getbool("BITMART_AUTO_START", True)

        return AppConfig(
            database=db,
            bitmart=bitmart,
            log_level=log_level,
            api_host=api_host,
            api_port=api_port,
            auto_start=auto_start,
        )
