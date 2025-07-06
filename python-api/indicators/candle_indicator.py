import pandas as pd

def compute_candle_pattern(closes: list, highs: list, lows: list, opens: list) -> str:
    # Simplifié : on regarde la dernière bougie
    last_open = opens[-1]
    last_close = closes[-1]
    last_high = highs[-1]
    last_low = lows[-1]

    candle_body = abs(last_close - last_open)
    upper_wick = last_high - max(last_open, last_close)
    lower_wick = min(last_open, last_close) - last_low

    # Rejet bas (bougie verte avec mèche basse dominante)
    if last_close > last_open and lower_wick > 1.5 * candle_body:
        return "rejet_bas"

    # Rejet haut (bougie rouge avec mèche haute dominante)
    if last_open > last_close and upper_wick > 1.5 * candle_body:
        return "rejet_haut"

    return "aucun"

def validate_candle_conditions(closes: list, highs: list, lows: list, opens: list, direction: str) -> bool:
    pattern = compute_candle_pattern(closes, highs, lows, opens)

    if direction == 'long':
        return pattern == "rejet_bas"
    elif direction == 'short':
        return pattern == "rejet_haut"
    return False
