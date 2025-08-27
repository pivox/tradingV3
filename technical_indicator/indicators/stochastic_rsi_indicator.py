import pandas as pd
import numpy as np

def compute_stochastic_rsi(closes: list, period: int = 14):
    close_series = pd.Series(closes)
    delta = close_series.diff()

    gain = (delta.where(delta > 0, 0)).rolling(window=period).mean()
    loss = (-delta.where(delta < 0, 0)).rolling(window=period).mean()

    rs = gain / loss
    rsi = 100 - (100 / (1 + rs))

    min_rsi = rsi.rolling(window=period).min()
    max_rsi = rsi.rolling(window=period).max()

    stoch_rsi = (rsi - min_rsi) / (max_rsi - min_rsi)
    stoch_rsi = stoch_rsi.fillna(0)

    return stoch_rsi

def validate_stochastic_rsi_conditions(closes: list, direction: str) -> (bool, float):
    stoch_rsi = compute_stochastic_rsi(closes)
    last_value = stoch_rsi.iloc[-1]

    if direction == "long":
        result = last_value < 0.2
    elif direction == "short":
        result = last_value > 0.8
    else:
        result = False

    # Cast en bool natif
    return bool(result), round(last_value, 4)
