from dataclasses import FrozenInstanceError

import pytest
from pydantic import SecretStr

from app.config import SignerConfig, TESTNET_URI


AGENT_ADDRESS = "0x1111111111111111111111111111111111111111"
INVALID_FIXTURE_KEY = "not-a-real-private-key"
FIXTURE_TOKEN = "deterministic-test-token"


def set_required_env(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv(
        "HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY", INVALID_FIXTURE_KEY
    )
    monkeypatch.setenv("HYPERLIQUID_TESTNET_AGENT_ADDRESS", AGENT_ADDRESS)
    monkeypatch.setenv("HYPERLIQUID_SIGNER_AUTH_TOKEN", FIXTURE_TOKEN)


def test_config_uses_testnet_defaults_and_hides_secrets(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    set_required_env(monkeypatch)

    config = SignerConfig.from_env()

    assert config.environment == "testnet"
    assert config.network == "testnet"
    assert config.api_base_uri == TESTNET_URI
    assert config.agent_address == AGENT_ADDRESS
    assert config.broadcast_enabled is False
    assert isinstance(config.agent_private_key, SecretStr)
    assert isinstance(config.auth_token, SecretStr)
    assert INVALID_FIXTURE_KEY not in repr(config)
    assert FIXTURE_TOKEN not in repr(config)


def test_config_is_immutable(monkeypatch: pytest.MonkeyPatch) -> None:
    set_required_env(monkeypatch)
    config = SignerConfig.from_env()

    with pytest.raises(FrozenInstanceError):
        config.network = "mainnet"  # type: ignore[misc]


@pytest.mark.parametrize("variable", [
    "HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY",
    "HYPERLIQUID_TESTNET_AGENT_ADDRESS",
    "HYPERLIQUID_SIGNER_AUTH_TOKEN",
])
@pytest.mark.parametrize("value", ["", "   "])
def test_config_rejects_missing_or_blank_credentials(
    monkeypatch: pytest.MonkeyPatch,
    variable: str,
    value: str,
) -> None:
    set_required_env(monkeypatch)
    monkeypatch.setenv(variable, value)

    with pytest.raises(ValueError, match="^signer_credentials_required$"):
        SignerConfig.from_env()


@pytest.mark.parametrize("address", [
    "0X1111111111111111111111111111111111111111",
    "0xAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
    "0x111111111111111111111111111111111111111",
    "1111111111111111111111111111111111111111",
    "0xgggggggggggggggggggggggggggggggggggggggg",
])
def test_config_requires_a_lowercase_agent_address(
    monkeypatch: pytest.MonkeyPatch,
    address: str,
) -> None:
    set_required_env(monkeypatch)
    monkeypatch.setenv("HYPERLIQUID_TESTNET_AGENT_ADDRESS", address)

    with pytest.raises(ValueError, match="^signer_credentials_required$"):
        SignerConfig.from_env()


@pytest.mark.parametrize("variable", ["HYPERLIQUID_ENV", "HYPERLIQUID_NETWORK"])
@pytest.mark.parametrize("value", ["mainnet", "TESTNET", " testnet", "testnet "])
def test_config_requires_exact_testnet_environment_and_network(
    monkeypatch: pytest.MonkeyPatch,
    variable: str,
    value: str,
) -> None:
    set_required_env(monkeypatch)
    monkeypatch.setenv(variable, value)

    with pytest.raises(ValueError, match="^testnet_environment_required$"):
        SignerConfig.from_env()


@pytest.mark.parametrize("endpoint", [
    "https://api.hyperliquid.xyz",
    "http://api.hyperliquid-testnet.xyz",
    "https://api.hyperliquid-testnet.xyz/",
    "https://api.hyperliquid-testnet.xyz/exchange",
    "https://api.hyperliquid-testnet.xyz?network=testnet",
    "https://api.hyperliquid-testnet.xyz#exchange",
    "https://user@api.hyperliquid-testnet.xyz",
    "https://api.hyperliquid-testnet.xyz:443",
    "https://api.hyperliquid-testnet.xyz.evil.example",
])
def test_config_rejects_any_non_exact_testnet_endpoint(
    monkeypatch: pytest.MonkeyPatch,
    endpoint: str,
) -> None:
    set_required_env(monkeypatch)
    monkeypatch.setenv("HYPERLIQUID_API_BASE_URI", endpoint)

    with pytest.raises(ValueError, match="^testnet_endpoint_required$"):
        SignerConfig.from_env()


@pytest.mark.parametrize("value", ["1", "true", "yes", "on"])
def test_config_accepts_explicit_true_broadcast_values(
    monkeypatch: pytest.MonkeyPatch,
    value: str,
) -> None:
    set_required_env(monkeypatch)
    monkeypatch.setenv("HYPERLIQUID_SIGNER_BROADCAST_ENABLED", value)

    assert SignerConfig.from_env().broadcast_enabled is True


@pytest.mark.parametrize("value", ["0", "false", "no", "off"])
def test_config_accepts_explicit_false_broadcast_values(
    monkeypatch: pytest.MonkeyPatch,
    value: str,
) -> None:
    set_required_env(monkeypatch)
    monkeypatch.setenv("HYPERLIQUID_SIGNER_BROADCAST_ENABLED", value)

    assert SignerConfig.from_env().broadcast_enabled is False


def test_config_rejects_unknown_broadcast_value(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    set_required_env(monkeypatch)
    monkeypatch.setenv("HYPERLIQUID_SIGNER_BROADCAST_ENABLED", "enabled")

    with pytest.raises(ValueError, match="^invalid_broadcast_enabled$"):
        SignerConfig.from_env()
