from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from app.backtesting.dataset import CandleRecord, DatasetBuilder, DatasetSourceIdentity


_FIXTURE_DIR = (
    Path(__file__).parents[2]
    / "trading-app"
    / "tests"
    / "Fixtures"
    / "paper-backtesting"
)
_FORBIDDEN_KEYS = {"mode", "setup", "profile", "strategy"}


def _assert_no_strategy_identity(value: Any) -> None:
    if isinstance(value, dict):
        assert not (_FORBIDDEN_KEYS & {str(key).lower() for key in value})
        for item in value.values():
            _assert_no_strategy_identity(item)
    elif isinstance(value, list):
        for item in value:
            _assert_no_strategy_identity(item)


def test_php_paper_adapter_fixture_builds_an_exact_eligible_stream() -> None:
    source_payload = json.loads(
        (_FIXTURE_DIR / "source-identity.json").read_text(encoding="utf-8")
    )
    candle_lines = (
        (_FIXTURE_DIR / "candles.ndjson").read_text(encoding="utf-8").splitlines()
    )
    candle_payloads = [json.loads(line) for line in candle_lines]
    _assert_no_strategy_identity(source_payload)
    _assert_no_strategy_identity(candle_payloads)

    source = DatasetSourceIdentity.model_validate_json(
        (_FIXTURE_DIR / "source-identity.json").read_bytes(), strict=True
    )
    records = tuple(
        CandleRecord.model_validate_json(line, strict=True) for line in candle_lines
    )
    result = DatasetBuilder(source).build(records)

    assert result.quality_report.eligible
    assert result.records == records
    assert result.record_count == 1
    assert result.symbols == ("BTCUSDT",)
    assert tuple(item.value for item in result.timeframes) == ("1m",)
    assert [
        (
            stream.market_data_venue,
            stream.market_type.value,
            stream.symbol,
            stream.timeframe.value,
            stream.observed_count,
        )
        for stream in result.quality_report.streams
    ] == [("okx", "perpetual", "BTCUSDT", "1m", 1)]
    assert result.records[0].volume == "0.001"
