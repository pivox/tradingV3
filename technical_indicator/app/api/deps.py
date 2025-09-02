from fastapi import Depends
from ..config.settings import Settings, get_settings

def get_rules_path(settings: Settings = Depends(get_settings)) -> str:
    return settings.INDICATOR_RULES_PATH
