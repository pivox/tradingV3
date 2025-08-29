# technical_indicator/app/loaders.py
from __future__ import annotations
import os, yaml

TF_TO_MINUTES = {
    "1m":1, "3m":3, "5m":5, "15m":15, "30m":30,
    "1h":60, "2h":120, "4h":240, "6h":360, "12h":720,
    "1d":1440, "3d":4320, "1w":10080,
}

def load_yaml(path: str) -> dict:
    with open(path, "r", encoding="utf-8") as f:
        return yaml.safe_load(f) or {}

def load_rules() -> dict:
    # Par défaut indicator_rules.yaml à la racine (ou INDICATOR_RULES_PATH)
    path = os.getenv("INDICATOR_RULES_PATH", "/app/indicator_rules.yaml")
    if not os.path.exists(path):
        # Fallback : indicatorv2.yaml si fourni
        alt = "/app/indicatorv2.yaml"
        if os.path.exists(alt):
            return load_yaml(alt)
        return {}
    return load_yaml(path)
