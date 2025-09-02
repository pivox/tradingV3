from pydantic_settings import BaseSettings
from pydantic import Field

class Settings(BaseSettings):
    INDICATOR_RULES_PATH: str = "/app/app/rules/indicator_rules.yaml"

    class Config:
        env_prefix = ""
        case_sensitive = False

_settings: Settings | None = None

def get_settings() -> Settings:
    global _settings
    if _settings is None:
        _settings = Settings()
    return _settings
