# app/rules/engine.py
from __future__ import annotations
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Tuple, Union

import json
import math
import os
from pathlib import Path

import numpy as np
import pandas as pd


# =============== CONVERSIONS PROFONDES (NumPy/Pandas -> Python natif) ===============
def _deep_to_py(obj: Any) -> Any:
    """Conversion récursive vers types natifs (dict/list/tuple/scalaires)."""
    # scalaires numpy
    if isinstance(obj, (np.bool_,)):
        return bool(obj)
    if isinstance(obj, (np.integer,)):
        return int(obj)
    if isinstance(obj, (np.floating,)):
        x = float(obj)
        if math.isnan(x) or math.isinf(x):
            return None
        return x
    # timestamps pandas
    if isinstance(obj, (pd.Timestamp,)):
        # renvoie un int epoch (secondes)
        return int(obj.timestamp())
    # séries/dataframes -> listes/dicts simples
    if isinstance(obj, pd.Series):
        return [_deep_to_py(v) for v in obj.tolist()]
    if isinstance(obj, pd.DataFrame):
        return [_deep_to_py(row) for _, row in obj.iterrows()]
    # containers
    if isinstance(obj, dict):
        return {str(k): _deep_to_py(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple, set)):
        return [_deep_to_py(v) for v in obj]
    # floats Python: normaliser NaN/Inf
    if isinstance(obj, float):
        if math.isnan(obj) or math.isinf(obj):
            return None
        return obj
    # bool/int/str/None restent tels quels
    return obj


def _ensure_dump_dir() -> Path:
    p = Path("/tmp/indicator_engine")
    try:
        p.mkdir(parents=True, exist_ok=True)
    except Exception:
        pass
    return p


def _dump_json(name: str, data: Any) -> None:
    """Dump JSON *après* conversion profonde (et ignorer les erreurs d’I/O)."""
    try:
        p = _ensure_dump_dir() / name
        with p.open("w", encoding="utf-8") as f:
            json.dump(_deep_to_py(data), f, ensure_ascii=False, indent=2)
    except Exception:
        pass


# ========================= RSI =========================
def _rsi(close: pd.Series, length: int = 14) -> pd.Series:
    """RSI EMA-like, stable/déterministe, renvoie 0..100 sans NaN en fin."""
    if not isinstance(close, pd.Series):
        close = pd.Series(close)

    delta = close.diff()
    gain = delta.clip(lower=0.0)
    loss = -delta.clip(upper=0.0)

    alpha = 1.0 / float(length)
    avg_gain = gain.ewm(alpha=alpha, adjust=False, min_periods=length).mean()
    avg_loss = loss.ewm(alpha=alpha, adjust=False, min_periods=length).mean()

    rs = avg_gain / avg_loss.replace(0, np.nan)
    rsi = 100.0 - (100.0 / (1.0 + rs))

    # éviter FutureWarning: utiliser bfill/ffill modernes
    rsi = rsi.bfill().ffill()
    return rsi


# =============== FEATURES RSI ===============
@dataclass
class RsiFeatures:
    rsi_last: float
    rsi_prev: float
    crosses_above: Dict[float, bool]
    crosses_below: Dict[float, bool]
    slope_up: bool
    slope_down: bool
    delta_last: float
    delta_3: float
    delta_5: float
    recent_min_5: float
    recent_max_5: float

    def as_dict(self) -> Dict[str, Any]:
        return {
            "rsi_last": self.rsi_last,
            "rsi_prev": self.rsi_prev,
            "crosses_above": self.crosses_above,
            "crosses_below": self.crosses_below,
            "slope_up": self.slope_up,
            "slope_down": self.slope_down,
            "delta_last": self.delta_last,
            "delta_3": self.delta_3,
            "delta_5": self.delta_5,
            "recent_min_5": self.recent_min_5,
            "recent_max_5": self.recent_max_5,
        }


