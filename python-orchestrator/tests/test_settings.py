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


# --- Interrupteur d'activation live (SAFE-003) ------------------------------


def test_live_switch_off_by_default(monkeypatch):
    # La config livrée garde le live désactivé : interrupteur OFF + allow-list vide.
    monkeypatch.delenv("ORCHESTRATION_LIVE_ENABLED", raising=False)
    monkeypatch.delenv("ORCHESTRATION_LIVE_EXCHANGES", raising=False)
    settings = Settings.from_env()
    assert settings.live_enabled is False
    assert settings.live_exchanges == ()


@pytest.mark.parametrize(
    "raw,expected",
    [
        ("true", True),
        ("True", True),
        ("1", True),
        ("yes", True),
        ("on", True),
        ("false", False),
        ("0", False),
        ("no", False),
        ("off", False),
    ],
)
def test_live_enabled_parsing(monkeypatch, raw, expected):
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", raw)
    assert Settings.from_env().live_enabled is expected


def test_live_enabled_invalid_raises(monkeypatch):
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", "maybe")
    with pytest.raises(SettingsError):
        Settings.from_env()


def test_live_exchanges_parsed_normalized_and_deduped(monkeypatch):
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", " Bitmart , bitmart , FAKE ")
    assert Settings.from_env().live_exchanges == ("bitmart", "fake")


def test_live_exchanges_unknown_raises(monkeypatch):
    # Une coquille (exchange inconnu) doit lever au démarrage plutôt que de rendre
    # l'allow-list silencieusement inopérante (fail-closed explicite).
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", "bitmart,binance")
    with pytest.raises(SettingsError):
        Settings.from_env()


# --- Niveau de log d'audit (OBS-001) ----------------------------------------


def test_log_level_defaults_to_info(monkeypatch):
    monkeypatch.delenv("ORCHESTRATION_LOG_LEVEL", raising=False)
    assert Settings.from_env().log_level == "INFO"


@pytest.mark.parametrize(
    "raw,expected",
    [
        ("debug", "DEBUG"),
        ("INFO", "INFO"),
        (" warning ", "WARNING"),
        ("Error", "ERROR"),
        ("critical", "CRITICAL"),
    ],
)
def test_log_level_parsed_and_normalized(monkeypatch, raw, expected):
    monkeypatch.setenv("ORCHESTRATION_LOG_LEVEL", raw)
    assert Settings.from_env().log_level == expected


def test_log_level_invalid_raises_at_startup(monkeypatch):
    # Une valeur invalide lève au démarrage (comme ORCHESTRATION_LOCK_TTL_SECONDS),
    # pas de repli silencieux sur le défaut.
    monkeypatch.setenv("ORCHESTRATION_LOG_LEVEL", "verbose")
    with pytest.raises(SettingsError):
        Settings.from_env()


# --- Collecte des métriques d'exécution (OBS-002) ---------------------------


def test_metrics_enabled_on_by_default(monkeypatch):
    monkeypatch.delenv("ORCHESTRATION_METRICS_ENABLED", raising=False)
    assert Settings.from_env().metrics_enabled is True


@pytest.mark.parametrize(
    "raw,expected",
    [("true", True), ("1", True), ("on", True), ("false", False), ("0", False), ("off", False)],
)
def test_metrics_enabled_parsing(monkeypatch, raw, expected):
    monkeypatch.setenv("ORCHESTRATION_METRICS_ENABLED", raw)
    assert Settings.from_env().metrics_enabled is expected


def test_metrics_enabled_invalid_raises_at_startup(monkeypatch):
    # Comme les autres interrupteurs : une valeur invalide lève au démarrage plutôt
    # qu'un repli silencieux.
    monkeypatch.setenv("ORCHESTRATION_METRICS_ENABLED", "sometimes")
    with pytest.raises(SettingsError):
        Settings.from_env()
