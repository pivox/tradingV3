from __future__ import annotations

import json
import os
import stat
import subprocess
import sys
import textwrap
from copy import deepcopy
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.backtesting.tradingcore_bridge import (
    BacktestTradingCoreBridge,
    CanonicalBacktestRuleRequest,
    CanonicalBacktestRuleResult,
    TradingCoreBridgeError,
)
from app.modern_trading_contracts import _canonical_json


ROOT = Path(__file__).resolve().parents[2]
SNAPSHOT = Path(__file__).parent / "fixtures/backtesting/php-effective-config-snapshot.json"


def request_payload() -> dict:
    snapshot = json.loads(SNAPSHOT.read_text())
    return {
        "schema_version": "canonical-backtest-rule-request.v1",
        "request_id": "bridge-test-1",
        "effective_config_snapshot": snapshot,
        "symbol": "BTCUSDT",
        "market_type": "perpetual",
        "evaluated_at": "2026-08-10T12:00:00Z",
        "indicators_by_timeframe": {
            timeframe: {
                "snapshot_identity": {
                    "timeframe": timeframe,
                    "symbol": "BTCUSDT",
                    "exchange": "fake",
                    "environment": "test",
                    "market_type": "perpetual",
                },
                "kline_time": observed,
                "series_order": "oldest_to_newest",
                "ema_200_series": [100.0, 101.0],
                "ema_200_series_timestamps": [1, 2],
                "macd_hist_series": [0.1, 0.2],
                "macd_hist_series_timestamps": [1, 2],
                "macd_line_signal_series": [-0.1, 0.1],
                "macd_line_signal_series_timestamps": [1, 2],
            }
            for timeframe, observed in {
                "1h": "2026-08-10T11:00:00Z",
                "15m": "2026-08-10T11:45:00Z",
                "5m": "2026-08-10T11:55:00Z",
                "1m": "2026-08-10T11:59:00Z",
            }.items()
        },
    }


def result_payload(request: CanonicalBacktestRuleRequest) -> dict:
    snapshot = request.effective_config_snapshot
    payload = {
        "schema_version": "canonical-backtest-rule-result.v1",
        "request_id": request.request_id,
        "mode_id": snapshot.request.mode_id,
        "mode_version": snapshot.request.mode_version,
        "setup_id": snapshot.request.setup_id,
        "setup_version": snapshot.request.setup_version,
        "side": snapshot.request.side,
        "exchange": "fake",
        "environment": snapshot.request.environment,
        "market_type": request.market_type,
        "symbol": request.symbol,
        "config_hash": snapshot.config_hash,
        "condition_catalog_hash": snapshot.condition_catalog_hash,
        "snapshot_hash": snapshot.snapshot_hash,
        "evaluated_at": request.evaluated_at,
        "passed": False,
        "reason_code": "critical_timeframe_missing",
        "trace": {"plan_cache_key": "a" * 64},
        "input_hash": request.input_hash(),
    }
    import hashlib

    payload["result_hash"] = "sha256:" + hashlib.sha256(
        _canonical_json(payload).encode()
    ).hexdigest()
    return payload


def executable_script(source: str, tmp_path: Path) -> str:
    path = tmp_path / "child.py"
    path.write_text("#!/usr/bin/env python3\n" + textwrap.dedent(source))
    path.chmod(path.stat().st_mode | stat.S_IXUSR)
    return str(path)


def test_request_is_strict_frozen_and_identity_bound() -> None:
    request = CanonicalBacktestRuleRequest.model_validate(request_payload())
    with pytest.raises(ValidationError):
        request.symbol = "ETHUSDT"  # type: ignore[misc]
    forged = request_payload()
    forged["profile"] = "scalper"
    with pytest.raises(ValidationError):
        CanonicalBacktestRuleRequest.model_validate(forged)
    forged = request_payload()
    forged["evaluated_at"] = 1_786_365_600
    with pytest.raises(ValidationError):
        CanonicalBacktestRuleRequest.model_validate(forged)
    forged = request_payload()
    forged["indicators_by_timeframe"]["1m"]["snapshot_identity"]["symbol"] = "ETHUSDT"
    with pytest.raises(ValidationError, match="identity_mismatch"):
        CanonicalBacktestRuleRequest.model_validate(forged)


def test_result_rejects_hash_and_nondeterministic_trace_drift() -> None:
    request = CanonicalBacktestRuleRequest.model_validate(request_payload())
    payload = result_payload(request)
    assert CanonicalBacktestRuleResult.model_validate(payload).passed is False
    forged = deepcopy(payload)
    forged["passed"] = True
    with pytest.raises(ValidationError, match="result_hash_mismatch"):
        CanonicalBacktestRuleResult.model_validate(forged)
    forged = deepcopy(payload)
    forged["trace"]["plan_cache_hit"] = False
    with pytest.raises(ValidationError, match="nondeterministic"):
        CanonicalBacktestRuleResult.model_validate(forged)


