import pandas as pd
import numpy as np

def compute_supertrend(highs: list, lows: list, closes: list, period: int = 10, multiplier: float = 3.0):
    df = pd.DataFrame({
        'high': highs,
        'low': lows,
        'close': closes
    })

    df['hl2'] = (df['high'] + df['low']) / 2
    df['tr'] = df[['high', 'low', 'close']].max(axis=1) - df[['high', 'low', 'close']].min(axis=1)
    df['atr'] = df['tr'].rolling(window=period).mean()

    df['upper_band'] = df['hl2'] + (multiplier * df['atr'])
    df['lower_band'] = df['hl2'] - (multiplier * df['atr'])

    df['supertrend'] = True
    for i in range(1, len(df)):
        if df['close'][i] > df['upper_band'][i - 1]:
            df['supertrend'][i] = True
        elif df['close'][i] < df['lower_band'][i - 1]:
            df['supertrend'][i] = False
        else:
            df['supertrend'][i] = df['supertrend'][i - 1]
            if df['supertrend'][i]:
                df['lower_band'][i] = max(df['lower_band'][i], df['lower_band'][i - 1])
            else:
                df['upper_band'][i] = min(df['upper_band'][i], df['upper_band'][i - 1])

    return df['upper_band'], df['lower_band'], df['supertrend']

def validate_supertrend(closes: list, highs: list, lows: list, direction: str = None, period: int = 10, multiplier: float = 3.0) -> bool:
    upper, lower, supertrend = compute_supertrend(highs, lows, closes, period, multiplier)
    last_trend = supertrend.iloc[-1]

    if direction == 'long':
        return bool(last_trend)  # True → prix au-dessus
    elif direction == 'short':
        return not bool(last_trend)  # False → prix en-dessous

    return False
