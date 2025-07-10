from fastapi import APIRouter
from technical_indicator.indicators.stochastic_rsi_indicator import compute_stochastic_rsi, validate_stochastic_rsi_conditions
from technical_indicator.model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/stochastic_rsi")
def run_stochastic_rsi(request: IndicatorRequest):
    closes = [k.close for k in request.klines]

    result_long, last_stoch_long = validate_stochastic_rsi_conditions(closes, direction="long")
    result_short, last_stoch_short = validate_stochastic_rsi_conditions(closes, direction="short")

    return {
        "indicator": "stochastic_rsi",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "results": {
            "long": {
                "result": result_long,
                "stoch_rsi": last_stoch_long
            },
            "short": {
                "result": result_short,
                "stoch_rsi": last_stoch_short
            }
        }
    }
