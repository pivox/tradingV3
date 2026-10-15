"""Driver of the leverage study (PREREGISTRATION-leverage-gt2.md). Every data-reading command is
single use (`claim.py`): `claim` writes the stage's claim, the command consumes it first.

    python -m scripts.research_lev.cli claim   --stage E0a
    python -m scripts.research_lev.cli census  [--workers 2]
    python -m scripts.research_lev.cli claim   --stage E1
    python -m scripts.research_lev.cli replay  [--workers 2]
    python -m scripts.research_lev.cli claim   --stage analysis
    python -m scripts.research_lev.cli analyse

The protocol file, its `.sha256`, the output directory, the tape and the instrument file are the
frozen ones of `protocol.py`; an explicit `--prereg`, `--expected-sha256`, `--out`, `--tape-dir`
or `--instruments` that differs from them is refused. Before a claim is consumed, the inputs are
checked without reading any tape content (instrument and manifest sha256, the 76 planned
pair-week series with no missing day, every klines month present); a failed check refuses the
run and leaves the claim unconsumed.

Resources: by-series workers (≤ 2), a series at a time per worker; the memory guard is checked
on every poll (0.5 s) and stops the run cleanly (status `aborted_by_guard`, no output written)
when MemAvailable < 4000 MB; a worker that dies (e.g. killed by the kernel) ends the run the
same way instead of hanging.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import multiprocessing
import os
import sys
import time
from pathlib import Path

import numpy as np

from . import claim as claims
from . import protocol as pr
from .census import POLICY, aggregate, census_series, streams_of, trigger, trigger_counts
from .engine import COUNTER_KEYS, EXPORT_DTYPE, replay_arm
from .leverage import assert_admissible
from .protocol import FREEZE_MARKER, P2_SYMBOLS, VOLUME_THRESHOLDS, WINDOWS, arm_key
from .stats import analyse

MEMORY_FLOOR_MB = 4000
MAX_WORKERS = 2
COUNTER_DTYPE = np.dtype([("config", "<i2"), ("arm", "<i1"), ("series", "<i2")] + [(key, "<i8") for key in COUNTER_KEYS])


class MemoryGuard(RuntimeError):
    pass


class WorkerLost(MemoryGuard):
    """A worker died without returning its series (most likely the kernel OOM killer)."""


class InputError(SystemExit):
    pass


def mem_available_mb() -> float:
    for line in Path("/proc/meminfo").read_text().splitlines():
        if line.startswith("MemAvailable:"):
            return int(line.split()[1]) / 1024.0
    raise RuntimeError("MemAvailable not found")


def guard(floor_mb: float = MEMORY_FLOOR_MB) -> None:
    available = mem_available_mb()
    if available < floor_mb:
        raise MemoryGuard(f"MemAvailable {available:.0f} MB < {floor_mb} MB")


def load_series(tape_dir: Path, instruments: Path):
    """Plans the series (lazy loaders: no tape content is read here)."""
    from ..research_exec.screen import load_binance_series

    series, missing = load_binance_series(tape_dir, instruments, "training", P2_SYMBOLS, WINDOWS, setups=True, klines_dir=None, setup_volume_thresholds=VOLUME_THRESHOLDS)
    for item in series:
        assert_admissible(item.name)
    return series, missing


def input_hashes(tape_dir: Path, instruments: Path) -> dict[str, str]:
    out = {"instruments": claims.sha256_file(instruments) if Path(instruments).exists() else "missing"}
    for name in ("manifest-aggtrades.json", "manifest-klines.json"):
        path = tape_dir / name
        out[name] = claims.sha256_file(path) if path.exists() else "missing"
    return out


def _same_path(given: str | Path | None, frozen: Path, what: str) -> Path:
    if given in (None, ""):
        return Path(frozen)
    if Path(given).resolve() != Path(frozen).resolve():
        raise InputError(f"{what} {given} is not the frozen {frozen}")
    return Path(frozen)


def missing_klines(tape_dir: Path, series: list) -> list[str]:
    from ..research_exec.platform_setups import months_covering
    from ..research_lev.leverage import symbol_of_series

    out = []
    for item in series:
        symbol = symbol_of_series(item.name)
        for month in months_covering(item.start_s, item.end_s):
            if not (tape_dir / "klines" / symbol / f"{symbol}-1m-{month}.npz").exists():
                out.append(f"{symbol}:{month}")
    return sorted(set(out))


def preflight(tape_dir: Path, instruments: Path) -> tuple[list, dict]:
    """Checks made BEFORE a claim is consumed, reading no tape content: input hashes, the planned
    series (exactly the 76 frozen pair-weeks, no missing day) and the klines months."""
    hashes = input_hashes(tape_dir, instruments)
    if hashes != pr.INPUT_SHA256:
        raise InputError(f"input hashes {hashes} differ from the frozen {pr.INPUT_SHA256}")
    series, missing = load_series(tape_dir, instruments)
    names = tuple(item.name for item in series)
    if names != pr.EXPECTED_SERIES or missing:
        raise InputError(f"planned series differ from the 76 frozen pair-weeks ({len(names)} planned, missing days {missing[:5]}…)")
    lacking = missing_klines(tape_dir, series)
    if lacking:
        raise InputError(f"klines months missing: {lacking[:5]}…")
    return series, hashes


_SERIES: list = []
_TASK: dict = {}


_PIDS = None


def _init(series: list, task: dict, pids=None) -> None:
    global _SERIES, _TASK, _PIDS
    _SERIES, _TASK, _PIDS = series, task, pids


def _started(index: int) -> None:
    if _PIDS is not None:
        _PIDS[index] = os.getpid()


def _run_job(function, index: int):
    """Every job records its worker's pid first, so that a worker death is detected."""
    _started(index)
    return function(index)


