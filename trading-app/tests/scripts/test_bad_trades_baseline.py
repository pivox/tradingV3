from __future__ import annotations

import csv
import importlib.util
import json
import hashlib
import os
import subprocess
import sys
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


def write_expected_cells_manifest(tmp_path: Path, cells: list[dict[str, str]]) -> Path:
    compact = json.dumps(cells, separators=(",", ":"), ensure_ascii=False)
    by_mode: dict[str, int] = {}
    for cell in cells:
        by_mode[cell["mode_id"]] = by_mode.get(cell["mode_id"], 0) + 1
    payload = {
        "schema_version": "paper-certification-matrix-v1",
        "minimum_certified_trades_per_cell": 50,
        "cells_sha256": "sha256:" + hashlib.sha256(compact.encode()).hexdigest(),
        "expected_cell_count_by_mode": dict(sorted(by_mode.items())),
        "cells": cells,
    }
    destination = tmp_path / "expected-cells.json"
    destination.write_text(json.dumps(payload), encoding="utf-8")
    return destination


def write_two_cell_eligible_fixture(tmp_path: Path) -> tuple[Path, list[dict[str, str]]]:
    with FIXTURE.open(newline="", encoding="utf-8") as source:
        reader = csv.DictReader(source)
        assert reader.fieldnames is not None
        fieldnames = [*reader.fieldnames, "mode_version", "setup_version"]
        template = next(reader)
    cells = [
        {
            "paper_network": "mainnet", "market_data_venue": "okx",
            "mode_id": "day_trading", "mode_version": "1.1.0",
            "setup_id": "day_trading.trend_continuation.long", "setup_version": "1.1.0",
            "canonical_side": "long",
        },
        {
            "paper_network": "mainnet", "market_data_venue": "okx",
            "mode_id": "scalping", "mode_version": "1.1.0",
            "setup_id": "scalping.pullback.long", "setup_version": "1.1.0",
            "canonical_side": "long",
        },
    ]
    rows: list[dict[str, str]] = []
    for cell_index, cell in enumerate(cells):
        for row_index in range(50):
            row = dict(template)
            row.update(cell)
            row["entry_event_id"] = f"{cell_index}-{row_index}"
            row["net_pnl_usdt"] = "2.0" if cell_index == 0 else "-1.0"
            row["realized_net_pnl_r"] = "1.0" if cell_index == 0 else "-0.5"
            rows.append(row)
    destination = tmp_path / "two-eligible-cells.csv"
    with destination.open("w", newline="", encoding="utf-8") as target:
        writer = csv.DictWriter(target, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)
    return destination, cells


@pytest.mark.parametrize("value", ["Infinity", "-Infinity", "NaN"])
def test_parse_float_rejects_non_finite_csv_values(value: str) -> None:
    module = load_module()

    assert module.parse_float(value) is None


@pytest.mark.parametrize("value", ["Infinity", "-Infinity", "NaN"])
@pytest.mark.parametrize(
    ("field", "exclusion_reason"),
    [
        ("net_pnl_usdt", "missing_net_pnl_usdt"),
        ("realized_net_pnl_r", "missing_realized_net_pnl_r"),
    ],
)
def test_non_finite_csv_kpi_values_cannot_certify_trade(
    field: str,
    exclusion_reason: str,
    value: str,
) -> None:
    module = load_module()
    with FIXTURE.open(newline="", encoding="utf-8") as source:
        row = next(csv.DictReader(source))
    row[field] = value

    assert module.is_certified(row) is False
    assert exclusion_reason in module.exclusion_reasons(row)


def test_build_baseline_rejects_cell_minimum_below_global_contract() -> None:
    module = load_module()

    with pytest.raises(ValueError, match="min_cell_size must be at least 50"):
        module.build_baseline(FIXTURE, min_cell_size=1)


def test_reference_only_trade_can_never_be_certified() -> None:
    module = load_module()
    with FIXTURE.open(newline="", encoding="utf-8") as source:
        row = next(csv.DictReader(source))
    row["paper_eligibility"] = "reference_only"

    assert module.is_certified(row) is False
    assert "paper_eligibility:reference_only" in module.exclusion_reasons(row)


def test_certification_cell_key_normalizes_only_side_casing() -> None:
    module = load_module()
    row = {
        "paper_network": "mainnet",
        "market_data_venue": "okx",
        "mode_id": "day_trading",
        "setup_id": "day_trading.trend_continuation.long",
        "canonical_side": "LONG",
    }

    assert module.certification_cell_key(row) == (
        "mainnet|okx|day_trading|day_trading.trend_continuation.long|long"
    )


def test_expected_manifest_reports_zero_count_cells_and_excludes_unexpected_certified_rows(tmp_path: Path) -> None:
    module = load_module()
    manifest = write_expected_cells_manifest(tmp_path, [{
        "paper_network": "mainnet",
        "market_data_venue": "okx",
        "mode_id": "day_trading",
        "mode_version": "1.1.0",
        "setup_id": "day_trading.trend_continuation.long",
        "setup_version": "1.1.0",
        "canonical_side": "long",
    }])

    result = module.build_baseline(FIXTURE, expected_cells_path=manifest)

    expected_key = "mainnet|okx|day_trading|day_trading.trend_continuation.long|long"
    assert result["certification_cells"]["expected_cell_count"] == 1
    assert result["certification_cells"]["eligible_cell_count"] == 0
    assert result["certification_cells"]["under_sampled"] == {expected_key: 0}
    assert result["certification_cells"]["unexpected_certified"]
    assert result["groups"]["mode"] == {}


