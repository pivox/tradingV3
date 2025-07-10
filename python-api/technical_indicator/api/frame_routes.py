from fastapi import APIRouter
from technical_indicator.model.indicator_request import IndicatorRequest
from technical_indicator.api import rsi, macd, ema, bollinger, stochastic_rsi, vwap, volume, candle, supertrend, adx
import logging
import time

router = APIRouter()
logger = logging.getLogger("indicator_api")
logging.basicConfig(level=logging.INFO)

def run_all_indicators(request: IndicatorRequest):
    start = time.time()
    results = []

    modules = [
        rsi, macd, ema, bollinger, stochastic_rsi,
        vwap, volume, candle, supertrend, adx
    ]

    for module in modules:
        module_name = module.__name__.split('.')[-1]
        function_name = f"run_{module_name}"
        if hasattr(module, function_name):
            try:
                result = getattr(module, function_name)(request)
                results.append(result)
            except Exception as e:
                logger.error(f"❌ Erreur sur {module_name}: {str(e)}")
                results.append({"indicator": module_name, "error": str(e)})

    logger.info(f"✅ Fin traitement en {time.time() - start:.2f}s")
    return results

def create_frame_endpoint(forced_timeframe: str):
    def endpoint(request: IndicatorRequest):
        request.timeframe = forced_timeframe
        logger.info(f"✅ Traitement {forced_timeframe} lancé")
        return run_all_indicators(request)
    return endpoint

# ✅ Génération dynamique des endpoints
timeframes = ["1m", "5m", "15m", "1h", "4h"]

for tf in timeframes:
    path = f"/api/frame{tf}/run"
    router.add_api_route(path, create_frame_endpoint(tf), methods=["POST"])
