"""Driver of the leverage study (PREREGISTRATION-leverage-gt2.md). Every data-reading command is
single use (`claim.py`): `claim` writes the stage's claim, the command consumes it first.

    python -m scripts.research_lev.cli claim   --stage E0a --out DIR --prereg P --expected-sha256 H
    python -m scripts.research_lev.cli census  --out DIR --tape-dir T --instruments I [--workers 2]
    python -m scripts.research_lev.cli claim   --stage E1 ...
    python -m scripts.research_lev.cli replay  --out DIR --tape-dir T --instruments I [--workers 2]
    python -m scripts.research_lev.cli claim   --stage analysis ...
    python -m scripts.research_lev.cli analyse --out DIR

Resources: by-series workers (≤ 2), a series at a time per worker; the memory guard stops the
run cleanly (status `aborted_by_guard`, no output written) when MemAvailable < 4000 MB.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import multiprocessing
import sys
import time
from pathlib import Path

import numpy as np

from . import claim as claims
from .census import POLICY, aggregate, census_series, streams_of, trigger
from .engine import COUNTER_KEYS, EXPORT_DTYPE, replay_arm
from .leverage import assert_admissible
from .protocol import CONFIGURATION_IDS, FREEZE_MARKER, P2_SYMBOLS, VOLUME_THRESHOLDS, WINDOWS, arm_key
from .stats import analyse

MEMORY_FLOOR_MB = 4000
MAX_WORKERS = 2
COUNTER_DTYPE = np.dtype([("config", "<i2"), ("arm", "<i1"), ("series", "<i2")] + [(key, "<i8") for key in COUNTER_KEYS])


class MemoryGuard(RuntimeError):
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


def load_series(tape_dir: Path, instruments: Path, klines_dir: Path | None):
    from ..research_exec.screen import load_binance_series

    series, missing = load_binance_series(tape_dir, instruments, "training", P2_SYMBOLS, WINDOWS, setups=True, klines_dir=klines_dir, setup_volume_thresholds=VOLUME_THRESHOLDS)
    for item in series:
        assert_admissible(item.name)
    return series, missing


def input_hashes(tape_dir: Path, instruments: Path) -> dict[str, str]:
    out = {"instruments": claims.sha256_file(instruments)}
    for name in ("manifest-aggtrades.json", "manifest-klines.json"):
        path = tape_dir / name
        out[name] = claims.sha256_file(path) if path.exists() else "missing"
    return out


_SERIES: list = []
_TASK: dict = {}


def _init(series: list, task: dict) -> None:
    global _SERIES, _TASK
    _SERIES, _TASK = series, task


def _census_one(index: int) -> tuple[int, str, dict]:
    item = _SERIES[index]
    data = item.data(index)
    parts = census_series(data, item.name, CONFIGURATION_IDS)
    del data
    return index, item.name, parts


def _replay_one(index: int) -> tuple[int, np.ndarray, np.ndarray]:
    item = _SERIES[index]
    assert_admissible(item.name)
    data = item.data(index)
    exports, counters = [], []
    arms = _TASK["arms"]
    for ci, identifier in enumerate(CONFIGURATION_IDS):
        streams = streams_of(identifier, data.features)
        for ai, key in enumerate(arms):
            definition, band = key.split(":")
            export, counts = replay_arm(data, streams, POLICY, definition, band, config=ci, arm=ai)
            exports.append(export)
            counters.append(tuple([ci, ai, index] + [counts[k] for k in COUNTER_KEYS]))
    del data
    export = np.concatenate(exports) if exports else np.zeros(0, dtype=EXPORT_DTYPE)
    return index, export, np.array(counters, dtype=COUNTER_DTYPE)


def run_pool(function, count: int, series: list, task: dict, workers: int, floor_mb: float = MEMORY_FLOOR_MB) -> list:
    """At most `workers` series in flight; the memory guard is checked before each submission and
    after each completion."""
    workers = max(1, min(workers, MAX_WORKERS))
    results, pending, next_index = [], [], 0
    context = multiprocessing.get_context("fork")
    with context.Pool(workers, initializer=_init, initargs=(series, task), maxtasksperchild=1) as pool:
        try:
            while next_index < count or pending:
                while next_index < count and len(pending) < workers:
                    guard(floor_mb)
                    pending.append(pool.apply_async(function, (next_index,)))
                    next_index += 1
                done = [job for job in pending if job.ready()]
                if not done:
                    time.sleep(0.5)
                    continue
                for job in done:
                    results.append(job.get())
                    pending.remove(job)
                    print(f"{len(results)}/{count} series", file=sys.stderr, flush=True)
                guard(floor_mb)
        except MemoryGuard:
            pool.terminate()
            raise
    return results


def cmd_claim(args) -> int:
    document = claims.claim(Path(args.out), args.stage, Path(args.prereg), FREEZE_MARKER, args.expected_sha256)
    print(json.dumps({"stage": document["stage"], "claimed_at": document["claimed_at"], "protocol_body_sha256": document["protocol_body_sha256"]}))
    return 0


def cmd_census(args) -> int:
    out = Path(args.out)
    claims.consume(out, "E0a", resume=args.resume)
    try:
        guard()
        series, missing = load_series(Path(args.tape_dir), Path(args.instruments), Path(args.klines_dir) if args.klines_dir else None)
        started = time.time()
        results = run_pool(_census_one, len(series), series, {}, args.workers)
    except MemoryGuard as error:
        claims.abort(out, "E0a", str(error))
        print(json.dumps({"stage": "E0a", "status": "aborted_by_guard", "reason": str(error)}))
        return 3
    results.sort(key=lambda item: item[0])
    census = aggregate([(name, parts) for _, name, parts in results])
    census.update({"configurations": list(CONFIGURATION_IDS), "symbols": list(P2_SYMBOLS), "windows": list(WINDOWS), "missing_days": missing,
                   "series": [item.name for item in series], "elapsed_seconds": round(time.time() - started, 1), "inputs": input_hashes(Path(args.tape_dir), Path(args.instruments)),
                   "code": claims.code_identity(), "note": "pre-fill upper envelope: decision-time inputs only, no order, no fill, no exit, no pair blocking"})
    rule = trigger(census["pooled"])
    (out / "e0a-census.json").write_text(json.dumps(census, indent=1, sort_keys=True) + "\n")
    (out / "e0a-trigger.json").write_text(json.dumps(rule, indent=1, sort_keys=True) + "\n")
    claims.finish(out, "E0a", ["e0a-census.json", "e0a-trigger.json"])
    print(json.dumps({"stage": "E0a", "status": "complete", "e1_arms": {d: v["e1_arms"] for d, v in rule["definitions"].items()}}))
    return 0


def cmd_replay(args) -> int:
    out = Path(args.out)
    claims.consume(out, "E1", resume=args.resume)
    rule = json.loads((out / "e0a-trigger.json").read_text())
    arms = [arm_key(definition, band) for definition, spec in rule["definitions"].items() for band in spec["e1_arms"]]
    manifest = {"configurations": list(CONFIGURATION_IDS), "arms": arms, "e0a_trigger_sha256": claims.sha256_file(out / "e0a-trigger.json")}
    if not arms:
        np.savez_compressed(out / "e1-replay.npz", export=np.zeros(0, dtype=EXPORT_DTYPE), counters=np.zeros(0, dtype=COUNTER_DTYPE))
        manifest.update({"series": [], "note": "no arm triggered by E0a: E1 not run"})
        (out / "e1-manifest.json").write_text(json.dumps(manifest, indent=1, sort_keys=True) + "\n")
        claims.finish(out, "E1", ["e1-replay.npz", "e1-manifest.json"])
        print(json.dumps({"stage": "E1", "status": "complete", "arms": []}))
        return 0
    try:
        guard()
        series, missing = load_series(Path(args.tape_dir), Path(args.instruments), Path(args.klines_dir) if args.klines_dir else None)
        started = time.time()
        results = run_pool(_replay_one, len(series), series, {"arms": arms}, args.workers)
    except MemoryGuard as error:
        claims.abort(out, "E1", str(error))
        print(json.dumps({"stage": "E1", "status": "aborted_by_guard", "reason": str(error)}))
        return 3
    results.sort(key=lambda item: item[0])
    export = np.concatenate([item[1] for item in results]) if results else np.zeros(0, dtype=EXPORT_DTYPE)
    counters = np.concatenate([item[2] for item in results]) if results else np.zeros(0, dtype=COUNTER_DTYPE)
    np.savez_compressed(out / "e1-replay.npz", export=export, counters=counters)
    totals = {key: {k: int(counters[k][counters["arm"] == ai].sum()) for k in COUNTER_KEYS} for ai, key in enumerate(arms)}
    manifest.update({"series": [item.name for item in series], "missing_days": missing, "elapsed_seconds": round(time.time() - started, 1),
                     "inputs": input_hashes(Path(args.tape_dir), Path(args.instruments)), "code": claims.code_identity(), "counters_total": totals,
                     "export_sha256": hashlib.sha256(export.tobytes()).hexdigest()})
    (out / "e1-manifest.json").write_text(json.dumps(manifest, indent=1, sort_keys=True) + "\n")
    claims.finish(out, "E1", ["e1-replay.npz", "e1-manifest.json"])
    print(json.dumps({"stage": "E1", "status": "complete", "arms": arms}))
    return 0


def cmd_analyse(args) -> int:
    out = Path(args.out)
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
    c.add_argument("--out", required=True)
    c.add_argument("--prereg", required=True)
    c.add_argument("--expected-sha256", required=True)
    for name in ("census", "replay"):
        p = sub.add_parser(name)
        p.add_argument("--out", required=True)
        p.add_argument("--tape-dir", required=True)
        p.add_argument("--instruments", required=True)
        p.add_argument("--klines-dir", default="")
        p.add_argument("--workers", type=int, default=MAX_WORKERS)
        p.add_argument("--resume", action="store_true", help="only after a memory-guard abort, with identical code")
    a = sub.add_parser("analyse")
    a.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    return {"claim": cmd_claim, "census": cmd_census, "replay": cmd_replay, "analyse": cmd_analyse}[args.command](args)


if __name__ == "__main__":
    sys.exit(main())
