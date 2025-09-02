from fastapi import APIRouter
from ...models.rsi_request import RSIRequest
from ...services.aggregations import df_from_klines, trim_last_n
from ...services.rsi import compute_rsi_from_df

router = APIRouter()

@router.post("/rsi")
def rsi(req: RSIRequest):
    if not req.klines or len(req.klines) < max(3, req.length + 1):
        return {"status": 400, "detail": "Not enough klines", "required_min": max(3, req.length + 1)}

    df = df_from_klines([k.model_dump() for k in req.klines])
    df = trim_last_n(df, req.n)
    rsi_series = compute_rsi_from_df(df, length=req.length)
    last_value = float(rsi_series.iloc[-1])
    return {
        "status": 200,
        "contract": req.contract,
        "timeframe": req.timeframe,
        "length": req.length,
        "n": req.n,
        "rsi_last": round(last_value, 4),
        "count": int(len(rsi_series))
    }
