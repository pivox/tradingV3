import os, yaml

DEFAULT_RULES = {
    "timeframes": {
        "5m": {
            "rsi": {
                "length": 14,
                "score": {
                    "long": [
                        {"condition": "crosses_above:30", "weight": 0.6},
                        {"condition": "slope_up", "weight": 0.4}
                    ],
                    "short": [
                        {"condition": "crosses_below:70", "weight": 0.6},
                        {"condition": "slope_down", "weight": 0.4}
                    ]
                },
                "decision": {
                    "long_min_score": 0.7,
                    "short_min_score": 0.7
                }
            }
        },
        "1h": {"rsi": {"length": 14, "score": {"long": [], "short": []}, "decision": {"long_min_score": 0.7, "short_min_score": 0.7}}},
        "4h": {"rsi": {"length": 14, "score": {"long": [], "short": []}, "decision": {"long_min_score": 0.7, "short_min_score": 0.7}}}
    }
}

def load_rules(path: str | None = None) -> dict:
    path = path or os.getenv("INDICATOR_RULES_PATH", "")
    if path and os.path.exists(path):
        with open(path, "r", encoding="utf-8") as f:
            return yaml.safe_load(f) or DEFAULT_RULES
    return DEFAULT_RULES
