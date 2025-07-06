import pandas as pd

def compute_vwap(highs: list, lows: list, closes: list, volumes: list):
    typical_price = (pd.Series(highs) + pd.Series(lows) + pd.Series(closes)) / 3
    cumulative_tp_vol = (typical_price * pd.Series(volumes)).cumsum()
    cumulative_vol = pd.Series(volumes).cumsum()
    vwap = cumulative_tp_vol / cumulative_vol
    return vwap


def validate_vwap_conditions(closes: list, highs: list, lows: list, volumes: list, direction: str) -> bool:
    vwap = compute_vwap(highs, lows, closes, volumes)

    last_price = closes[-1]
    last_vwap = vwap.iloc[-1]

    if direction == 'long':
        return bool(last_price > last_vwap)

    if direction == 'short':
        return bool(last_price < last_vwap)

    return False