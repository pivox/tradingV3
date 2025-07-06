from fastapi import APIRouter
from indicators.volume_indicator import compute_volume_indicator, validate_volume_conditions
from model.indicator_request import IndicatorRequest

router = APIRouter()

@router.post("/indicator/volume")
def run_volume(request: IndicatorRequest):
    volumes = [k.volume for k in request.klines]

    result_long, last_vol_long, avg_vol_long = validate_volume_conditions(volumes, direction="long")
    result_short, last_vol_short, avg_vol_short = validate_volume_conditions(volumes, direction="short")

    return {
        "indicator": "volume",
        "symbol": request.symbol,
        "timeframe": request.timeframe,
        "results": {
            "long": {
                "result": result_long,
                "last_volume": last_vol_long,
                "avg_volume": avg_vol_long
            },
            "short": {
                "result": result_short,
                "last_volume": last_vol_short,
                "avg_volume": avg_vol_short
            }
        }
    }
