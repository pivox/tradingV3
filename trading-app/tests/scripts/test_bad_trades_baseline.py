from __future__ import annotations

import csv
import importlib.util
import json
from datetime import datetime, timedelta
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "trading-app" / "scripts" / "bad_trades_baseline.py"
FIXTURE = ROOT / "trading-app" / "tests" / "fixtures" / "bad_trades_baseline_sample.csv"
EXPORT_SQL = ROOT / "docs" / "handbook" / "reports" / "queries" / "bad-trades-baseline-v2.sql"


def load_module():
    spec = importlib.util.spec_from_file_location("bad_trades_baseline", SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def write_minimum_eligible_fixture(tmp_path: Path, filename: str = "eligible.csv") -> Path:
    with FIXTURE.open(newline="", encoding="utf-8") as source:
        reader = csv.DictReader(source)
        assert reader.fieldnames is not None
        fieldnames = reader.fieldnames
        rows = list(reader)

    expanded: list[dict[str, str]] = []
    for row in rows:
        repeat_count = 50 if row["is_certified"].lower() == "true" else 1
        for index in range(repeat_count):
            copy = dict(row)
            copy["entry_event_id"] = f"{row['entry_event_id']}{index:02d}"
            offset = timedelta(days=index)
            for field in ("entry_time", "close_time"):
                if copy[field]:
                    copy[field] = (datetime.fromisoformat(copy[field]) + offset).isoformat()
            expanded.append(copy)

    destination = tmp_path / filename
    with destination.open("w", newline="", encoding="utf-8") as target:
        writer = csv.DictWriter(target, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(expanded)

    return destination


def test_build_baseline_rejects_cell_minimum_below_global_contract() -> None:
    module = load_module()

    with pytest.raises(ValueError, match="min_cell_size must be at least 50"):
        module.build_baseline(FIXTURE, min_cell_size=1)


def test_cli_rejects_cell_minimum_below_global_contract(tmp_path: Path) -> None:
    module = load_module()

    with pytest.raises(SystemExit) as error:
        module.main(
            [
                "--input",
                str(FIXTURE),
                "--output-md",
                str(tmp_path / "baseline.md"),
                "--output-json",
                str(tmp_path / "baseline.json"),
                "--min-cell-size",
                "1",
            ]
        )

    assert error.value.code == 2


def test_build_baseline_segments_certified_rows_and_computes_core_metrics(tmp_path: Path) -> None:
    module = load_module()
    eligible_fixture = write_minimum_eligible_fixture(tmp_path)

    result = module.build_baseline(eligible_fixture, seed=132, monte_carlo_runs=20)

    assert result["population"]["total_rows"] == 251
    assert result["population"]["certified_rows"] == 250
    assert result["population"]["excluded_rows"] == 1
    assert result["population"]["excluded_by_reason"]["cost_completeness:partial"] == 1
    assert result["population"]["modes"]["day_trading"]["certified_rows"] == 100
    assert result["population"]["modes"]["scalping"]["certified_rows"] == 100
    assert result["population"]["modes"]["micro_scalping"]["certified_rows"] == 50
    assert result["certification_cells"]["eligible_cell_count"] == 4
    assert result["certification_cells"]["eligible_trade_count"] == 250

    day_trading = result["groups"]["mode"]["day_trading"]
    assert day_trading["wins"] == 50
    assert day_trading["losses"] == 50
    assert day_trading["winrate"] == 0.5
    assert day_trading["net_expectancy_usdt"] == 0.5
    assert day_trading["profit_factor"] == 2.0
    assert day_trading["max_drawdown_usdt"] == -1.0
    assert day_trading["mean_realized_net_pnl_r"] == 0.25
    assert day_trading["median_realized_net_pnl_r"] == 0.25
    assert day_trading["wilson_95"]["low"] < day_trading["winrate"] < day_trading["wilson_95"]["high"]
    assert day_trading["liquidity"] == {
        "status": "unavailable_not_exposed_by_position_trade_analysis_v2"
    }
    assert result["groups"]["side"]["LONG"]["rows"] == 200
    assert result["groups"]["side"]["SHORT"]["rows"] == 50

    scalping = result["groups"]["mode"]["scalping"]
    assert scalping["loss_causes"]["costs_destroy_edge"] == 100
    assert scalping["loss_causes"]["entry_momentum_extreme_candidate"] == 50

    simulation = result["simulation"]["mode"]["day_trading"]
    assert simulation["capital_usdt"] == 100.0
    assert simulation["trades_per_path"] == 100
    assert simulation["compounding_on"]["status"] == "not_computable_missing_risk_policy"
    assert simulation["compounding_off"]["p05_final_capital_usdt"] <= simulation["compounding_off"]["p95_final_capital_usdt"]


def test_cli_writes_markdown_and_json_outputs(tmp_path: Path) -> None:
    module = load_module()
    eligible_fixture = write_minimum_eligible_fixture(tmp_path)
    output_md = tmp_path / "baseline.md"
    output_json = tmp_path / "baseline.json"

    exit_code = module.main(
        [
            "--input",
            str(eligible_fixture),
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
    assert "## Metriques par side" in rendered
    assert "costs_destroy_edge" in rendered
    assert "unique autorite de certification" in rendered
    assert "fill_cost_ledger" not in rendered
    payload = json.loads(output_json.read_text(encoding="utf-8"))
    assert payload["population"]["certified_rows"] == 250
    assert payload["source"]["contract"] == (
        "position_trade_analysis_v2 is the sole certification authority; KPI PnL uses "
        "canonical_net_pnl_usdt and canonical_realized_net_pnl_r only"
    )
    assert "fill_cost_ledger" not in json.dumps(payload)


def test_current_v2_export_shape_marks_liquidity_unavailable_instead_of_zero(tmp_path: Path) -> None:
    module = load_module()
    eligible_fixture = write_minimum_eligible_fixture(tmp_path, "eligible-source.csv")
    liquidity_fields = {
        "maker_fill_count",
        "taker_fill_count",
        "unknown_liquidity_fill_count",
    }
    sql = EXPORT_SQL.read_text(encoding="utf-8")
    assert not any(field in sql for field in liquidity_fields)

    with eligible_fixture.open(newline="", encoding="utf-8") as source:
        reader = csv.DictReader(source)
        assert reader.fieldnames is not None
        fieldnames = [field for field in reader.fieldnames if field not in liquidity_fields]
        rows = [{field: row[field] for field in fieldnames} for row in reader]

    exported = tmp_path / "v2-export.csv"
    with exported.open("w", newline="", encoding="utf-8") as target:
        writer = csv.DictWriter(target, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

    result = module.build_baseline(exported, seed=132, monte_carlo_runs=20)

    assert result["groups"]["mode"]["day_trading"]["liquidity"] == {
        "status": "unavailable_not_exposed_by_position_trade_analysis_v2"
    }


def test_max_drawdown_uses_realized_close_order() -> None:
    module = load_module()

    rows = [
        {"entry_event_id": "1", "entry_time": "2026-06-01T00:00:00+00:00", "close_time": "2026-06-01T02:00:00+00:00", "net_pnl_usdt": "10"},
        {"entry_event_id": "2", "entry_time": "2026-06-01T01:00:00+00:00", "close_time": "2026-06-01T01:30:00+00:00", "net_pnl_usdt": "-5"},
        {"entry_event_id": "3", "entry_time": "2026-06-01T03:00:00+00:00", "close_time": "2026-06-01T03:00:00+00:00", "net_pnl_usdt": "-5"},
    ]

    assert module.max_drawdown(rows) == -5.0
