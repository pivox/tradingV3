# app/api/routers/validate.py
from __future__ import annotations
from fastapi import APIRouter, Depends, HTTPException
from typing import Dict, Any
import pandas as pd

from ...models.validate_models import ValidateRequest, ValidateResponse
from ...rules.loader import load_rules
from ...rules.engine import evaluate_rules  # signature: (rules_cfg, df)
from ...services.aggregations import build_dataframe_sorted

router = APIRouter(tags=["validate"])

@router.post("/validate", response_model=ValidateResponse)
def validate(req: ValidateRequest) -> ValidateResponse:
    # 1) Asserts de base
    if not req.klines or len(req.klines) < 10:
        raise HTTPException(status_code=400, detail="Not enough klines (>=10 required)")

    # 2) DataFrame trié ASC + colonnes standard
    df = build_dataframe_sorted([k.model_dump() for k in req.klines])  # close/volume/timestamp, ASC
    if "close" not in df.columns:
        raise HTTPException(status_code=400, detail="Missing 'close' in klines")

    # 3) Charger les règles complètes puis n’en garder que le timeframe demandé
    rules_all: Dict[str, Any] = load_rules()
    tf = req.timeframe

    tf_cfg = (rules_all or {}).get("timeframes", {}).get(tf)
    if not tf_cfg:
        # Aucun bloc pour ce timeframe → renvoyer un faux neutre explicite
        return ValidateResponse(
            valid=False,
            side=None,
            score=0.0,
            reasons=[f"No rules for timeframe '{tf}'"],
            debug={"timeframe": tf, "n": len(req.klines)}
        )

    rules_for_tf = {"timeframes": {tf: tf_cfg}}

    # 4) Évaluer
    valid, side, score, reasons, debug = evaluate_rules(rules_for_tf, df)

    return ValidateResponse(
        valid=bool(valid),
        side=side,
        score=float(score),
        reasons=reasons or None,
        debug=dict(debug or {}, contract=req.contract, timeframe=tf, n=len(df))
    )



def evaluate_rules(
    *args,
    **kwargs
) -> Tuple[bool, Optional[str], float, List[str], Dict[str, Any]]:
    """
    Accepte deux styles d'appel :
      A) evaluate_rules(tf_rules, df)
      B) evaluate_rules(rules_all, timeframe, df)

    Retourne: (valid, side, score, reasons, debug)
    """
    # --- Normalisation des arguments ---
    timeframe = None  # juste pour le debug
    if len(args) == 2:
        # Style A : (tf_rules, df)
        tf_rules, df = args
    elif len(args) == 3:
        # Style B : (rules_all, timeframe, df) -> on extrait tf_rules ici
        rules_all, timeframe, df = args
        rules_all = rules_all or {}
        tf = str(timeframe)
        tf_rules = (rules_all.get("timeframes") or {}).get(tf)
    else:
        raise TypeError("evaluate_rules expects (tf_rules, df) or (rules_all, timeframe, df)")

    # --- Garde-fous de base ---
    if df is None or df.empty:
        return False, None, 0.0, ["empty_df"], {"timeframe": timeframe or "unknown"}

    if "timestamp" in df.columns:
        df = df.sort_values("timestamp", ascending=True).reset_index(drop=True)
    if "close" not in df.columns:
        return False, None, 0.0, ["missing_close_series"], {"timeframe": timeframe or "unknown"}

    # --- Détermination du style de règles (A vs B) ---
    #  - Style A : bloc direct {"rsi": {..., "long": {...}, "short": {...}}}
    #  - Style B : bloc {"indicators": {"rsi": {...}}, "long": {...}, "short": {...}}
    if not tf_rules:
        return False, None, 0.0, ["no_rules_for_timeframe_or_empty"], {"timeframe": timeframe or "unknown"}

    style = "A" if "rsi" in tf_rules else ("B" if "indicators" in tf_rules else "UNKNOWN")

    # --- Reste de la fonction à partir d'ici : inchangé ---
    # utilise `tf_rules` au lieu de `rules` et, si tu logges dans debug,
    # inclue `timeframe` si non None.
    #
    # Exemple (pseudo-extrait) :
    #   if style == "A":
    #       rsi_cfg = tf_rules.get("rsi", {}) or {}
    #   elif style == "B":
    #       rsi_cfg = (tf_rules.get("indicators", {}) or {}).get("rsi", {}) or {}
    #
    # puis calcul du RSI, features, conditions, scores, etc.
    #
    # N’oublie pas dans le debug final :
    #   debug = {..., "timeframe": timeframe or "unknown", "style": style, ...}
    #
    # et retourne (valid: bool, side: Optional[str], score: float, reasons: List[str], debug: Dict[str, Any])
