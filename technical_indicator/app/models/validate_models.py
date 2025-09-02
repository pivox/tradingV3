from pydantic import BaseModel
from typing import List, Optional
from .kline import Kline

class ValidateRequest(BaseModel):
    contract: str
    timeframe: str
    klines: List[Kline]

class ValidateResponse(BaseModel):
    valid: bool
    side: Optional[str] = None  # LONG|SHORT
    score: Optional[float] = None
    reasons: Optional[List[str]] = None
    debug: Optional[dict] = None
