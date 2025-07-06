from fastapi import APIRouter
from indicators.macd_indicator import validate_macd_conditions, compute_macd
from model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/macd")
def run_macd(request: IndicatorRequest):
    closes = [k.close for k in request.klines]

    macd_line, signal_line, histogram = compute_macd(closes)

    result_long, result_short = validate_macd_conditions(closes)

    return {
        "indicator": "macd",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "extra": {"macd": {
            "macd": round(macd_line.iloc[-1], 4),
            "signal": round(signal_line.iloc[-1], 4),
            "histogram": round(histogram.iloc[-1], 4)
        }},
        "results": {
            "long": result_long,
            "short": result_short
        }
    }