import pandas as pd


def compute_rsi(closes: list, period: int = 14):
    series = pd.Series(closes)
    delta = series.diff()
    gain = delta.clip(lower=0)
    loss = -delta.clip(upper=0)

    avg_gain = gain.rolling(window=period, min_periods=1).mean()
    avg_loss = loss.rolling(window=period, min_periods=1).mean()

    rs = avg_gain / avg_loss
    rsi = 100 - (100 / (1 + rs))

    return rsi


def validate_rsi_conditions(closes, periods=[6, 14], direction=None):
    results = {}
    for period in periods:
        rsi_series = compute_rsi(closes, period)
        last_rsi = rsi_series.iloc[-1]

        if direction == 'long':
            if last_rsi > 78 and period == 6:
                return True
            if last_rsi > 70 and period == 14:
                return True
        elif direction == 'short':
            if last_rsi < 22 and period == 6:
                return True
            if last_rsi < 30 and period == 14:
                return True
        else:
            long_cond = (last_rsi > 78 and period == 6) or (last_rsi > 70 and period == 14)
            short_cond = (last_rsi < 22 and period == 6) or (last_rsi < 30 and period == 14)
            results['long'] = results.get('long', False) or long_cond
            results['short'] = results.get('short', False) or short_cond

    if direction:
        return False
    return results.get('long', False), results.get('short', False)