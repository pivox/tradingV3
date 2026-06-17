import pytest

from app.settings import Settings, SettingsError


def test_defaults(monkeypatch):
    monkeypatch.delenv("MAX_CONCURRENCY", raising=False)
    monkeypatch.delenv("ORCHESTRATOR_PORT", raising=False)

    settings = Settings.from_env()
    assert settings.max_concurrency == 2
    assert settings.port == 8099


def test_invalid_integer_raises(monkeypatch):
    monkeypatch.setenv("MAX_CONCURRENCY", "not-a-number")
    with pytest.raises(SettingsError):
        Settings.from_env()


def test_max_concurrency_must_be_positive(monkeypatch):
    monkeypatch.setenv("MAX_CONCURRENCY", "0")
    with pytest.raises(SettingsError):
        Settings.from_env()


def test_port_out_of_range_raises(monkeypatch):
    monkeypatch.setenv("ORCHESTRATOR_PORT", "70000")
    with pytest.raises(SettingsError):
        Settings.from_env()
