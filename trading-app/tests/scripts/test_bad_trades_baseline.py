from __future__ import annotations

import importlib.util
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "trading-app" / "scripts" / "bad_trades_baseline.py"
FIXTURE = ROOT / "trading-app" / "tests" / "fixtures" / "bad_trades_baseline_sample.csv"


def load_module():
    spec = importlib.util.spec_from_file_location("bad_trades_baseline", SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_build_baseline_segments_certified_rows_and_computes_core_metrics() -> None:
    module = load_module()

    result = module.build_baseline(FIXTURE, seed=132, monte_carlo_runs=20, min_cell_size=1)

    assert result["population"]["total_rows"] == 6
    assert result["population"]["certified_rows"] == 5
    assert result["population"]["excluded_rows"] == 1
    assert result["population"]["excluded_by_reason"]["cost_completeness:partial"] == 1
    assert result["population"]["modes"]["day_trading"]["certified_rows"] == 2
    assert result["population"]["modes"]["scalping"]["certified_rows"] == 2
    assert result["population"]["modes"]["micro_scalping"]["certified_rows"] == 1
    assert result["certification_cells"]["eligible_cell_count"] == 4
    assert result["certification_cells"]["eligible_trade_count"] == 5

    day_trading = result["groups"]["mode"]["day_trading"]
    assert day_trading["wins"] == 1
    assert day_trading["losses"] == 1
    assert day_trading["winrate"] == 0.5
    assert day_trading["net_expectancy_usdt"] == 0.5
    assert day_trading["profit_factor"] == 2.0
    assert day_trading["max_drawdown_usdt"] == -1.0
    assert day_trading["mean_realized_net_pnl_r"] == 0.25
    assert day_trading["median_realized_net_pnl_r"] == 0.25
    assert day_trading["wilson_95"]["low"] < day_trading["winrate"] < day_trading["wilson_95"]["high"]
    assert day_trading["liquidity"]["maker_fills"] == 1
    assert day_trading["liquidity"]["taker_fills"] == 1
    assert result["groups"]["side"]["LONG"]["rows"] == 4
    assert result["groups"]["side"]["SHORT"]["rows"] == 1

    scalping = result["groups"]["mode"]["scalping"]
    assert scalping["loss_causes"]["costs_destroy_edge"] == 2
    assert scalping["loss_causes"]["entry_momentum_extreme_candidate"] == 1

    simulation = result["simulation"]["mode"]["day_trading"]
    assert simulation["capital_usdt"] == 100.0
    assert simulation["trades_per_path"] == 100
    assert simulation["compounding_on"]["status"] == "not_computable_missing_risk_policy"
    assert simulation["compounding_off"]["p05_final_capital_usdt"] <= simulation["compounding_off"]["p95_final_capital_usdt"]


def test_cli_writes_markdown_and_json_outputs(tmp_path: Path) -> None:
    module = load_module()
    output_md = tmp_path / "baseline.md"
    output_json = tmp_path / "baseline.json"

    exit_code = module.main(
        [
            "--input",
            str(FIXTURE),
            "--output-md",
            str(output_md),
            "--output-json",
            str(output_json),
            "--seed",
            "132",
            "--monte-carlo-runs",
            "20",
            "--min-cell-size",
            "1",
        ]
    )

    assert exit_code == 0
    rendered = output_md.read_text(encoding="utf-8")
    assert "# Baseline bad trades certifiee v2" in rendered
    assert "## Metriques par side" in rendered
    assert "costs_destroy_edge" in rendered
    payload = json.loads(output_json.read_text(encoding="utf-8"))
    assert payload["population"]["certified_rows"] == 5


def test_max_drawdown_uses_realized_close_order() -> None:
    module = load_module()

    rows = [
        {"entry_event_id": "1", "entry_time": "2026-06-01T00:00:00+00:00", "close_time": "2026-06-01T02:00:00+00:00", "net_pnl_usdt": "10"},
        {"entry_event_id": "2", "entry_time": "2026-06-01T01:00:00+00:00", "close_time": "2026-06-01T01:30:00+00:00", "net_pnl_usdt": "-5"},
        {"entry_event_id": "3", "entry_time": "2026-06-01T03:00:00+00:00", "close_time": "2026-06-01T03:00:00+00:00", "net_pnl_usdt": "-5"},
    ]

    assert module.max_drawdown(rows) == -5.0