def _linear_slope(y: pd.Series) -> float:
    n = len(y)
    if n < 2:
        return 0.0
    x = np.arange(n, dtype=float)
    m, _ = np.polyfit(x, y.astype(float), 1)
    return float(m)


def _build_rsi_features(rsi: pd.Series) -> RsiFeatures:
    if not rsi.index.is_monotonic_increasing:
        rsi = rsi.sort_index()

    last = float(rsi.iloc[-1])
    prev = float(rsi.iloc[-2]) if len(rsi) >= 2 else last

    levels = [25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75]
    crosses_above = {float(l): bool((prev <= l) and (last > l)) for l in levels}
    crosses_below = {float(l): bool((prev >= l) and (last < l)) for l in levels}

    win = 5 if len(rsi) >= 5 else min(3, len(rsi))
    slope = _linear_slope(rsi.tail(win))
    slope_up = bool(slope > 0.0)
    slope_down = bool(slope < 0.0)

    delta_last = float(last - prev)
    delta_3 = float(last - float(rsi.iloc[-4])) if len(rsi) >= 4 else delta_last
    delta_5 = float(last - float(rsi.iloc[-6])) if len(rsi) >= 6 else delta_3

    last5 = rsi.tail(5) if len(rsi) >= 5 else rsi
    recent_min_5 = float(last5.min())
    recent_max_5 = float(last5.max())

    return RsiFeatures(
        rsi_last=last,
        rsi_prev=prev,
        crosses_above=crosses_above,
        crosses_below=crosses_below,
        slope_up=slope_up,
        slope_down=slope_down,
        delta_last=delta_last,
        delta_3=delta_3,
        delta_5=delta_5,
        recent_min_5=recent_min_5,
        recent_max_5=recent_max_5,
    )


# =============== CONDITIONS ===============
def _cond_crosses_above(feats: RsiFeatures, spec: Union[int, float, Dict[str, Any]]) -> bool:
    level = None
    if isinstance(spec, (int, float)):
        level = float(spec)
    elif isinstance(spec, dict):
        level = float(spec.get("level", 50))
    return bool(feats.crosses_above.get(level, False)) if level is not None else False


def _cond_crosses_below(feats: RsiFeatures, spec: Union[int, float, Dict[str, Any]]) -> bool:
    level = None
    if isinstance(spec, (int, float)):
        level = float(spec)
    elif isinstance(spec, dict):
        level = float(spec.get("level", 50))
    return bool(feats.crosses_below.get(level, False)) if level is not None else False


def _cond_above(feats: RsiFeatures, lvl: Union[int, float]) -> bool:
    return bool(feats.rsi_last > float(lvl))


def _cond_below(feats: RsiFeatures, lvl: Union[int, float]) -> bool:
    return bool(feats.rsi_last < float(lvl))


def _cond_slope_up(feats: RsiFeatures, flag: bool) -> bool:
    return bool(flag) and bool(feats.slope_up)


def _cond_slope_down(feats: RsiFeatures, flag: bool) -> bool:
    return bool(flag) and bool(feats.slope_down)


def _cond_delta_min(feats: RsiFeatures, val: Union[int, float]) -> bool:
    try:
        return bool((feats.rsi_last - feats.recent_min_5) >= float(val))
    except Exception:
        return False


def _cond_rsi_between(feats: RsiFeatures, bounds: List[Union[int, float]]) -> bool:
    try:
        lo, hi = float(bounds[0]), float(bounds[1])
        return bool((feats.rsi_last >= lo) and (feats.rsi_last <= hi))
    except Exception:
        return False


