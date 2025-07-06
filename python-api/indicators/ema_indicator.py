import pandas as pd

def compute_ema(series: list, period: int):
    return pd.Series(series).ewm(span=period, adjust=False).mean()

def validate_ema_conditions(closes: list, direction: str) -> bool:
    ema9 = compute_ema(closes, 9)
    ema21 = compute_ema(closes, 21)
    ema200 = compute_ema(closes, 200)

    last_price = closes[-1]
    last_ema9 = ema9.iloc[-1]
    last_ema21 = ema21.iloc[-1]
    last_ema200 = ema200.iloc[-1]

    if direction == "long":
        result = (last_ema9 > last_ema21) and (last_price > last_ema200)
    elif direction == "short":
        result = (last_ema9 < last_ema21) and (last_price < last_ema200)
    else:
        result = False

    # Cast en bool Python natif
    return bool(result), round(last_ema9, 4), round(last_ema21, 4), round(last_ema200, 4)