def _census_one(index: int) -> tuple[int, str, dict]:
    item = _SERIES[index]
    data = item.data(index)
    parts = census_series(data, item.name, tuple(_TASK["configurations"]))
    del data
    return index, item.name, parts


def _replay_one(index: int) -> tuple[int, np.ndarray, np.ndarray]:
    item = _SERIES[index]
    assert_admissible(item.name)
    data = item.data(index)
    exports, counters = [], []
    arms = _TASK["arms"]
    for ci, identifier in enumerate(_TASK["configurations"]):
        streams = streams_of(identifier, data.features)
        for ai, key in enumerate(arms):
            definition, band = key.split(":")
            export, counts = replay_arm(data, streams, POLICY, definition, band, config=ci, arm=ai)
            exports.append(export)
            counters.append(tuple([ci, ai, index] + [counts[k] for k in COUNTER_KEYS]))
    del data
    export = np.concatenate(exports) if exports else np.zeros(0, dtype=EXPORT_DTYPE)
    return index, export, np.array(counters, dtype=COUNTER_DTYPE)


def _alive(pid: int) -> bool:
    try:
        state = Path(f"/proc/{pid}/stat").read_text().rsplit(")", 1)[1].split()[0]
    except (FileNotFoundError, ProcessLookupError, IndexError):
        return False
    return state not in ("Z", "X")


def run_pool(function, count: int, series: list, task: dict, workers: int, floor_mb: float = MEMORY_FLOOR_MB, poll_s: float = 0.5, grace_s: float = 5.0) -> list:
    """At most `workers` series in flight. The memory guard is checked before each submission and
    on every poll while series are in flight; a worker that dies before returning its series
    raises `WorkerLost` after `grace_s` (no hang). Either way the pool is terminated and nothing
    is written by the caller."""
    workers = max(1, min(workers, MAX_WORKERS))
    results, pending, next_index = [], {}, 0
    context = multiprocessing.get_context("fork")
    pids = context.Array("q", max(count, 1), lock=False)
    dead_since: dict[int, float] = {}
    with context.Pool(workers, initializer=_init, initargs=(series, task, pids), maxtasksperchild=1) as pool:
        try:
            while next_index < count or pending:
                while next_index < count and len(pending) < workers:
                    guard(floor_mb)
                    pending[next_index] = pool.apply_async(_run_job, (function, next_index))
                    next_index += 1
                done = [index for index, job in pending.items() if job.ready()]
                if not done:
                    guard(floor_mb)
                    for index in pending:
                        pid = int(pids[index])
                        if pid > 0 and not _alive(pid):
                            first = dead_since.setdefault(index, time.monotonic())
                            if time.monotonic() - first > grace_s and not pending[index].ready():
                                raise WorkerLost(f"worker {pid} of series {index} died without a result")
                    time.sleep(poll_s)
                    continue
                for index in done:
                    results.append(pending.pop(index).get())
                    print(f"{len(results)}/{count} series", file=sys.stderr, flush=True)
                guard(floor_mb)
        except MemoryGuard:
            pool.terminate()
            raise
    return results


