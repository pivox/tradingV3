# technical_indicator/app/rules_engine.py
from __future__ import annotations
import pandas as pd
from typing import Dict, Any, List, Tuple
from .registry import run_indicator

def build_dataframe(klines: List[dict]) -> pd.DataFrame:
    # klines triés par timestamp ASC
    df = pd.DataFrame(klines, columns=["timestamp","open","high","low","close","volume"])
    df = df.sort_values("timestamp", ascending=True).reset_index(drop=True)
    return df

def evaluate_rules(rules_cfg: Dict[str, Any], df: pd.DataFrame) -> Tuple[bool, str, float, List[str], Dict[str, Any]]:
    """
    Très simple moteur de règles :
    rules_cfg exemple:
    {
      "timeframes": {
         "4h": {
            "conditions": [
               {"indicator":"rsi","params":{"period":14}, "op":"<", "value":30, "weight":0.5, "side":"LONG", "reason":"RSI < 30"},
               {"indicator":"macd","params":{"fast":12,"slow":26,"signal":9}, "op":">", "value":0, "weight":0.5, "side":"LONG", "reason":"MACD > 0"}
            ],
            "threshold": 0.8
         },
         "1m": { ... }
      }
    }
    """
    reasons: List[str] = []
    debug: Dict[str, Any] = {}

    tf_rules = rules_cfg.get("timeframes", {}).get(debug.get("timeframe", ""), None)
    # On ne connaît pas le timeframe ici; on applique plus haut. Cette fn reçoit déjà le bon tf_rules.
    conditions = tf_rules.get("conditions", []) if tf_rules else []
    threshold = float(tf_rules.get("threshold", 0.8)) if tf_rules else 0.8

    # On va calculer et agréger un score simple pondéré
    total_weight = 0.0
    score = 0.0
    decided_side = None

    # Cache indicateurs pour éviter recompute
    icache: Dict[str, Dict[str, Any]] = {}

    for cond in conditions:
        name = cond["indicator"]
        params = cond.get("params", {})
        op = cond.get("op", ">")
        value = float(cond.get("value", 0))
        weight = float(cond.get("weight", 1.0))
        side = cond.get("side")  # "LONG"|"SHORT"|None
        reason = cond.get("reason", f"{name} {op} {value}")

        if name not in icache:
            icache[name] = run_indicator(name, df, **params)

        # On cherche la dernière valeur pertinente
        # Conventions (à adapter selon tes retourneurs):
        last_val = None
        ind_res = icache[name]
        # Essaye quelques clés standard
        for key in ("value","rsi","macd","hist","signal","ema","supertrend","direction","bb_upper","bb_lower"):
            if key in ind_res:
                v = ind_res[key]
                # Si c'est une série Pandas, prend la dernière
                try:
                    if hasattr(v, "iloc"):
                        v = float(v.iloc[-1])
                except Exception:
                    pass
                if isinstance(v, (int,float)):
                    last_val = float(v)
                    break
        if last_val is None:
            # sinon, essaye value=close dernier
            last_val = float(df["close"].iloc[-1])

        passed = compare(last_val, op, value)
        total_weight += weight
        if passed:
            score += weight
            reasons.append(reason)
            if side and decided_side is None:
                decided_side = side

    normalized = (score / total_weight) if total_weight > 0 else 0.0
    is_valid = normalized >= threshold
    if not is_valid:
        decided_side = None

    debug.update({
        "total_weight": total_weight,
        "raw_score": score,
        "normalized": normalized,
        "conditions_count": len(conditions),
        "indicators_cache_keys": list(icache.keys()),
    })

    return is_valid, (decided_side or "LONG"), normalized, reasons, debug

def compare(a: float, op: str, b: float) -> bool:
    if op == ">":  return a > b
    if op == ">=": return a >= b
    if op == "<":  return a < b
    if op == "<=": return a <= b
    if op == "==": return a == b
    if op == "!=": return a != b
    # default
    return False
