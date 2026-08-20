from scripts.golden20_fresh_stack_runner import run_fresh_stacks


def test_golden20_runs_twice_through_fresh_real_http_stacks():
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
        "report_digest": "sha256:578aaf847bee9833d8946d056638506ebac938dabbb8dd3459df626e64c46b8d",
        "reports_identical": True,
        "schema_version": "fake-paper-golden20-fresh-stacks-v1",
        "stack_count": 2,
        "status": "pass",
        "symbols": [["BTCUSDT"], ["BTCUSDT"], ["BTCUSDT"]],
        "transport": "loopback_tcp_http",
    }