def frozen_expected_sha256() -> str:
    path = pr.SHA256_PATH
    if not Path(path).exists():
        raise claims.ClaimError(f"{path} does not exist: the protocol is not frozen")
    return Path(path).read_text().split()[0]


def cmd_claim(args) -> int:
    prereg = _same_path(args.prereg, pr.PREREG_PATH, "--prereg")
    out = _same_path(args.out, pr.OUT_DIR, "--out")
    expected = frozen_expected_sha256()
    if args.expected_sha256 and args.expected_sha256 != expected:
        raise claims.ClaimError(f"--expected-sha256 {args.expected_sha256} is not the frozen {expected} ({pr.SHA256_PATH})")
    document = claims.claim(out, args.stage, prereg, FREEZE_MARKER, expected)
    print(json.dumps({"stage": document["stage"], "claimed_at": document["claimed_at"], "protocol_body_sha256": document["protocol_body_sha256"], "code_sha256": document["code_sha256"]}))
    return 0


def _paths(args) -> tuple[Path, Path, Path]:
    return (_same_path(args.out, pr.OUT_DIR, "--out"), _same_path(getattr(args, "tape_dir", None), pr.TAPE_DIR, "--tape-dir"),
            _same_path(getattr(args, "instruments", None), pr.INSTRUMENTS_PATH, "--instruments"))


def _check_complete(series: list, results: list) -> None:
    if len(series) != len(pr.EXPECTED_SERIES) or len(results) != len(series):
        raise RuntimeError(f"{len(results)} of {len(series)} series returned, {len(pr.EXPECTED_SERIES)} frozen: the stage is not finished")


def cmd_census(args) -> int:
    out, tape_dir, instruments = _paths(args)
    series, hashes = preflight(tape_dir, instruments)
    configurations = pr.active_configurations()
    claims.consume(out, "E0a", resume=args.resume)
    try:
        guard()
        started = time.time()
        results = run_pool(_census_one, len(series), series, {"configurations": configurations}, args.workers)
    except MemoryGuard as error:
        claims.abort(out, "E0a", f"{type(error).__name__}: {error}")
        print(json.dumps({"stage": "E0a", "status": "aborted_by_guard", "reason": str(error)}))
        return 3
    _check_complete(series, results)
    results.sort(key=lambda item: item[0])
    census = aggregate([(name, parts) for _, name, parts in results])
    census.update({"configurations": list(configurations), "symbols": list(P2_SYMBOLS), "windows": list(WINDOWS), "missing_days": [],
                   "series": [item.name for item in series], "elapsed_seconds": round(time.time() - started, 1), "inputs": hashes,
                   "code": claims.code_identity(), "multiplicity_rule": pr.multiplicity_rule(), "r1_both_rule": pr.R1_BOTH_RULE,
                   "note": "pre-fill upper envelope: decision-time inputs only, no order, no fill, no exit, no pair blocking"})
    rule = trigger(trigger_counts(census))
    rule["multiplicity_rule"] = pr.multiplicity_rule()
    (out / "e0a-census.json").write_text(json.dumps(census, indent=1, sort_keys=True) + "\n")
    (out / "e0a-trigger.json").write_text(json.dumps(rule, indent=1, sort_keys=True) + "\n")
    claims.finish(out, "E0a", ["e0a-census.json", "e0a-trigger.json"])
    print(json.dumps({"stage": "E0a", "status": "complete", "e1_arms": {d: v["e1_arms"] for d, v in rule["definitions"].items()}}))
    return 0


