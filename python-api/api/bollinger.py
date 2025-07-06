from fastapi import APIRouter
from indicators.bollinger_indicator import compute_bollinger_bands, validate_bollinger_conditions
from model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/bollinger")
def run_bollinger(request: IndicatorRequest):
    closes = [k.close for k in request.klines]

    sma, upper, lower = compute_bollinger_bands(closes)
    result_long = validate_bollinger_conditions(closes, direction='long')
    result_short = validate_bollinger_conditions(closes, direction='short')

    return {
        "indicator": "bollinger",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "extra": {
            "sma": round(float(sma.iloc[-1]), 4),
            "upper_band": round(float(upper.iloc[-1]), 4),
            "lower_band": round(float(lower.iloc[-1]), 4),
            "result": {
                "long": bool(result_long),
                "short": bool(result_short)
            }
        }
    }
