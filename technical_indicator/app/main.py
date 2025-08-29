# technical_indicator/app/main.py
from __future__ import annotations
from fastapi import FastAPI, HTTPException
from typing import Dict, Any
from .models import ValidateRequest, ValidateResponse
from .loaders import load_rules, TF_TO_MINUTES
from .rules_engine import build_dataframe, evaluate_rules

app = FastAPI(title="Indicator API", version="1.0.0")

@app.get("/health")
def health() -> Dict[str, Any]:
    return {"status": "ok"}

@app.post("/validate", response_model=ValidateResponse)
def validate(req: ValidateRequest):
    if len(req.klines) < 10:
        raise HTTPException(status_code=400, detail="Not enough klines (>=10 required)")

    # DataFrame
    df = build_dataframe([k.model_dump() for k in req.klines])

    # Règles
    rules_all = load_rules()
    tf_rules = rules_all.get("timeframes", {}).get(req.timeframe, None)
    if not tf_rules:
        # fallback: valide = False si aucune règle
        return ValidateResponse(valid=False, reasons=["No rules for timeframe"], debug={"timeframe": req.timeframe})

    valid, side, score, reasons, debug = evaluate_rules({"timeframes": {req.timeframe: tf_rules}}, df)
    debug.update({"timeframe": req.timeframe, "contract": req.contract, "n": len(req.klines)})

    return ValidateResponse(
        valid=bool(valid),
        side=side if valid else None,
        score=round(float(score), 4),
        reasons=reasons or None,
        debug=debug
    )
