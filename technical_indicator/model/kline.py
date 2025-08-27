from pydantic import BaseModel
from typing import Optional

class Kline(BaseModel):
    open: float
    high: float
    low: float
    close: float
    volume: float
    timestamp: int  # Timestamp Unix (secondes ou millisecondes selon la source)
    turnover: Optional[float] = None  # Optionnel : valeur en USDT échangée
    symbol: Optional[str] = None     # Optionnel : utile pour debug ou suivi
    timeframe: Optional[str] = None  # Optionnel : ex. '1m', '5m', '15m'