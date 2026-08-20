from pathlib import Path

import pytest

from scripts.golden20_fresh_stack_runner import run_fresh_stacks


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]


@pytest.mark.skipif(
    not (REPOSITORY_ROOT / "trading-app/vendor/autoload.php").exists(),
    reason="Symfony dependencies unavailable; exercised by the dedicated golden CI step",
)
def test_golden20_runs_twice_through_fresh_real_http_stacks(monkeypatch):
    for key in ("HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY", "http_proxy", "https_proxy", "all_proxy"):
        monkeypatch.setenv(key, "http://127.0.0.1:9")
    monkeypatch.setenv("NO_PROXY", "")
    monkeypatch.setenv("no_proxy", "")

    assert run_fresh_stacks() == {
        "config_hashes_unique": True,
        "disabled_sets": ["recipe_fake_multi_disabled"],
        "exchange_calls": {"bitmart": 0, "hyperliquid": 0, "okx": 0},
        "fresh_database_count": 2,
        "fresh_process_count": 4,
        "loopback_http_stacks": 2,
        "orders_total": 0,
        "profiles": ["regular", "scalper", "scalper_micro"],
        "replay_same_run_id": True,
        "report_digest": "sha256:ea8de7061ee5c55e9d6eaffdf70906705399d35af38405592c7d22b40fad5dfc",
        "reports_identical": True,
        "seed_certified": True,
        "seed_fingerprint": "sha256:0943ae9d5da0cdc265118d4f1fcb5ba00985f1844d7cd9814a66d52dd7550160",
        "seed_schema_version": "fake-deterministic-seed-v1",
        "schema_version": "fake-paper-golden20-fresh-stacks-v1",
        "stack_count": 2,
        "status": "pass",
        "symbols": [["BTCUSDT"], ["BTCUSDT"], ["BTCUSDT"]],
        "transport": "loopback_tcp_http",
    }
