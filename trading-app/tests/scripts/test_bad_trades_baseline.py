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

    result = module.build_baseline(FIXTURE, seed=132, monte_carlo_runs=20)

    assert result["population"]["total_rows"] == 6
    assert result["population"]["certified_rows"] == 5
    assert result["population"]["excluded_rows"] == 1
    assert result["population"]["excluded_by_reason"]["cost_completeness:partial"] == 1
    assert result["population"]["profiles"]["regular"]["certified_rows"] == 2
    assert result["population"]["profiles"]["scalper"]["certified_rows"] == 2
    assert result["population"]["profiles"]["scalper_micro"]["certified_rows"] == 1

    regular = result["groups"]["profile"]["regular"]
    assert regular["wins"] == 1
    assert regular["losses"] == 1
    assert regular["winrate"] == 0.5
    assert regular["net_expectancy_usdt"] == 0.5
    assert regular["profit_factor"] == 2.0
    assert regular["max_drawdown_usdt"] == -1.0
    assert regular["mean_realized_net_pnl_r"] == 0.25
    assert regular["median_realized_net_pnl_r"] == 0.25
    assert regular["wilson_95"]["low"] < regular["winrate"] < regular["wilson_95"]["high"]
    assert regular["liquidity"]["maker_fills"] == 1
    assert regular["liquidity"]["taker_fills"] == 1
    assert result["groups"]["direction"]["long"]["rows"] == 4
    assert result["groups"]["direction"]["short"]["rows"] == 1

    scalper = result["groups"]["profile"]["scalper"]
    assert scalper["loss_causes"]["costs_destroy_edge"] == 2
    assert scalper["loss_causes"]["entry_momentum_extreme_candidate"] == 1

    simulation = result["simulation"]["profile"]["regular"]
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
        ]
    )

    assert exit_code == 0
    rendered = output_md.read_text(encoding="utf-8")
    assert "# Baseline bad trades certifiee v2" in rendered
    assert "## Metriques par direction" in rendered
    assert "costs_destroy_edge" in rendered
    payload = json.loads(output_json.read_text(encoding="utf-8"))
    assert payload["population"]["certified_rows"] == 5
