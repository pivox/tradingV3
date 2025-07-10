import pandas as pd

def compute_adx(highs: list, lows: list, closes: list, period: int = 14):
    df = pd.DataFrame({"high": highs, "low": lows, "close": closes})
    df["tr"] = df["high"].combine(df["low"], max) - df["low"].combine(df["high"], min)
    df["plus_dm"] = df["high"].diff()
    df["minus_dm"] = df["low"].diff().abs()

    df["plus_dm"] = df.apply(lambda row: row["plus_dm"] if row["plus_dm"] > row["minus_dm"] and row["plus_dm"] > 0 else 0, axis=1)
    df["minus_dm"] = df.apply(lambda row: row["minus_dm"] if row["minus_dm"] > row["plus_dm"] and row["minus_dm"] > 0 else 0, axis=1)

    tr_smooth = df["tr"].rolling(window=period).sum()
    plus_dm_smooth = df["plus_dm"].rolling(window=period).sum()
    minus_dm_smooth = df["minus_dm"].rolling(window=period).sum()

    plus_di = 100 * (plus_dm_smooth / tr_smooth)
    minus_di = 100 * (minus_dm_smooth / tr_smooth)

    dx = (abs(plus_di - minus_di) / (plus_di + minus_di)) * 100
    adx = dx.rolling(window=period).mean()

    return plus_di, minus_di, adx


def validate_adx_conditions(plus_di_series, minus_di_series, adx_series, direction: str) -> bool:
    # ✅ Prendre uniquement les dernières valeurs scalaires
    plus_di = plus_di_series.iloc[-1]
    minus_di = minus_di_series.iloc[-1]
    adx = adx_series.iloc[-1]

    if direction == 'long':
        return bool(plus_di > minus_di and adx > 25)
    elif direction == 'short':
        return bool(plus_di < minus_di and adx > 25)
    else:
        return False
