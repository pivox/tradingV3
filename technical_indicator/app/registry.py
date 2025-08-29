# technical_indicator/app/registry.py
from __future__ import annotations
import pandas as pd
from typing import Dict, Any, Callable

# Import de tes modules d’indicateurs (adapter les chemins selon ton projet)
from indicators.rsi_indicator import compute_rsi
from indicators.macd_indicator import compute_macd
from indicators.ema_indicator import compute_ema
from indicators.supertrend_indicator import compute_supertrend
from indicators.bollinger_indicator import compute_bollinger

# Signature standard attendue : fn(df: pd.DataFrame, **kwargs) -> Dict[str, Any] ou DataFrame enrichi
# On normalise un peu pour retourner un dict simple.
Registry: Dict[str, Callable[..., Dict[str, Any]]] = {
    "rsi": lambda df, **kw: {"rsi": compute_rsi(df, **kw)},
    "macd": lambda df, **kw: compute_macd(df, **kw),  # suppose: {'macd':..., 'signal':..., 'hist':...}
    "ema": lambda df, **kw: {"ema": compute_ema(df, **kw)},
    "supertrend": lambda df, **kw: compute_supertrend(df, **kw),  # {'supertrend':..., 'direction':...}
    "bollinger": lambda df, **kw: compute_bollinger(df, **kw),    # {'bb_upper':..., 'bb_lower':..., ...}
}

def run_indicator(name: str, df: pd.DataFrame, **kwargs) -> Dict[str, Any]:
    name = name.lower()
    if name not in Registry:
        raise ValueError(f"Unknown indicator '{name}'")
    return Registry[name](df, **kwargs)
