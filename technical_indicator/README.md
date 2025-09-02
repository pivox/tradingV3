# Technical Indicator API

API FastAPI pour calculer des indicateurs techniques (actuellement RSI) et valider un setup selon un fichier de règles YAML, par timeframe.

## Démarrer localement

```bash
pip install -r requirements.txt
export INDICATOR_RULES_PATH=/app/app/rules/trading.yaml  # ou chemin absolu
python -m uvicorn app.main:app --reload --port 8000
```

## Endpoints

- `GET /` : message de bienvenue
- `GET /health` : état de l'API
- `POST /rsi` : calcule le RSI (paramètres: `contract`, `timeframe`, `length`, `n`, `klines`)
- `POST /validate` : applique les règles YAML (RSI-only pour l’instant) et renvoie `{valid, side, score, reasons, debug}`
