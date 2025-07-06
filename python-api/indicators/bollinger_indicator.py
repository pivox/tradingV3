import pandas as pd

def compute_bollinger_bands(closes: list, period: int = 20, num_std: float = 2.0):
    series = pd.Series(closes)
    sma = series.rolling(window=period).mean()
    std = series.rolling(window=period).std()
    upper_band = sma + (num_std * std)
    lower_band = sma - (num_std * std)
    return sma, upper_band, lower_band

def validate_bollinger_conditions(closes: list, direction: str) -> bool:
    sma, upper, lower = compute_bollinger_bands(closes)
    last_close = closes[-1]

    if direction == "long":
        return bool(last_close > lower.iloc[-1] and closes[-2] <= lower.iloc[-2])
    elif direction == "short":
        return bool(last_close < upper.iloc[-1] and closes[-2] >= upper.iloc[-2])
    return False
