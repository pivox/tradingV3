"""Single-use claims (model: research_exec/q3r2_c2.py `claim`).

A stage (E0a census, E1 replay, E1 analysis) runs only after `claim` wrote `<stage>-claim.json`.
A claim is refused unless:

- every owner decision of the protocol is set (`protocol.unresolved_decisions()` is empty);
- the protocol body sha256 equals the expected hash AND the hash on the protocol's FROZEN line;
- the FROZEN line also carries `code sha256 <digest>` and the current code identity (every
  `research_lev` and `research_exec` module) has that digest: the code is pinned to the freeze,
  for every stage;
- for E1 and the analysis, the code identity also equals the one recorded in `E0a-claim.json`;
- every prerequisite stage is complete and each of its outputs still has the sha256 recorded when
  that stage finished (`<stage>-consumed.json` `outputs`); the analysis depends on E0a and E1.

`consume` is the first thing a run does, before any data is read: it re-checks the protocol body
against the FROZEN line, the code and the prerequisites, and creates `<stage>-consumed.json`
exclusively; a second run is refused. The only exception is a run that the memory guard (or the
loss of a worker) stopped before it wrote any output (`aborted_by_guard`): it may be resumed with
identical code at most `protocol.MAX_GUARD_RESUMPTIONS` times (owner decision D3: 0 or 1), and
each resumption is recorded. The analysis stage has no resumption.
"""

from __future__ import annotations

import hashlib
import json
import os
from datetime import datetime, timezone
from pathlib import Path

from . import protocol as pr

STAGES = ("E0a", "E1", "analysis")
RESUMABLE = ("E0a", "E1")
PREREQUISITES = {"E0a": (), "E1": ("E0a",), "analysis": ("E0a", "E1")}
STAGE_OUTPUTS = {"E0a": ("e0a-census.json", "e0a-trigger.json"), "E1": ("e1-replay.npz", "e1-manifest.json"), "analysis": ("e1-analysis.json",)}
PREREQUISITE_OUTPUTS = {stage: STAGE_OUTPUTS[stage] for stage in ("E0a", "E1")}


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


def frozen_line(path: Path) -> dict | None:
    """The protocol's `FROZEN… <time> body sha256 <hash> [code sha256 <digest>] …` line (`FROZEN`
    or `FROZEN_NOT_EXECUTED`, the programme's two forms), None while unfrozen."""
    for line in Path(path).read_text().splitlines():
        words = line.split()
        if words and words[0] in ("FROZEN", "FROZEN_NOT_EXECUTED") and "body sha256 " in line:
            out = {"status": words[0], "time": words[1] if len(words) > 1 else None, "body": line.split("body sha256 ", 1)[1].split()[0], "code": None}
            if "code sha256 " in line:
                out["code"] = line.split("code sha256 ", 1)[1].split()[0]
            return out
    return None


def frozen_hash(path: Path) -> str | None:
    line = frozen_line(path)
    return None if line is None else line["body"]


def code_identity() -> dict[str, str]:
    here = Path(__file__).resolve().parent
    files = sorted(here.glob("*.py")) + sorted((here.parent / "research_exec").glob("*.py"))
    return {f"{path.parent.name}/{path.name}": sha256_file(path) for path in files}


