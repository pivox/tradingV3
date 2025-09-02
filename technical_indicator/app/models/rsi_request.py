from pydantic import BaseModel, Field
from typing import List, Optional
from .kline import Kline

class RSIRequest(BaseModel):
    contract: str = Field(..., description="Ex: BTCUSDT")
    timeframe: str = Field(..., description="Ex: 1m,5m,15m,1h,4h")
    length: int = Field(14, ge=2, le=300, description="Période RSI, par défaut 14")
    n: Optional[int] = Field(None, ge=1, description="Nombre de dernières bougies à considérer (optionnel)")
    klines: List[Kline] = Field(default_factory=list, description="Bougies OHLCV (ordre chrono ascendant)")