def cmd_replay(args) -> int:
    out, tape_dir, instruments = _paths(args)
    series, hashes = preflight(tape_dir, instruments)
    configurations = pr.active_configurations()
    census = json.loads((out / "e0a-census.json").read_text())
    rule = json.loads((out / "e0a-trigger.json").read_text())
    expected = trigger(trigger_counts(census))
    expected["multiplicity_rule"] = pr.multiplicity_rule()
    if expected != rule or census.get("configurations") != list(configurations):
        raise claims.ClaimError("E1: e0a-trigger.json is not the E0a rule applied to e0a-census.json under the frozen decisions")
    arms = [arm_key(definition, band) for definition, spec in rule["definitions"].items() for band in spec["e1_arms"]]
    claims.consume(out, "E1", resume=args.resume)
    manifest = {"configurations": list(configurations), "arms": arms, "e0a_trigger_sha256": claims.sha256_file(out / "e0a-trigger.json")}
    if not arms:
        np.savez_compressed(out / "e1-replay.npz", export=np.zeros(0, dtype=EXPORT_DTYPE), counters=np.zeros(0, dtype=COUNTER_DTYPE))
        manifest.update({"series": [], "note": "no arm triggered by E0a: E1 not run"})
        (out / "e1-manifest.json").write_text(json.dumps(manifest, indent=1, sort_keys=True) + "\n")
        claims.finish(out, "E1", ["e1-replay.npz", "e1-manifest.json"])
        print(json.dumps({"stage": "E1", "status": "complete", "arms": []}))
        return 0
    try:
        guard()
        started = time.time()
        results = run_pool(_replay_one, len(series), series, {"arms": arms, "configurations": configurations}, args.workers)
    except MemoryGuard as error:
        claims.abort(out, "E1", f"{type(error).__name__}: {error}")
        print(json.dumps({"stage": "E1", "status": "aborted_by_guard", "reason": str(error)}))
        return 3
    _check_complete(series, results)
    results.sort(key=lambda item: item[0])
    export = np.concatenate([item[1] for item in results]) if results else np.zeros(0, dtype=EXPORT_DTYPE)
    counters = np.concatenate([item[2] for item in results]) if results else np.zeros(0, dtype=COUNTER_DTYPE)
    np.savez_compressed(out / "e1-replay.npz", export=export, counters=counters)
    totals = {key: {k: int(counters[k][counters["arm"] == ai].sum()) for k in COUNTER_KEYS} for ai, key in enumerate(arms)}
    manifest.update({"series": [item.name for item in series], "missing_days": [], "elapsed_seconds": round(time.time() - started, 1),
                     "inputs": hashes, "code": claims.code_identity(), "counters_total": totals,
                     "export_sha256": hashlib.sha256(export.tobytes()).hexdigest()})
    (out / "e1-manifest.json").write_text(json.dumps(manifest, indent=1, sort_keys=True) + "\n")
    claims.finish(out, "E1", ["e1-replay.npz", "e1-manifest.json"])
    print(json.dumps({"stage": "E1", "status": "complete", "arms": arms}))
    return 0


def cmd_analyse(args) -> int:
    out = _same_path(args.out, pr.OUT_DIR, "--out")
    claims.consume(out, "analysis")
    with np.load(out / "e1-replay.npz") as data:
        export = data["export"]
    manifest = json.loads((out / "e1-manifest.json").read_text())
    census = json.loads((out / "e0a-census.json").read_text())
    document = analyse(export, manifest, census)
    (out / "e1-analysis.json").write_text(json.dumps(document, indent=1, sort_keys=True) + "\n")
    claims.finish(out, "analysis", ["e1-analysis.json"])
    print(json.dumps({"stage": "analysis", "status": "complete", "primary_verdict": document["primary"]["verdict"]}))
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_lev.cli")
    sub = parser.add_subparsers(dest="command", required=True)
    c = sub.add_parser("claim")
    c.add_argument("--stage", choices=claims.STAGES, required=True)
    c.add_argument("--out", default="")
    c.add_argument("--prereg", default="")
    c.add_argument("--expected-sha256", default="")
    for name in ("census", "replay"):
        p = sub.add_parser(name)
        p.add_argument("--out", default="")
        p.add_argument("--tape-dir", default="")
        p.add_argument("--instruments", default="")
        p.add_argument("--workers", type=int, default=MAX_WORKERS)
        p.add_argument("--resume", action="store_true", help="only after a memory-guard abort, with identical code, within MAX_GUARD_RESUMPTIONS")
    a = sub.add_parser("analyse")
    a.add_argument("--out", default="")
    args = parser.parse_args(argv)
    return {"claim": cmd_claim, "census": cmd_census, "replay": cmd_replay, "analyse": cmd_analyse}[args.command](args)


if __name__ == "__main__":
    sys.exit(main())
