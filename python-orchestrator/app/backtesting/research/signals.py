"""Bounded, provenance-checked driver for the canonical PHP research signal worker."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import selectors
import shutil
import stat
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Iterator

from .binance_history import SYMBOLS
from .signal_sources import (SignalError, SourceSelection, _ms, iter_verified_candles,
                             select_sources)

MAX_LINE_BYTES = 2 * 1024 * 1024
MAX_OUTPUT_BYTES = 4 * 1024**3
DEFAULT_STDERR_BYTES = 1024 * 1024
DEFAULT_WALL_SECONDS = 6 * 3600
CODE_FILES = (
    "src/Command/ResearchSignalWorkerCommand.php",
    "src/TradingCore/Backtesting/Research/ResearchSignalSession.php",
    "src/TradingCore/Backtesting/Research/ResearchCandle.php",
    "src/TradingCore/Backtesting/Research/ResearchRollingWindows.php",
)


def _encode(frame: dict) -> bytes:
    raw = json.dumps(frame, separators=(",", ":"), ensure_ascii=False, allow_nan=False).encode() + b"\n"
    if len(raw) > MAX_LINE_BYTES:
        raise SignalError("worker input line size limit")
    return raw


def _is_scored_tick(tick: int, score_start_ms: int, end_ms: int) -> bool:
    return score_start_ms <= tick < end_ms


def _first_evaluable_ms(selection: SourceSelection) -> int:
    first_4h = ((_ms(selection.start) + 14399999) // 14400000) * 14400000
    earliest = max(_ms(selection.score_start), first_4h + 250 * 14400000)
    return ((earliest + 899999) // 900000) * 900000


def _private_output(root: Path, dataset_root: Path) -> Path:
    root = Path(root)
    if not root.is_absolute() or ".." in root.parts or root.exists() or root.is_symlink():
        raise SignalError("fresh absolute output directory required")
    repo = Path(__file__).resolve().parents[4]
    git_pointer = repo / ".git"
    repositories = [repo]
    if git_pointer.is_file():
        marker = git_pointer.read_text().strip()
        if marker.startswith("gitdir: "):
            gitdir = Path(marker.removeprefix("gitdir: ")).resolve()
            if gitdir.parent.name == "worktrees" and gitdir.parent.parent.name == ".git":
                repositories.append(gitdir.parent.parent.parent)
    if (root == Path.home() or root == dataset_root or dataset_root in root.parents
            or any(root == path or path in root.parents or root in path.parents
                   for path in repositories)):
        raise SignalError("output root outside repositories and dataset required")
    cursor = Path(root.anchor)
    for part in root.parts[1:]:
        cursor /= part
        if cursor.is_symlink():
            raise SignalError("symlink output path")
    if not root.parent.is_dir():
        raise SignalError("output parent directory required")
    root.mkdir(mode=0o700)
    return root


def _atomic_json(path: Path, data: dict) -> None:
    content = json.dumps(data, sort_keys=True, indent=2, allow_nan=False).encode() + b"\n"
    temporary = path.with_name("." + path.name + ".tmp")
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if temporary.exists():
            temporary.unlink()


def _code_hashes(app_dir: Path, *, fake_worker: bool) -> dict[str, str]:
    paths = [Path(__file__), Path(__file__).with_name("signal_sources.py")]
    if not fake_worker:
        paths.extend(app_dir / name for name in CODE_FILES)
    hashes: dict[str, str] = {}
    for path in paths:
        if not path.is_file() or path.is_symlink():
            raise SignalError("worker or runner code unavailable")
        hashes[str(path)] = hashlib.sha256(path.read_bytes()).hexdigest()
    return hashes


def _worker_argv(app_dir: Path, supplied: tuple[str, ...] | None) -> tuple[str, ...]:
    if supplied is not None:
        if not supplied or not Path(supplied[0]).is_absolute():
            raise SignalError("absolute test worker executable required")
        return supplied
    php = shutil.which("php")
    if php is None or not (app_dir / "bin/console").is_file():
        raise SignalError("PHP worker unavailable")
    return (php, "-d", "display_errors=stderr", str(app_dir / "bin/console"),
            "app:research:signals", "--no-interaction")


def _frames(selection: SourceSelection, root: Path, session_id: str) -> Iterator[bytes]:
    yield _encode({"schema_version": "research-signal-open.v1", "session_id": session_id,
                   "dataset_id": "research-" + selection.dataset_sha256[:24],
                   "dataset_sha256": selection.dataset_sha256,
                   "source_venue": "binance_usdm", "source_network": "mainnet",
                   "market_type": "perpetual", "symbol": selection.symbol,
                   "start_ms": _ms(selection.start), "end_ms": _ms(selection.end),
                   "score_start_ms": _ms(selection.score_start)})
    batch: list[dict] = []
    for record in iter_verified_candles(root, selection):
        batch.append(record)
        if len(batch) == 1440:
            yield _encode({"schema_version": "research-signal-candles.v1",
                           "session_id": session_id, "candles": batch})
            batch = []
    if batch:
        yield _encode({"schema_version": "research-signal-candles.v1",
                       "session_id": session_id, "candles": batch})
    yield _encode({"schema_version": "research-signal-close.v1", "session_id": session_id})


def _rss_sample(pid: int) -> int | None:
    try:
        for line in Path(f"/proc/{pid}/status").read_text().splitlines():
            if line.startswith("VmHWM:"):
                return int(line.split()[1]) * 1024
    except (OSError, ValueError, IndexError):
        pass
    return None


def _terminate(proc: subprocess.Popen[bytes]) -> None:
    if proc.poll() is not None:
        return
    proc.terminate()
    try:
        proc.wait(timeout=2)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait(timeout=2)


def _identity(frame: dict, opened: dict, session_id: str, selection: SourceSelection) -> None:
    source = {"dataset_id": "research-" + selection.dataset_sha256[:24],
              "dataset_sha256": selection.dataset_sha256,
              "source_venue": "binance_usdm", "source_network": "mainnet",
              "market_type": "perpetual"}
    if (frame.get("session_id") != session_id or frame.get("source") != source
            or (frame.get("symbol") not in (None, selection.symbol))):
        raise SignalError("worker source or session identity conflict")
    if opened:
        if frame.get("execution") != opened.get("execution") or frame.get("baseline") != opened.get("baseline"):
            raise SignalError("worker execution or baseline changed")


def _validate_opened(frame: dict, session_id: str, selection: SourceSelection) -> None:
    if frame.get("schema_version") != "research-signal-opened.v1":
        raise SignalError("worker open acknowledgement missing")
    _identity(frame, {}, session_id, selection)
    execution = frame.get("execution")
    if (not isinstance(execution, dict)
            or any(execution.get(key) != value for key, value in {
                "mode_id": "day_trading", "mode_version": "1.1.0",
                "setup_id": "day_trading.trend_continuation.long", "setup_version": "1.1.0",
                "exchange": "fake", "environment": "local", "side": "long",
                "execution_capability": "backtest"}.items())
            or not isinstance(frame.get("baseline"), dict)
            or not isinstance(frame.get("effective_config_snapshot"), dict)
            or frame.get("indicator_engine_version") != "php_fallback_v1"):
        raise SignalError("worker baseline or protocol identity invalid")


def _run_symbol(root: Path, out: Path, app_dir: Path, selection: SourceSelection,
                argv: tuple[str, ...], timeout: float, max_stderr: int,
                max_output: int) -> dict:
    session_id = f"research-{selection.symbol}-{selection.dataset_sha256[:20]}"
    env = {"APP_ENV": "prod", "APP_DEBUG": "0", "SYMFONY_DOTENV_PATH": "/dev/null",
           "PATH": os.defpath, "LC_ALL": "C", "LANG": "C"}
    started = time.monotonic()
    deadline = started + timeout
    try:
        proc = subprocess.Popen(argv, cwd=app_dir, stdin=subprocess.PIPE,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                env=env, shell=False, bufsize=0)
    except OSError as exc:
        raise SignalError("worker process could not start") from exc
    assert proc.stdin is not None and proc.stdout is not None and proc.stderr is not None
    producer_error: list[Exception] = []
    sent = {"candles": 0, "batches": 0}

    def produce() -> None:
        try:
            for line in _frames(selection, root, session_id):
                frame = json.loads(line)
                if frame["schema_version"] == "research-signal-candles.v1":
                    sent["candles"] += len(frame["candles"])
                    sent["batches"] += 1
                proc.stdin.write(line)
                proc.stdin.flush()
        except (Exception, BrokenPipeError) as exc:
            producer_error.append(exc)
        finally:
            try:
                proc.stdin.close()
            except OSError:
                pass

    thread = threading.Thread(target=produce, daemon=True)
    thread.start()
    for stream in (proc.stdout, proc.stderr):
        os.set_blocking(stream.fileno(), False)
    selector = selectors.DefaultSelector()
    selector.register(proc.stdout, selectors.EVENT_READ, "stdout")
    selector.register(proc.stderr, selectors.EVENT_READ, "stderr")
    pending = bytearray()
    stderr = bytearray()
    stdout_total = 0
    opened: dict = {}
    summary: dict | None = None
    acknowledgements = results = passed = scored = scored_passed = boundary = 0
    since_ack = 0
    next_eval: int | None = None
    peak_rss: int | None = None
    result_digest = hashlib.sha256()
    boundary_digest = hashlib.sha256()
    partial = out / f"{selection.symbol}.results.partial.ndjson"
    boundary_partial = out / f"{selection.symbol}.boundary.partial.ndjson"
    result_fd = os.open(partial, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    boundary_fd = os.open(boundary_partial, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    failure: Exception | None = None
    try:
        with os.fdopen(result_fd, "wb") as results_file, os.fdopen(boundary_fd, "wb") as boundary_file:
            def handle(line: bytes) -> None:
                nonlocal opened, summary, acknowledgements, results, passed, since_ack, next_eval
                nonlocal scored, scored_passed, boundary
                try:
                    frame = json.loads(line)
                except (ValueError, UnicodeError) as exc:
                    raise SignalError("worker output JSON invalid") from exc
                if not isinstance(frame, dict):
                    raise SignalError("worker output frame invalid")
                schema = frame.get("schema_version")
                if schema == "research-signal-error.v1":
                    raise SignalError("worker structured error: " + str(frame.get("reason_code")))
                if not opened:
                    _validate_opened(frame, session_id, selection)
                    opened = frame
                    return
                if summary is not None:
                    raise SignalError("worker emitted data after summary")
                if schema == "research-signal-result.v1":
                    _identity(frame, opened, session_id, selection)
                    tick = frame.get("evaluated_ms")
                    if type(tick) is not int or tick < _ms(selection.score_start) or tick > _ms(selection.end):
                        raise SignalError("worker result outside scored availability")
                    if next_eval is None:
                        next_eval = _first_evaluable_ms(selection)
                    if tick != next_eval or type(frame.get("passed")) is not bool:
                        raise SignalError("worker result chronology or verdict invalid")
                    next_eval += 900000
                    contexts = frame.get("contexts")
                    if not isinstance(contexts, dict) or any(
                        not isinstance(item, dict) or type(item.get("available_ms")) is not int
                        or item["available_ms"] > tick for item in contexts.values()
                    ):
                        raise SignalError("worker context availability invalid")
                    for key in ("numeric_context_hash", "trace_hash", "result_hash"):
                        if not isinstance(frame.get(key), str) or re.fullmatch(r"sha256:[0-9a-f]{64}", frame[key]) is None:
                            raise SignalError("worker result hash invalid")
                    raw = line + b"\n"
                    if _is_scored_tick(tick, _ms(selection.score_start), _ms(selection.end)):
                        results_file.write(raw)
                        result_digest.update(raw)
                        scored += 1
                        scored_passed += int(frame["passed"])
                    else:
                        boundary_file.write(raw)
                        boundary_digest.update(raw)
                        boundary += 1
                    results += 1
                    since_ack += 1
                    passed += int(frame["passed"])
                    return
                if schema == "research-signal-batch-accepted.v1":
                    if frame.get("session_id") != session_id or type(frame.get("consumed_candles")) is not int:
                        raise SignalError("worker batch acknowledgement invalid")
                    count = frame["consumed_candles"]
                    if (count < 1 or count > 1440 or type(frame.get("emitted_results")) is not int
                            or frame["emitted_results"] != since_ack):
                        raise SignalError("worker batch accounting invalid")
                    acknowledgements += count
                    since_ack = 0
                    return
                if schema == "research-signal-summary.v1":
                    _identity(frame, opened, session_id, selection)
                    summary = frame
                    return
                raise SignalError("worker frame schema invalid")

            while selector.get_map():
                if time.monotonic() >= deadline:
                    raise SignalError("worker wall timeout")
                sample = _rss_sample(proc.pid)
                if sample is not None:
                    peak_rss = max(peak_rss or 0, sample)
                for key, _ in selector.select(timeout=min(0.1, max(0, deadline - time.monotonic()))):
                    chunk = os.read(key.fileobj.fileno(), 65536)
                    if not chunk:
                        selector.unregister(key.fileobj)
                        continue
                    if key.data == "stderr":
                        stderr.extend(chunk)
                        if len(stderr) > max_stderr:
                            raise SignalError("worker stderr size limit")
                        continue
                    stdout_total += len(chunk)
                    if stdout_total > max_output:
                        raise SignalError("worker output size limit")
                    pending.extend(chunk)
                    if len(pending) > MAX_LINE_BYTES and b"\n" not in pending:
                        raise SignalError("worker output line size limit")
                    while b"\n" in pending:
                        line, _, rest = pending.partition(b"\n")
                        pending = bytearray(rest)
                        if len(line) > MAX_LINE_BYTES:
                            raise SignalError("worker output line size limit")
                        handle(bytes(line))
            if pending:
                raise SignalError("worker output trailing partial line")
            thread.join(timeout=max(0, deadline - time.monotonic()))
            if thread.is_alive():
                raise SignalError("worker stdin producer timeout")
            if producer_error:
                raise SignalError("worker input failed: " + str(producer_error[0]))
            exit_code = proc.wait(timeout=max(0.001, deadline - time.monotonic()))
            if exit_code != 0:
                raise SignalError("worker exited nonzero: " + str(exit_code))
            if not opened or summary is None:
                raise SignalError("worker explicit summary missing")
            expected = {"consumed_candles": selection.expected_candles,
                        "warmup_unavailable": selection.expected_warmup,
                        "before_score_start": selection.expected_before_score,
                        "evaluated_ticks": selection.expected_evaluations,
                        "passed_rules": passed, "failed_rules": results - passed,
                        "first_source_open_ms": _ms(selection.start),
                        "last_source_open_ms": _ms(selection.end) - 60000,
                        "source_available_end_ms": _ms(selection.end),
                        "promised_end_ms": _ms(selection.end),
                        "score_start_ms": _ms(selection.score_start),
                        "completion": "complete"}
            if (acknowledgements != selection.expected_candles or sent["candles"] != selection.expected_candles
                    or results != selection.expected_evaluations
                    or any(summary.get(key) != value for key, value in expected.items())):
                raise SignalError("worker completion or count conflict")
            results_file.flush()
            os.fsync(results_file.fileno())
            boundary_file.flush()
            os.fsync(boundary_file.fileno())
    except Exception as exc:
        failure = exc
    finally:
        selector.close()
        _terminate(proc)
        thread.join(timeout=2)
    elapsed = time.monotonic() - started
    if failure is not None:
        raise SignalError(str(failure)) from failure
    target = out / f"{selection.symbol}.results.ndjson"
    boundary_target = out / f"{selection.symbol}.boundary.ndjson"
    os.replace(partial, target)
    os.replace(boundary_partial, boundary_target)
    return {"status": "complete", "source_candles": selection.expected_candles,
            "evaluated_ticks": results, "passed_rules": passed, "failed_rules": results - passed,
            "scored_evaluations": scored, "scored_passed_rules": scored_passed,
            "scored_failed_rules": scored - scored_passed,
            "boundary_diagnostic_count": boundary,
            "boundary_sha256": boundary_digest.hexdigest(),
            "result_sha256": result_digest.hexdigest(), "dataset_sha256": selection.dataset_sha256,
            "manifest_sha256": selection.manifest_sha256, "baseline": opened["baseline"],
            "execution": opened["execution"], "indicator_engine_version": opened["indicator_engine_version"],
            "elapsed_seconds": elapsed, "sampled_child_vm_hwm_bytes": peak_rss,
            "candles_per_second": selection.expected_candles / elapsed if elapsed > 0 else None,
            "evaluations_per_second": results / elapsed if elapsed > 0 and results else None,
            "stderr_sha256": hashlib.sha256(stderr).hexdigest(), "stderr_bytes": len(stderr)}


def run_signals(dataset_root: Path, output_root: Path, app_dir: Path, start: str, end: str,
                score_start: str, symbols: tuple[str, ...], *,
                worker_argv: tuple[str, ...] | None = None,
                wall_timeout: float = DEFAULT_WALL_SECONDS,
                max_stderr_bytes: int = DEFAULT_STDERR_BYTES,
                max_output_bytes: int = MAX_OUTPUT_BYTES) -> dict:
    if (not symbols or len(set(symbols)) != len(symbols) or any(s not in SYMBOLS for s in symbols)
            or wall_timeout <= 0 or max_stderr_bytes < 0 or max_output_bytes <= 0):
        raise SignalError("runner input limits invalid")
    dataset_root = Path(dataset_root)
    app_dir = Path(app_dir)
    if not app_dir.is_absolute() or not app_dir.is_dir():
        raise SignalError("absolute Symfony app directory required")
    selections = [select_sources(dataset_root, start, end, score_start, symbol)
                  for symbol in symbols]
    argv = _worker_argv(app_dir, worker_argv)
    code_hashes = _code_hashes(app_dir, fake_worker=worker_argv is not None)
    output_root = _private_output(Path(output_root), dataset_root)
    report: dict = {"schema": "research-signal-run.v1", "status": "running",
                    "source_start": start, "source_end": end, "score_start": score_start,
                    "symbols": {}, "code_sha256": code_hashes, "worker_argv": list(argv[:1]),
                    "errors": []}
    started = time.monotonic()
    try:
        for selection in selections:
            report["symbols"][selection.symbol] = _run_symbol(
                dataset_root, output_root, app_dir, selection, argv, wall_timeout,
                max_stderr_bytes, max_output_bytes)
        report["status"] = "complete"
        return report
    except Exception as exc:
        report["status"] = "failed"
        report["errors"].append({"type": type(exc).__name__, "message": str(exc)})
        raise SignalError(str(exc)) from exc
    finally:
        elapsed = time.monotonic() - started
        completed = list(report["symbols"].values())
        candles = sum(item["source_candles"] for item in completed)
        evaluations = sum(item["evaluated_ticks"] for item in completed)
        scored = sum(item["scored_evaluations"] for item in completed)
        report["totals"] = {"completed_symbols": len(completed), "source_candles": candles,
                            "evaluated_ticks": evaluations, "scored_evaluations": scored,
                            "elapsed_seconds": elapsed,
                            "candles_per_second": candles / elapsed if elapsed > 0 and candles else None,
                            "evaluations_per_second": evaluations / elapsed if elapsed > 0 and evaluations else None}
        _atomic_json(output_root / "report.json", report)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Run verified canonical research signals")
    parser.add_argument("--dataset-root", type=Path, required=True)
    parser.add_argument("--output-root", type=Path, required=True)
    parser.add_argument("--app-dir", type=Path, required=True)
    parser.add_argument("--start", required=True)
    parser.add_argument("--end", required=True)
    parser.add_argument("--score-start", required=True)
    parser.add_argument("--symbols", required=True)
    args = parser.parse_args(argv)
    try:
        report = run_signals(args.dataset_root, args.output_root, args.app_dir,
                             args.start, args.end, args.score_start,
                             tuple(args.symbols.split(",")))
    except SignalError as exc:
        print(f"research signals failed: {exc}", file=sys.stderr)
        return 1
    print(json.dumps({"status": report["status"], "symbols": list(report["symbols"])}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
