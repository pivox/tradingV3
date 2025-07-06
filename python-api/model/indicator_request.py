from pydantic import BaseModel
from typing import List, Optional

from model.kline import Kline


class IndicatorRequest(BaseModel):
    klines: List[Kline]
    symbol: str
    timeframe: Optional[str] = None  # 'long' ou 'short'