from fastapi import APIRouter
from technical_indicator.indicators.vwap_indicator import compute_vwap, validate_vwap_conditions
from technical_indicator.model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/vwap")
def run_vwap(request: IndicatorRequest):
    closes = [k.close for k in request.klines]
    highs = [k.high for k in request.klines]
    lows = [k.low for k in request.klines]
    volumes = [k.volume for k in request.klines]

    result_long = validate_vwap_conditions(closes, highs, lows, volumes, direction='long')
    result_short = validate_vwap_conditions(closes, highs, lows, volumes, direction='short')

    vwap_series = compute_vwap(highs, lows, closes, volumes)
    last_vwap = round(vwap_series.iloc[-1], 4)

    return {
        "indicator": "vwap",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "vwap": last_vwap,
        "results": {
            "long": result_long,
            "short": result_short
        }
    }