def test_expected_manifest_rejects_a_tampered_cell_digest(tmp_path: Path) -> None:
    module = load_module()
    manifest = write_expected_cells_manifest(tmp_path, [{
        "paper_network": "mainnet",
        "market_data_venue": "okx",
        "mode_id": "day_trading",
        "mode_version": "1.1.0",
        "setup_id": "day_trading.trend_continuation.long",
        "setup_version": "1.1.0",
        "canonical_side": "long",
    }])
    payload = json.loads(manifest.read_text(encoding="utf-8"))
    payload["cells"][0]["canonical_side"] = "short"
    manifest.write_text(json.dumps(payload), encoding="utf-8")

    with pytest.raises(ValueError, match="certification matrix digest mismatch"):
        module.build_baseline(FIXTURE, expected_cells_path=manifest)


def test_expected_manifest_excludes_a_certified_row_from_another_contract_version(tmp_path: Path) -> None:
    module = load_module()
    manifest = write_expected_cells_manifest(tmp_path, [{
        "paper_network": "mainnet",
        "market_data_venue": "okx",
        "mode_id": "day_trading",
        "mode_version": "1.1.0",
        "setup_id": "day_trading.trend_continuation.long",
        "setup_version": "1.1.0",
        "canonical_side": "long",
    }])
    with FIXTURE.open(newline="", encoding="utf-8") as source:
        reader = csv.DictReader(source)
        assert reader.fieldnames is not None
        row = next(reader)
        fieldnames = [*reader.fieldnames, "mode_version", "setup_version"]
    row.update({
        "paper_network": "mainnet",
        "market_data_venue": "okx",
        "mode_id": "day_trading",
        "mode_version": "1.0.0",
        "setup_id": "day_trading.trend_continuation.long",
        "setup_version": "1.0.0",
        "canonical_side": "long",
    })
    exported = tmp_path / "wrong-version.csv"
    with exported.open("w", newline="", encoding="utf-8") as target:
        writer = csv.DictWriter(target, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerow(row)

    result = module.build_baseline(exported, expected_cells_path=manifest)

    key = "mainnet|okx|day_trading|day_trading.trend_continuation.long|long"
    assert result["certification_cells"]["under_sampled"] == {key: 0}
    assert result["certification_cells"]["version_mismatched_certified"] == {
        key + "|mode_version=1.0.0|setup_version=1.0.0": 1
    }
    assert result["certification_cells"]["eligible_trade_count"] == 0


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


def test_cli_applies_expected_cell_manifest_and_renders_zero_count(tmp_path: Path) -> None:
    module = load_module()
    manifest = write_expected_cells_manifest(tmp_path, [{
        "paper_network": "mainnet",
        "market_data_venue": "okx",
        "mode_id": "day_trading",
        "mode_version": "1.1.0",
        "setup_id": "day_trading.trend_continuation.long",
        "setup_version": "1.1.0",
        "canonical_side": "long",
    }])
    output_md = tmp_path / "baseline.md"
    output_json = tmp_path / "baseline.json"

    assert module.main([
        "--input", str(FIXTURE),
        "--expected-cells", str(manifest),
        "--output-md", str(output_md),
        "--output-json", str(output_json),
    ]) == 0

    payload = json.loads(output_json.read_text(encoding="utf-8"))
    assert payload["certification_cells"]["under_sampled"] == {
        "mainnet|okx|day_trading|day_trading.trend_continuation.long|long": 0
    }
    assert "| `mainnet\\|okx\\|day_trading\\|day_trading.trend_continuation.long\\|long` | 0 |" in output_md.read_text(encoding="utf-8")


def test_seeded_simulation_is_independent_from_python_hash_seed(tmp_path: Path) -> None:
    exported, cells = write_two_cell_eligible_fixture(tmp_path)
    manifest = write_expected_cells_manifest(tmp_path, cells)
    simulations = []
    for hash_seed in ("1", "2"):
        output_json = tmp_path / f"baseline-{hash_seed}.json"
        completed = subprocess.run(
            [
                sys.executable, str(SCRIPT), "--input", str(exported),
                "--expected-cells", str(manifest),
                "--output-md", str(tmp_path / f"baseline-{hash_seed}.md"),
                "--output-json", str(output_json),
                "--seed", "132", "--monte-carlo-runs", "37",
            ],
            env={**os.environ, "PYTHONHASHSEED": hash_seed},
            check=False,
            capture_output=True,
            text=True,
        )
        assert completed.returncode == 0, completed.stderr
        simulations.append(json.loads(output_json.read_text(encoding="utf-8"))["simulation"])

    assert simulations[0] == simulations[1]


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
    assert "paper_eligibility = 'baseline_eligible'" in sql
    assert "paper_eligibility," in sql

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
