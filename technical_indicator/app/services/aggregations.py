import pandas as pd

def df_from_klines(klines: list[dict]) -> pd.DataFrame:
    df = pd.DataFrame(klines)
    df = df.sort_values("timestamp").reset_index(drop=True)
    return df

def trim_last_n(df: pd.DataFrame, n: int | None) -> pd.DataFrame:
    if n is None:
        return df
    return df.tail(n).reset_index(drop=True)

def build_dataframe_sorted(rows: list[dict]) -> pd.DataFrame:
    df = pd.DataFrame(rows)
    # colonnes attendues: timestamp, open, high, low, close, volume
    if "timestamp" in df.columns:
        df = df.sort_values("timestamp", ascending=True).drop_duplicates(subset=["timestamp"])
        df = df.set_index(pd.to_datetime(df["timestamp"], unit="s"), drop=True)
    return df
