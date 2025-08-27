import pandas as pd

def compute_volume_indicator(volumes: list, multiplier: float = 1.5):
    series = pd.Series(volumes)
    avg_volume = series.rolling(window=20).mean().iloc[-1]
    last_volume = series.iloc[-1]
    return round(last_volume, 2), round(avg_volume, 2)

def validate_volume_conditions(volumes: list, direction: str, multiplier: float = 1.5) -> bool:
    last_volume, avg_volume = compute_volume_indicator(volumes, multiplier)
    if direction == "long":
        result = last_volume > avg_volume * multiplier
    elif direction == "short":
        result = last_volume > avg_volume * multiplier
    else:
        result = False
    return bool(result), last_volume, avg_volume
