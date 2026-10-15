"""Single-use claims (model: research_exec/q3r2_c2.py `claim`).

A stage (E0a census, E1 replay, E1 analysis) runs only after `claim` wrote `<stage>-claim.json`,
which records the frozen protocol's body sha256 (checked against the FROZEN line of the file),
the code identity and the prerequisite outputs. `consume` is the first thing a run does, before
any data is read: it re-checks the code identity and creates `<stage>-consumed.json`
exclusively; a second run is refused. The only exception is a run that the memory guard
stopped before it wrote any output (`aborted_by_guard`): it may be resumed with identical code,
and the resumption is recorded. The analysis stage has no resumption.
"""

from __future__ import annotations

import hashlib
import json
import os
from datetime import datetime, timezone
from pathlib import Path

STAGES = ("E0a", "E1", "analysis")
RESUMABLE = ("E0a", "E1")
PREREQUISITES = {"E0a": (), "E1": ("E0a",), "analysis": ("E1",)}
PREREQUISITE_OUTPUTS = {"E0a": ("e0a-census.json", "e0a-trigger.json"), "E1": ("e1-replay.npz", "e1-manifest.json")}


class ClaimError(SystemExit):
    pass


def now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def sha256_file(path: Path) -> str:
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def body_sha256(path: Path, marker: str) -> str:
    """sha256 of the file content up to and including the line `marker` and the following blank
    line (the programme's freeze convention)."""
    text = Path(path).read_text()
    start = text.index(marker)
    end = text.index("\n", start) + 1
    body = text[:end] + "\n"
    if text[end:end + 1] != "\n":
        raise ClaimError(f"{path}: the freeze marker must be followed by a blank line")
    return hashlib.sha256(body.encode()).hexdigest()


def frozen_hash(path: Path) -> str | None:
    """The hash on the protocol's `FROZEN… <time> body sha256 <hash>` line (`FROZEN` or
    `FROZEN_NOT_EXECUTED`, the programme's two forms), None while unfrozen."""
    for line in Path(path).read_text().splitlines():
        if line.split(" ", 1)[0] in ("FROZEN", "FROZEN_NOT_EXECUTED") and "body sha256 " in line:
            return line.split("body sha256 ", 1)[1].split()[0]
    return None


def code_identity() -> dict[str, str]:
    here = Path(__file__).resolve().parent
    files = sorted(here.glob("*.py")) + sorted((here.parent / "research_exec").glob("*.py"))
    return {f"{path.parent.name}/{path.name}": sha256_file(path) for path in files}


def _write_exclusive(path: Path, document: dict) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644)
    with os.fdopen(fd, "w") as handle:
        handle.write(json.dumps(document, indent=1, sort_keys=True) + "\n")


def claim(out: Path, stage: str, prereg: Path, marker: str, expected_sha256: str) -> dict:
    if stage not in STAGES:
        raise ClaimError(f"unknown stage {stage}")
    out = Path(out)
    out.mkdir(parents=True, exist_ok=True)
    target = out / f"{stage}-claim.json"
    if target.exists():
        raise ClaimError(f"{stage}: claim already exists — each stage reads its data once")
    actual = body_sha256(prereg, marker)
    if actual != expected_sha256:
        raise ClaimError(f"{stage}: protocol body sha256 {actual} differs from the expected {expected_sha256}")
    if frozen_hash(prereg) != actual:
        raise ClaimError(f"{stage}: the protocol is not frozen with this hash (no matching FROZEN line)")
    prerequisites = {}
    for required in PREREQUISITES[stage]:
        consumed = out / f"{required}-consumed.json"
        if not consumed.exists() or json.loads(consumed.read_text()).get("status") != "complete":
            raise ClaimError(f"{stage}: stage {required} is not complete")
        for name in PREREQUISITE_OUTPUTS[required]:
            prerequisites[name] = sha256_file(out / name)
    document = {"stage": stage, "claimed_at": now(), "protocol_body_sha256": actual, "code": code_identity(), "prerequisites": prerequisites}
    _write_exclusive(target, document)
    return document


def consume(out: Path, stage: str, resume: bool = False) -> dict:
    """First call of a run, before any data is read."""
    out = Path(out)
    claimed = out / f"{stage}-claim.json"
    if not claimed.exists():
        raise ClaimError(f"{stage}: no claim — run `claim` first")
    document = json.loads(claimed.read_text())
    if document["code"] != code_identity():
        raise ClaimError(f"{stage}: the code changed since the claim")
    for name, digest in document["prerequisites"].items():
        if sha256_file(out / name) != digest:
            raise ClaimError(f"{stage}: prerequisite {name} changed since the claim")
    target = out / f"{stage}-consumed.json"
    if target.exists():
        previous = json.loads(target.read_text())
        if resume and stage in RESUMABLE and previous.get("status") == "aborted_by_guard":
            previous.setdefault("resumptions", []).append({"at": now(), "after": previous.get("reason")})
            previous.update({"status": "running", "reason": None})
            target.write_text(json.dumps(previous, indent=1, sort_keys=True) + "\n")
            return previous
        raise ClaimError(f"{stage}: already consumed ({previous.get('status')}) — a second read is refused")
    record = {"stage": stage, "started_at": now(), "status": "running", "claim_sha256": sha256_file(claimed)}
    _write_exclusive(target, record)
    return record


def _update(out: Path, stage: str, **fields) -> dict:
    target = Path(out) / f"{stage}-consumed.json"
    record = json.loads(target.read_text())
    record.update(fields)
    target.write_text(json.dumps(record, indent=1, sort_keys=True) + "\n")
    return record


def finish(out: Path, stage: str, outputs: list[str]) -> dict:
    return _update(out, stage, status="complete", finished_at=now(), outputs={name: sha256_file(Path(out) / name) for name in outputs})


def abort(out: Path, stage: str, reason: str) -> dict:
    return _update(out, stage, status="aborted_by_guard", reason=reason, aborted_at=now())
