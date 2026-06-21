import pytest

from app.settings import Settings, SettingsError


def test_defaults(monkeypatch):
    monkeypatch.delenv("MAX_CONCURRENCY", raising=False)
    monkeypatch.delenv("ORCHESTRATOR_PORT", raising=False)
    monkeypatch.delenv("DATABASE_URL", raising=False)
    monkeypatch.delenv("ORCHESTRATION_DB_SCHEMA", raising=False)

    monkeypatch.delenv("ORCHESTRATION_LOCK_TTL_SECONDS", raising=False)

    settings = Settings.from_env()
    assert settings.max_concurrency == 2
    assert settings.port == 8099
    assert settings.database_url.startswith("postgresql+psycopg://")
    assert settings.db_schema == "orchestration"
    assert settings.lock_ttl_seconds == 1800


def test_lock_ttl_from_env(monkeypatch):
    monkeypatch.setenv("ORCHESTRATION_LOCK_TTL_SECONDS", "900")
    assert Settings.from_env().lock_ttl_seconds == 900


def test_lock_ttl_must_be_positive(monkeypatch):
    monkeypatch.setenv("ORCHESTRATION_LOCK_TTL_SECONDS", "0")
    with pytest.raises(SettingsError):
        Settings.from_env()


def test_database_url_and_schema_from_env(monkeypatch):
    monkeypatch.setenv("DATABASE_URL", "postgresql+psycopg://u:p@host:5432/db")
    monkeypatch.setenv("ORCHESTRATION_DB_SCHEMA", "orch_test")

    settings = Settings.from_env()
    assert settings.database_url == "postgresql+psycopg://u:p@host:5432/db"
    assert settings.db_schema == "orch_test"


def test_blank_schema_raises(monkeypatch):
    monkeypatch.setenv("ORCHESTRATION_DB_SCHEMA", "   ")
    with pytest.raises(SettingsError):
        Settings.from_env()


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
