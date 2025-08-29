# technical_indicator/app/models.py
from pydantic import BaseModel, Field
from typing import List, Literal, Optional, Dict, Any

class Kline(BaseModel):
    timestamp: int
    open: float
    high: float
    low: float
    close: float
    volume: float

class ValidateRequest(BaseModel):
    contract: str = Field(..., min_length=1)
    timeframe: Literal["1m","3m","5m","15m","30m","1h","2h","4h","6h","12h","1d","3d","1w"]
    klines: List[Kline] = Field(..., min_items=10)

class ValidateResponse(BaseModel):
    valid: bool
    side: Optional[Literal["LONG","SHORT"]] = None
    score: Optional[float] = None
    reasons: Optional[List[str]] = None
    debug: Optional[Dict[str, Any]] = None
