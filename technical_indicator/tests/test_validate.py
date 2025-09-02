from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)

def test_validate_not_enough():
    payload = {"contract":"BTCUSDT","timeframe":"5m","klines":[{"timestamp":1,"open":1,"high":1,"low":1,"close":1,"volume":1}]}
    r = client.post("/validate", json=payload)
    assert r.status_code == 200
    assert r.json()["valid"] == False
