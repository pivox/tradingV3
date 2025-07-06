from fastapi import APIRouter
from indicators.supertrend_indicator import compute_supertrend, validate_supertrend
from model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/supertrend")
def run_supertrend(request: IndicatorRequest):
    highs = [k.high for k in request.klines]
    lows = [k.low for k in request.klines]
    closes = [k.close for k in request.klines]

    period = 10
    multiplier = 3.0

    upper_band, lower_band, trend = compute_supertrend(highs, lows, closes, period, multiplier)

    last_upper = round(upper_band.iloc[-1], 4)
    last_lower = round(lower_band.iloc[-1], 4)
    last_trend = bool(trend.iloc[-1])  # True = Long, False = Short

    result_long = validate_supertrend(closes, highs, lows, direction='long', period=period, multiplier=multiplier)
    result_short = validate_supertrend(closes, highs, lows, direction='short', period=period, multiplier=multiplier)

    return {
        "indicator": "supertrend",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "supertrend": {
            "upper_band": last_upper,
            "lower_band": last_lower,
            "trend": "long" if last_trend else "short"
        },
        "results": {
            "long": result_long,
            "short": result_short
        }
    }
