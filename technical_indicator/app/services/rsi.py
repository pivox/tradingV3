import pandas as pd
import numpy as np

def compute_rsi_from_df(df: pd.DataFrame, length: int = 14) -> pd.Series:
    closes = df["close"].astype(float)
    delta = closes.diff()
    gain = delta.clip(lower=0)
    loss = -delta.clip(upper=0)
    avg_gain = gain.ewm(alpha=1/length, adjust=False).mean()
    avg_loss = loss.ewm(alpha=1/length, adjust=False).mean()
    rs = avg_gain / avg_loss.replace(0, np.nan)
    rsi = 100 - (100 / (1 + rs))
    return rsi

def rsi_signal_features(rsi: pd.Series) -> dict:
    last = float(rsi.iloc[-1])
    prev = float(rsi.iloc[-2]) if len(rsi) >= 2 else last
    slope_up = last > prev
    slope_down = last < prev
    crossed_above_30 = prev < 30 <= last
    crossed_below_70 = prev > 70 >= last
    return {
        "last": last,
        "prev": prev,
        "slope_up": slope_up,
        "slope_down": slope_down,
        "crossed_above_30": crossed_above_30,
        "crossed_below_70": crossed_below_70,
    }