def _eval_branch(branch: Any, feats: RsiFeatures) -> bool:
    # scalaire → crosses_above(level)
    if isinstance(branch, (int, float)):
        return _cond_crosses_above(feats, float(branch))
    # liste → all implicite
    if isinstance(branch, list):
        return all(_eval_branch(b, feats) for b in branch)
    # dict
    if not isinstance(branch, dict):
        return False
    if "all" in branch:
        return all(_eval_branch(b, feats) for b in branch["all"])
    if "any" in branch:
        return any(_eval_branch(b, feats) for b in branch["any"])
    if "crosses_above" in branch:
        return _cond_crosses_above(feats, branch["crosses_above"])
    if "crosses_below" in branch:
        return _cond_crosses_below(feats, branch["crosses_below"])
    if "above" in branch:
        return _cond_above(feats, branch["above"])
    if "below" in branch:
        return _cond_below(feats, branch["below"])
    if "slope_up" in branch:
        return _cond_slope_up(feats, branch["slope_up"])
    if "slope_down" in branch:
        return _cond_slope_down(feats, branch["slope_down"])
    if "delta_min" in branch:
        return _cond_delta_min(feats, branch["delta_min"])
    if "rsi_between" in branch:
        return _cond_rsi_between(feats, branch["rsi_between"])
    return False


def _score_weighted(bools: Dict[str, bool], weights: Dict[str, float]) -> float:
    if not weights:
        return 0.0
    s = 0.0
    wsum = 0.0
    for k, w in weights.items():
        w = float(w)
        wsum += w
        s += w * (1.0 if bool(bools.get(k, False)) else 0.0)
    return float(0.0 if wsum <= 0.0 else s / wsum)


