from pydantic import BaseModel, Field

class Kline(BaseModel):
    timestamp: int = Field(..., description="Unix timestamp (seconds)")
    open: float
    high: float
    low: float
    close: float
    volume: float
