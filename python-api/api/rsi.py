from fastapi import APIRouter
from indicators.rsi_indicator import compute_rsi, validate_rsi_conditions
from model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/rsi")
def run_rsi(request: IndicatorRequest):
    closes = [k.close for k in request.klines]
    periods = [6, 14]

    rsi_values = {p: round(compute_rsi(closes, p).iloc[-1], 2) for p in periods}

    # ✅ On vérifie les deux directions sans demander de paramètre
    result_long = bool(validate_rsi_conditions(closes, periods=periods, direction='long'))
    result_short = bool(validate_rsi_conditions(closes, periods=periods, direction='short'))

    return {
        "indicator": "rsi",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "extra": {
            "rsi": rsi_values
        },
        "results": {
            "long": result_long,
            "short": result_short
        }
    }