# =============== ÉVALUATION GLOBALE ===============
def evaluate_rules(
    rules: Dict[str, Any],
    timeframe: str,
    df: pd.DataFrame
) -> Tuple[bool, Optional[str], float, List[str], Dict[str, Any]]:
    """
    Retourne: (valid, side, score, reasons, debug)
    - df en ordre chronologique ASC, colonnes: ['timestamp','open','high','low','close','volume'].
    """
    if df is None or df.empty:
        return False, None, 0.0, ["empty_df"], {}

    if "timestamp" in df.columns:
        df = df.sort_values("timestamp", ascending=True).reset_index(drop=True)

    if "close" not in df.columns:
        return False, None, 0.0, ["missing_close_series"], {}

    tf_rules = (rules or {}).get("timeframes", {}).get(str(timeframe), None)
    if not tf_rules:
        return False, None, 0.0, ["no_rules_for_timeframe"], {"timeframe": timeframe}

    # style A (5m/15m/1h) vs style B (4h)
    style = "A" if "rsi" in tf_rules else "B" if "indicators" in tf_rules else "UNKNOWN"
    _dump_json(f"rules_dump_{timeframe}.json", tf_rules)

    if style == "A":
        rsi_cfg = tf_rules.get("rsi", {}) or {}
        length = int(rsi_cfg.get("length", 14))
    elif style == "B":
        rsi_cfg = (tf_rules.get("indicators", {}) or {}).get("rsi", {}) or {}
        length = int(rsi_cfg.get("length", 14))
    else:
        return False, None, 0.0, ["unknown_rules_style"], {"timeframe": timeframe}

    rsi = _rsi(df["close"].astype(float), length=length)
    feats = _build_rsi_features(rsi)

    reasons: List[str] = []
    long_score = 0.0
    short_score = 0.0
    long_pass = False
    short_pass = False

    if style == "A":
        # LONG
        long_any = (rsi_cfg.get("long", {}) or {}).get("any", [])
        long_ok = any(_eval_branch(b, feats) for b in (long_any if isinstance(long_any, list) else [long_any]))

        weights_long = ((rsi_cfg.get("score", {}) or {}).get("weights", {}) or {})
        bools_long = {
            "crosses_above": any(_cond_crosses_above(feats, lvl) for lvl in [30, 35, 40, 45, 50]),
            "slope_up": feats.slope_up,
            "above": feats.rsi_last > 40.0,
        }
        long_score = _score_weighted(bools_long, {k: float(v) for k, v in weights_long.items()})
        pass_th = float(((rsi_cfg.get("score", {}) or {}).get("pass_threshold", 0.5)))
        long_pass = bool(long_ok or (long_score >= pass_th))
        if long_pass:
            reasons.append("long_pass")

        # SHORT
        short_any = (rsi_cfg.get("short", {}) or {}).get("any", [])
        short_ok = any(_eval_branch(b, feats) for b in (short_any if isinstance(short_any, list) else [short_any]))

        weights_short = ((rsi_cfg.get("score", {}) or {}).get("weights", {}) or {})
        bools_short = {
            "crosses_below": any(_cond_crosses_below(feats, lvl) for lvl in [50, 55, 60, 65, 70]),
            "slope_down": feats.slope_down,
            "below": feats.rsi_last < 60.0,
        }
        short_score = _score_weighted(bools_short, {k: float(v) for k, v in weights_short.items()})
        short_pass = bool(short_ok or (short_score >= pass_th))
        if short_pass:
            reasons.append("short_pass")

    elif style == "B":
        # 4h
        long_any = (tf_rules.get("long", {}) or {}).get("any", [])
        long_ok = any(_eval_branch(b, feats) for b in (long_any if isinstance(long_any, list) else [long_any]))
        w_long = (tf_rules.get("long", {}) or {}).get("weight", {}) or {}
        bools_long = {
            "rsi_between": _cond_rsi_between(feats, [40, 65]),
            "crosses_above": _cond_crosses_above(feats, {"level": 50}),
        }
        long_score = _score_weighted(bools_long, {k: float(v) for k, v in w_long.items()})
        min_score_long = float((tf_rules.get("long", {}) or {}).get("min_score", 0.4))
        long_pass = bool(long_ok or (long_score >= min_score_long))
        if long_pass:
            reasons.append("long_pass")

        short_any = (tf_rules.get("short", {}) or {}).get("any", [])
        short_ok = any(_eval_branch(b, feats) for b in (short_any if isinstance(short_any, list) else [short_any]))
        w_short = (tf_rules.get("short", {}) or {}).get("weight", {}) or {}
        bools_short = {
            "rsi_between": _cond_rsi_between(feats, [35, 60]),
            "crosses_below": _cond_crosses_below(feats, {"level": 50}),
        }
        short_score = _score_weighted(bools_short, {k: float(v) for k, v in w_short.items()})
        min_score_short = float((tf_rules.get("short", {}) or {}).get("min_score", 0.4))
        short_pass = bool(short_ok or (short_score >= min_score_short))
        if short_pass:
            reasons.append("short_pass")

    # Décision
    valid = bool(long_pass or short_pass)
    side: Optional[str] = None
    score = 0.0
    if long_pass and short_pass:
        if long_score >= short_score:
            side, score = "LONG", float(long_score)
            reasons.append("both_pass_long_preferred")
        else:
            side, score = "SHORT", float(short_score)
            reasons.append("both_pass_short_preferred")
    elif long_pass:
        side, score = "LONG", float(long_score)
    elif short_pass:
        side, score = "SHORT", float(short_score)
    else:
        side, score = None, float(max(long_score, short_score))

    debug = {
        "style": style,
        "timeframe": str(timeframe),
        "length": int(length),
        "rsi_last": float(feats.rsi_last),
        "rsi_prev": float(feats.rsi_prev),
        "long_score": float(long_score),
        "short_score": float(short_score),
        "long_pass": bool(long_pass),
        "short_pass": bool(short_pass),
    }

    # Traces (après conversion profonde)
    _dump_json(f"features_{timeframe}.json", feats.as_dict())
    _dump_json(f"decision_{timeframe}.json", {
        "valid": valid,
        "side": side,
        "score": score,
        "reasons": reasons,
        **debug,
    })

    # Conversion finale pour FastAPI/Pydantic
    return (
        bool(valid),
        None if side is None else str(side),
        float(score),
        [str(r) for r in reasons],
        _deep_to_py(debug),
    )