def code_digest(identity: dict[str, str] | None = None) -> str:
    """sha256 of the canonical JSON of the code identity (the digest written on the FROZEN line)."""
    identity = code_identity() if identity is None else identity
    return hashlib.sha256(json.dumps(identity, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def _write_exclusive(path: Path, document: dict) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644)
    with os.fdopen(fd, "w") as handle:
        handle.write(json.dumps(document, indent=1, sort_keys=True) + "\n")


def _check_protocol(stage: str, prereg: Path, marker: str, expected_sha256: str) -> str:
    actual = body_sha256(prereg, marker)
    if actual != expected_sha256:
        raise ClaimError(f"{stage}: protocol body sha256 {actual} differs from the expected {expected_sha256}")
    line = frozen_line(prereg)
    if line is None or line["body"] != actual:
        raise ClaimError(f"{stage}: the protocol is not frozen with this hash (no matching FROZEN line)")
    if line["code"] is None:
        raise ClaimError(f"{stage}: the FROZEN line carries no code digest — the code is not pinned to the freeze")
    if line["code"] != code_digest():
        raise ClaimError(f"{stage}: the code differs from the code frozen with the protocol (digest {code_digest()} ≠ {line['code']})")
    return actual


def _check_prerequisites(out: Path, stage: str) -> dict[str, str]:
    prerequisites = {}
    for required in PREREQUISITES[stage]:
        consumed = out / f"{required}-consumed.json"
        record = json.loads(consumed.read_text()) if consumed.exists() else {}
        if record.get("status") != "complete":
            raise ClaimError(f"{stage}: stage {required} is not complete")
        recorded = record.get("outputs") or {}
        for name in PREREQUISITE_OUTPUTS[required]:
            if name not in recorded:
                raise ClaimError(f"{stage}: stage {required} recorded no hash for {name}")
            digest = sha256_file(out / name)
            if digest != recorded[name]:
                raise ClaimError(f"{stage}: {name} changed after stage {required} finished")
            prerequisites[name] = digest
    return prerequisites


def claim(out: Path, stage: str, prereg: Path, marker: str, expected_sha256: str) -> dict:
    if stage not in STAGES:
        raise ClaimError(f"unknown stage {stage}")
    pending = pr.unresolved_decisions()
    if pending:
        raise ClaimError(f"{stage}: owner decisions not set: {'; '.join(pending)}")
    out = Path(out)
    out.mkdir(parents=True, exist_ok=True)
    target = out / f"{stage}-claim.json"
    if target.exists():
        raise ClaimError(f"{stage}: claim already exists — each stage reads its data once")
    if (out / f"{stage}-consumed.json").exists():
        raise ClaimError(f"{stage}: a consumption record exists without its claim — refused")
    actual = _check_protocol(stage, prereg, marker, expected_sha256)
    identity = code_identity()
    if stage != "E0a":
        first = out / "E0a-claim.json"
        if not first.exists() or json.loads(first.read_text()).get("code") != identity:
            raise ClaimError(f"{stage}: the code differs from the code of the E0a claim")
    prerequisites = _check_prerequisites(out, stage)
    document = {"stage": stage, "claimed_at": now(), "protocol_path": str(Path(prereg).resolve()), "protocol_body_sha256": actual,
                "code": identity, "code_sha256": code_digest(identity), "prerequisites": prerequisites,
                "decisions": {"multiplicity": pr.MULTIPLICITY_RULE, "r1_both": pr.R1_BOTH_RULE, "max_guard_resumptions": pr.MAX_GUARD_RESUMPTIONS, "funding": pr.FUNDING_RULE}}
    _write_exclusive(target, document)
    return document


def consume(out: Path, stage: str, resume: bool = False, marker: str = pr.FREEZE_MARKER) -> dict:
    """First call of a run, before any data is read."""
    out = Path(out)
    claimed = out / f"{stage}-claim.json"
    if not claimed.exists():
        raise ClaimError(f"{stage}: no claim — run `claim` first")
    document = json.loads(claimed.read_text())
    _check_protocol(stage, Path(document["protocol_path"]), marker, document["protocol_body_sha256"])
    if document["code"] != code_identity():
        raise ClaimError(f"{stage}: the code changed since the claim")
    for name, digest in document["prerequisites"].items():
        if sha256_file(out / name) != digest:
            raise ClaimError(f"{stage}: prerequisite {name} changed since the claim")
    target = out / f"{stage}-consumed.json"
    if target.exists():
        previous = json.loads(target.read_text())
        allowed = int(pr.MAX_GUARD_RESUMPTIONS or 0)
        if resume and stage in RESUMABLE and previous.get("status") == "aborted_by_guard" and len(previous.get("resumptions", [])) < allowed:
            previous.setdefault("resumptions", []).append({"at": now(), "after": previous.get("reason")})
            previous.update({"status": "running", "reason": None})
            target.write_text(json.dumps(previous, indent=1, sort_keys=True) + "\n")
            return previous
        raise ClaimError(f"{stage}: already consumed ({previous.get('status')}, {len(previous.get('resumptions', []))} resumption(s), {allowed} allowed) — a second read is refused")
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