def test_bridge_uses_exact_shell_free_argv_and_canonical_stdin(tmp_path: Path) -> None:
    request = CanonicalBacktestRuleRequest.model_validate(request_payload())
    response = _canonical_json(result_payload(request))
    script = executable_script(
        f"""
        import json, sys
        payload = sys.stdin.buffer.read()
        json.loads(payload)
        sys.stdout.write({response!r} + "\\n")
        """,
        tmp_path,
    )
    bridge = BacktestTradingCoreBridge((sys.executable, script))
    result = bridge.evaluate(request)
    assert bridge.argv == (sys.executable, script)
    assert result.input_hash == request.input_hash()


@pytest.mark.parametrize(
    ("source", "reason"),
    [
        ("import sys; sys.exit(2)", "process_failed"),
        ("print('not-json')", "result_invalid"),
        ("print('{} {}')", "result_invalid"),
        ("pass", "result_invalid"),
    ],
)
def test_bridge_fails_closed_for_process_and_result_errors(
    source: str, reason: str, tmp_path: Path
) -> None:
    request = CanonicalBacktestRuleRequest.model_validate(request_payload())
    script = executable_script(source, tmp_path)
    with pytest.raises(TradingCoreBridgeError, match=reason):
        BacktestTradingCoreBridge((sys.executable, script)).evaluate(request)


def test_bridge_handles_missing_executable_timeout_and_bounded_outputs(tmp_path: Path) -> None:
    request = CanonicalBacktestRuleRequest.model_validate(request_payload())
    with pytest.raises(TradingCoreBridgeError, match="process_unavailable"):
        BacktestTradingCoreBridge((str(tmp_path / "missing"),)).evaluate(request)
    sleeper = executable_script("import time; time.sleep(2)", tmp_path)
    with pytest.raises(TradingCoreBridgeError, match="timeout"):
        BacktestTradingCoreBridge((sys.executable, sleeper), timeout_seconds=0.05).evaluate(request)
    noisy = executable_script("print('x' * 10000)", tmp_path)
    with pytest.raises(TradingCoreBridgeError, match="output_too_large"):
        BacktestTradingCoreBridge((sys.executable, noisy), max_output_bytes=100).evaluate(request)
    noisy_err = executable_script("import sys; sys.stderr.write('x' * 10000)", tmp_path)
    with pytest.raises(TradingCoreBridgeError, match="output_too_large"):
        BacktestTradingCoreBridge((sys.executable, noisy_err), max_output_bytes=100).evaluate(request)


def test_bridge_rejects_identity_drift_even_with_valid_result_hash(tmp_path: Path) -> None:
    request = CanonicalBacktestRuleRequest.model_validate(request_payload())
    payload = result_payload(request)
    payload["symbol"] = "ETHUSDT"
    import hashlib

    core = dict(payload)
    core.pop("result_hash")
    payload["result_hash"] = "sha256:" + hashlib.sha256(_canonical_json(core).encode()).hexdigest()
    script = executable_script(f"print({_canonical_json(payload)!r})", tmp_path)
    with pytest.raises(TradingCoreBridgeError, match="identity_mismatch"):
        BacktestTradingCoreBridge((sys.executable, script)).evaluate(request)


@pytest.mark.skipif(os.environ.get("CI") == "true", reason="local cross-runtime golden")
def test_real_symfony_invocation_is_byte_deterministic() -> None:
    console = ROOT / "trading-app/bin/console"
    app = ROOT / "trading-app"
    if not console.exists() or not (app / "vendor/autoload.php").exists():
        pytest.skip("Symfony dependencies unavailable")
    php_source = r'''require "vendor/autoload.php";
        $snapshot=(new App\TradingCore\Config\EffectiveTradingConfigResolver())->resolve(
            new App\TradingCore\Config\EffectiveTradingConfigRequest(
                "scalping","1.1.0","scalping.pullback.long","1.1.0",
                "fake","test","long",
                App\TradingCore\Execution\Enum\ShadowExecutionCapability::Backtest
            )
        );
        echo json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);'''
    generated = subprocess.run(
        ("php", "-r", php_source),
        cwd=app,
        check=True,
        capture_output=True,
        text=True,
        timeout=15,
    )
    payload = request_payload()
    payload["effective_config_snapshot"] = json.loads(generated.stdout)
    request = CanonicalBacktestRuleRequest.model_validate(payload)
    bridge = BacktestTradingCoreBridge()
    request_bytes = _canonical_json(request.model_dump(mode="json")).encode()
    first_code, first_stdout, first_stderr = bridge._run_bounded(request_bytes)
    second_code, second_stdout, second_stderr = bridge._run_bounded(request_bytes)
    assert (first_code, first_stdout, first_stderr) == (
        second_code,
        second_stdout,
        second_stderr,
    )
    assert first_code == 0
    assert first_stderr == b""
    first = bridge.evaluate(request)
    second = bridge.evaluate(request)
    assert first == second
    assert first.result_hash == second.result_hash
