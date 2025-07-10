from fastapi import APIRouter
from technical_indicator.indicators.candle_indicator import compute_candle_pattern, validate_candle_conditions
from technical_indicator.model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/candle")
def run_candle(request: IndicatorRequest):
    closes = [k.close for k in request.klines]
    highs = [k.high for k in request.klines]
    lows = [k.low for k in request.klines]
    opens = [k.open for k in request.klines]

    pattern = compute_candle_pattern(closes, highs, lows, opens)

    result_long = validate_candle_conditions(closes, highs, lows, opens, direction='long')
    result_short = validate_candle_conditions(closes, highs, lows, opens, direction='short')

    return {
        "indicator": "candle",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "pattern": pattern,
        "results": {
            "long": result_long,
            "short": result_short
        }
    }
