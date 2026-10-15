"""Command line of H2 Gate 1. Every location is a frozen constant of `protocol`; nothing else is accepted.

    python -m scripts.research_h2.cli digest                      # code digest to write on the FROZEN line
    python -m scripts.research_h2.cli claim --stage events --expected-sha256 <body> --owner-go "<reference>"
    python -m scripts.research_h2.cli events
    python -m scripts.research_h2.cli claim --stage outcomes --expected-sha256 <body> --owner-go "<reference>"
    python -m scripts.research_h2.cli outcomes

Run under `nice -n 15`; the guard stops the run when available memory falls below MIN_AVAILABLE_MB or the free disk
below MIN_FREE_DISK_GB (an `events` run stopped before writing may be resumed once with `--resume`). Any other
failure is recorded as `crashed`; the stage stays consumed. One owner go covers both stages: the outcomes claim takes
the events claim's reference and runs with no further decision.
"""

from __future__ import annotations

import argparse
import json
import shutil
import sys
from pathlib import Path

from . import claim as cl
from . import data as dt
from . import events as ev
from . import outcomes as oc
from . import protocol as pr


class GuardStop(RuntimeError):
    pass


def available_mb() -> int:
    for line in Path("/proc/meminfo").read_text().splitlines():
        if line.startswith("MemAvailable:"):
            return int(line.split()[1]) // 1024
    raise RuntimeError("MemAvailable not found")


def guard() -> None:
    memory = available_mb()
    disk = shutil.disk_usage(pr.OUT_DIR if pr.OUT_DIR.exists() else pr.PRIVATE_RESEARCH).free // 1024 ** 3
    if memory < pr.MIN_AVAILABLE_MB or disk < pr.MIN_FREE_DISK_GB:
        raise GuardStop(f"guard: available memory {memory} MB, free disk {disk} GB")


def sources() -> dt.Sources:
    return dt.Sources(pr.H2_TAPE, pr.SWING_TAPE)


def _run(stage: str, resume: bool, body) -> int:
    cl.consume(pr.OUT_DIR, stage, resume=resume)
    try:
        body()
    except GuardStop as stop:
        cl.abort(pr.OUT_DIR, stage, str(stop))
        print(str(stop), file=sys.stderr)
        return 3
    except BaseException as error:  # the stage stays consumed: no resumption, a rerun needs a written amendment
        cl.crash(pr.OUT_DIR, stage, f"{type(error).__name__}: {error}")
        raise
    cl.finish(pr.OUT_DIR, stage)
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_h2")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("digest")
    c = sub.add_parser("claim")
    c.add_argument("--stage", required=True, choices=cl.STAGES)
    c.add_argument("--expected-sha256", required=True)
    c.add_argument("--owner-go", required=True)
    e = sub.add_parser("events")
    e.add_argument("--resume", action="store_true")
    sub.add_parser("outcomes")
    args = parser.parse_args(argv)
    if args.command == "digest":
        print(json.dumps({"code_sha256": cl.code_digest(), "files": cl.code_identity()}, indent=1))
        return 0
    if args.command == "claim":
        document = cl.claim(pr.OUT_DIR, args.stage, pr.PREREG_PATH, args.expected_sha256, args.owner_go)
        print(json.dumps({"stage": document["stage"], "code_sha256": document["code_sha256"]}))
        return 0
    if args.command == "events":
        return _run("events", args.resume, lambda: ev.run(sources(), pr.OUT_DIR, guard))
    if args.command == "outcomes":
        expected = cl.events_sha256(pr.OUT_DIR)
        return _run("outcomes", False, lambda: oc.run(pr.OUT_DIR / ev.EVENTS_FILE, expected, sources(), pr.OUT_DIR, guard))
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
