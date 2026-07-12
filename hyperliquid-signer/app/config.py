import os
import re
from dataclasses import dataclass

from pydantic import SecretStr


TESTNET_URI = "https://api.hyperliquid-testnet.xyz"
_ADDRESS_PATTERN = re.compile(r"^0x[0-9a-f]{40}$")
_TRUE_VALUES = frozenset({"1", "true", "yes", "on"})
_FALSE_VALUES = frozenset({"0", "false", "no", "off"})


@dataclass(frozen=True)
class SignerConfig:
    environment: str
    network: str
    api_base_uri: str
    agent_private_key: SecretStr
    agent_address: str
    auth_token: SecretStr
    broadcast_enabled: bool

    @classmethod
    def from_env(cls) -> "SignerConfig":
        environment = os.getenv("HYPERLIQUID_ENV", "testnet")
        network = os.getenv("HYPERLIQUID_NETWORK", "testnet")
        if environment != "testnet" or network != "testnet":
            raise ValueError("testnet_environment_required")

        api_base_uri = os.getenv("HYPERLIQUID_API_BASE_URI", TESTNET_URI)
        if api_base_uri != TESTNET_URI:
            raise ValueError("testnet_endpoint_required")

        private_key = os.getenv(
            "HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY", ""
        ).strip()
        agent_address = os.getenv(
            "HYPERLIQUID_TESTNET_AGENT_ADDRESS", ""
        ).strip()
        auth_token = os.getenv("HYPERLIQUID_SIGNER_AUTH_TOKEN", "").strip()
        if (
            not private_key
            or not auth_token
            or _ADDRESS_PATTERN.fullmatch(agent_address) is None
        ):
            raise ValueError("signer_credentials_required")

        broadcast_enabled = _parse_broadcast_enabled(
            os.getenv("HYPERLIQUID_SIGNER_BROADCAST_ENABLED", "0")
        )
        return cls(
            environment=environment,
            network=network,
            api_base_uri=api_base_uri,
            agent_private_key=SecretStr(private_key),
            agent_address=agent_address,
            auth_token=SecretStr(auth_token),
            broadcast_enabled=broadcast_enabled,
        )


def _parse_broadcast_enabled(value: str) -> bool:
    normalized = value.strip().lower()
    if normalized in _TRUE_VALUES:
        return True
    if normalized in _FALSE_VALUES:
        return False
    raise ValueError("invalid_broadcast_enabled")
