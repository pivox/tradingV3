# config/config_loader.py

import yaml
from pathlib import Path
from typing import Any, Dict, Optional, List


class ConfigLoader:
    def __init__(self, config_path: str = "config/indicator_rules.yaml"):
        self.config_path = Path(config_path)
        self.config = self._load_config()

    def _load_config(self) -> Dict[str, Any]:
        if not self.config_path.exists():
            raise FileNotFoundError(f"Configuration file not found: {self.config_path}")
        with open(self.config_path, "r") as f:
            return yaml.safe_load(f)

    def get_strategy(self) -> Dict[str, Any]:
        return self.config.get("strategy", {})

    def is_long_enabled(self) -> bool:
        return self.get_strategy().get("long_enabled", False)

    def is_short_enabled(self) -> bool:
        return self.get_strategy().get("short_enabled", False)

    def get_min_score_required(self) -> float:
        return float(self.get_strategy().get("min_score_required", 0.0))

    def get_indicator_rule(self, direction: str, name: str) -> Optional[Dict[str, Any]]:
        """
        direction: 'long' or 'short'
        name: name of the indicator (e.g. 'rsi', 'ema', etc.)
        """
        return self.config.get("indicators", {}).get(direction, {}).get(name)

    def is_indicator_enabled(self, direction: str, name: str) -> bool:
        rule = self.get_indicator_rule(direction, name)
        return rule is not None and rule.get("enabled", False)

    def get_indicator_score(self, direction: str, name: str) -> float:
        rule = self.get_indicator_rule(direction, name)
        return float(rule.get("score", 0.0)) if rule else 0.0

    def get_indicator_timeframes(self, direction: str, name: str) -> List[str]:
        rule = self.get_indicator_rule(direction, name)
        return rule.get("timeframes", []) if rule else []

    def get_indicator_condition(self, direction: str, name: str) -> Optional[str]:
        rule = self.get_indicator_rule(direction, name)
        return rule.get("condition") if rule else None

    def get_indicator_field(self, direction: str, name: str, field: str) -> Any:
        rule = self.get_indicator_rule(direction, name)
        return rule.get(field) if rule else None

    def get_all_indicators(self, direction: str) -> List[str]:
        """
        Retourne tous les indicateurs définis dans une direction (long ou short)
        """
        return list(self.config.get("indicators", {}).get(direction, {}).keys())

    def get_list_by_timeframe(self, direction: str, timeframe: str) -> List[str]:
        """
        Retourne la liste des indicateurs actifs pour une direction ('long' ou 'short')
        et un timeframe donné (ex: '5m', '1h', etc.)
        """
        result = []
        indicators = self.config.get("indicators", {}).get(direction, {})
        for name, rule in indicators.items():
            if rule.get("enabled", False) and timeframe in rule.get("timeframes", []):
                result.append(name)
        return result

    def get_list_config_by_timeframe(self, direction: str, timeframe: str) -> Dict[str, Dict[str, Any]]:
        """
        Retourne un dictionnaire {nom_indicateur: config} pour les indicateurs actifs
        sur un timeframe donné, selon la direction ('long' ou 'short').
        """
        result = {}
        indicators = self.config.get("indicators", {}).get(direction, {})
        for name, rule in indicators.items():
            if rule.get("enabled", False) and timeframe in rule.get("timeframes", []):
                result[name] = rule
        return result