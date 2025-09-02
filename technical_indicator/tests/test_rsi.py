from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)

def test_rsi_min_klines():
    payload = {"contract":"BTCUSDT","timeframe":"5m","length":14,"klines":[]}
    r = client.post("/rsi", json=payload)
    assert r.status_code == 200
    assert r.json()["status"] == 400
