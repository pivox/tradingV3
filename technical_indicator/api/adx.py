from fastapi import APIRouter
from technical_indicator.indicators.adx_indicator import compute_adx, validate_adx_conditions
from technical_indicator.model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/adx")
def run_adx(request: IndicatorRequest):
    highs = [k.high for k in request.klines]
    lows = [k.low for k in request.klines]
    closes = [k.close for k in request.klines]

    plus_di, minus_di, adx_series = compute_adx(highs, lows, closes)

    # Appel deux fois pour long et short
    result_long = validate_adx_conditions(plus_di, minus_di, adx_series, direction='long')
    result_short = validate_adx_conditions(plus_di, minus_di, adx_series, direction='short')

    last_plus = round(plus_di.iloc[-1], 4)
    last_minus = round(minus_di.iloc[-1], 4)
    last_adx = round(adx_series.iloc[-1], 4)

    return {
        "indicator": "adx",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "extra": {
            "adx": {
                "plus_di": last_plus,
                "minus_di": last_minus,
                "adx": last_adx
            }
        },
        "results": {
            "long": bool(result_long),
            "short": bool(result_short)
        }
    }
