import pandas as pd

def compute_macd(closes: list, fast: int = 12, slow: int = 26, signal: int = 9):
    series = pd.Series(closes)
    ema_fast = series.ewm(span=fast, adjust=False).mean()
    ema_slow = series.ewm(span=slow, adjust=False).mean()
    macd_line = ema_fast - ema_slow
    signal_line = macd_line.ewm(span=signal, adjust=False).mean()
    histogram = macd_line - signal_line
    return macd_line, signal_line, histogram


def validate_macd_conditions(closes: list):
    macd_line, signal_line, hist = compute_macd(closes)

    macd_value = macd_line.iloc[-1]
    signal_value = signal_line.iloc[-1]
    hist_value = hist.iloc[-1]

    result_long = bool(macd_value > signal_value and hist_value > 0)
    result_short = bool(macd_value < signal_value and hist_value < 0)

    return result_long, result_short
