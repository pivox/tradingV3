from fastapi import APIRouter
from indicators.ema_indicator import compute_ema, validate_ema_conditions
from model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/ema")
def run_ema(request: IndicatorRequest):
    closes = [k.close for k in request.klines]

    result_long, ema9_long, ema21_long, ema200_long = validate_ema_conditions(closes, direction="long")
    result_short, ema9_short, ema21_short, ema200_short = validate_ema_conditions(closes, direction="short")

    return {
        "indicator": "ema",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "results": {
            "long": {
                "result": result_long,
                "ema9": ema9_long,
                "ema21": ema21_long,
                "ema200": ema200_long
            },
            "short": {
                "result": result_short,
                "ema9": ema9_short,
                "ema21": ema21_short,
                "ema200": ema200_short
            }
        }
    }
