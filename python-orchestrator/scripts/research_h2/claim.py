"""Single-use claims (model: research_lev/claim.py, branch claude/research-leverage-gt2).

A stage (`events`, then `outcomes`) runs only after `claim` wrote `<stage>-claim.json`. A claim is refused unless:

- every protocol decision is set (`protocol.unresolved_decisions()` is empty) and an owner go is given;
- the protocol body sha256 equals the expected hash AND the hash on the protocol's FROZEN line;
- the FROZEN line carries `code sha256 <digest>` and the current code identity (every `research_h2` module and the
  reused `research_swing2/okx.py`, `research_swing2/okx_universe.py`) has that digest: the code is pinned to the freeze;
- the inputs have the hashes recorded in the protocol (manifests, every H2 zip against its manifest, the digest of
  the swing v2 npz files, the X-Perp list);
- for `outcomes`: the code equals the one of the `events` claim, the `events` stage is complete and its event file
  still has the sha256 recorded when it finished.

`consume` is the first call of a run, before any data is read; it creates `<stage>-consumed.json` exclusively, so a
second run is refused. Only an `events` run that the memory/disk guard stopped before it wrote any output may be
resumed, at most `MAX_GUARD_RESUMPTIONS["events"]` times; `outcomes` has no resumption.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
from datetime import datetime, timezone
from pathlib import Path

from . import protocol as pr

STAGES = ("events", "outcomes")
PREREQUISITES = {"events": (), "outcomes": ("events",)}
STAGE_OUTPUTS = {"events": ("h2-events.json",), "outcomes": ("h2-outcomes.json", "h2-gate1-result.json")}


class ClaimError(SystemExit):
    pass


def now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def body_sha256(path: Path, marker: str = pr.FREEZE_MARKER) -> str:
    """sha256 of the file up to and including the line `marker` and the following blank line."""
    text = Path(path).read_text()
    start = text.index(marker)
    end = text.index("\n", start) + 1
    if text[end:end + 1] != "\n":
        raise ClaimError(f"{path}: the freeze marker must be followed by a blank line")
    return hashlib.sha256((text[:end] + "\n").encode()).hexdigest()


def frozen_line(path: Path) -> dict | None:
    """The protocol's `FROZEN[_NOT_EXECUTED] <time> body sha256 <hash> code sha256 <digest>` line (the programme's
    two forms), None while unfrozen."""
    for line in Path(path).read_text().splitlines():
        words = line.split()
        if words and words[0] in ("FROZEN", "FROZEN_NOT_EXECUTED") and "body sha256 " in line:
            out = {"time": words[1] if len(words) > 1 else None, "body": line.split("body sha256 ", 1)[1].split()[0], "code": None}
            if "code sha256 " in line:
                out["code"] = line.split("code sha256 ", 1)[1].split()[0]
            return out
    return None


def code_identity() -> dict[str, str]:
    here = Path(__file__).resolve().parent
    files = sorted(here.glob("*.py")) + [here.parent / "research_swing2" / "okx.py", here.parent / "research_swing2" / "okx_universe.py"]
    return {f"{path.parent.name}/{path.name}": sha256_file(path) for path in files}


def code_digest(identity: dict[str, str] | None = None) -> str:
    identity = code_identity() if identity is None else identity
    return hashlib.sha256(json.dumps(identity, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def swing_npz_identity(tape: Path | None = None) -> dict[str, str]:
    tape = pr.SWING_TAPE if tape is None else Path(tape)
    out = {}
    for path in sorted((tape / "klines").glob("*/*.npz")):
        period = re.search(r"-1m-(.+)\.npz$", path.name).group(1)
        if period <= pr.LAST_PERIOD:
            out[f"{path.parent.name}/{path.name}"] = sha256_file(path)
    return out


def verify_inputs() -> dict:
    """File hashes only (no candle is parsed). Raises ClaimError on any difference."""
    found = {pr.H2_MANIFEST: sha256_file(pr.H2_TAPE / pr.H2_MANIFEST), pr.SWING_MANIFEST: sha256_file(pr.SWING_TAPE / pr.SWING_MANIFEST),
             pr.SWING_VOLUME: sha256_file(pr.SWING_TAPE / pr.SWING_VOLUME), "eea-futures.json": sha256_file(pr.XPERP_SOURCE)}
    for name, digest in found.items():
        if digest != pr.INPUT_SHA256[name]:
            raise ClaimError(f"input {name}: sha256 {digest} differs from the frozen {pr.INPUT_SHA256[name]}")
    manifest = json.loads((pr.H2_TAPE / pr.H2_MANIFEST).read_text())
    expected = {f"{f['instId']}/{f['filename']}": f["zip_sha256"] for f in manifest["files"]}
    present = {f"{p.parent.name}/{p.name}": p for p in (pr.H2_TAPE / "zips").glob("*/*.zip")}
    if set(present) != set(expected):
        raise ClaimError(f"H2 zips differ from the manifest: missing {sorted(set(expected) - set(present))[:5]}, extra {sorted(set(present) - set(expected))[:5]}")
    for key, path in present.items():
        if sha256_file(path) != expected[key]:
            raise ClaimError(f"H2 zip {key} differs from its manifest sha256")
    swing = swing_npz_identity()
    digest = hashlib.sha256(json.dumps(swing, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    if digest != pr.SWING_NPZ_DIGEST or len(swing) != pr.SWING_NPZ_FILES:
        raise ClaimError(f"swing v2 npz digest {digest} ({len(swing)} files) differs from the frozen {pr.SWING_NPZ_DIGEST} ({pr.SWING_NPZ_FILES})")
    return {"files": found, "h2_zips": len(present), "swing_npz_digest": digest, "swing_npz_files": len(swing)}


def _write_exclusive(path: Path, document: dict) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644)
    with os.fdopen(fd, "w") as handle:
        handle.write(json.dumps(document, indent=1, sort_keys=True) + "\n")


def _check_protocol(stage: str, prereg: Path, expected_sha256: str) -> str:
    actual = body_sha256(prereg)
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
        for name in STAGE_OUTPUTS[required]:
            if name not in recorded:
                raise ClaimError(f"{stage}: stage {required} recorded no hash for {name}")
            digest = sha256_file(out / name)
            if digest != recorded[name]:
                raise ClaimError(f"{stage}: {name} changed after stage {required} finished")
            prerequisites[name] = digest
    return prerequisites


def claim(out: Path, stage: str, prereg: Path, expected_sha256: str, owner_go: str, inputs: dict | None = None) -> dict:
    if stage not in STAGES:
        raise ClaimError(f"unknown stage {stage}")
    pending = pr.unresolved_decisions()
    if pending:
        raise ClaimError(f"{stage}: decisions not set: {'; '.join(pending)}")
    if not owner_go or not owner_go.strip():
        raise ClaimError(f"{stage}: the single Gate 1 run needs the owner's explicit go (--owner-go)")
    out = Path(out)
    out.mkdir(parents=True, exist_ok=True)
    target = out / f"{stage}-claim.json"
    if target.exists():
        raise ClaimError(f"{stage}: claim already exists — each stage reads its data once")
    if (out / f"{stage}-consumed.json").exists():
        raise ClaimError(f"{stage}: a consumption record exists without its claim — refused")
    actual = _check_protocol(stage, prereg, expected_sha256)
    identity = code_identity()
    if stage != "events":
        first = out / "events-claim.json"
        if not first.exists() or json.loads(first.read_text()).get("code") != identity:
            raise ClaimError(f"{stage}: the code differs from the code of the events claim")
    prerequisites = _check_prerequisites(out, stage)
    inputs = verify_inputs() if inputs is None else inputs
    document = {"stage": stage, "claimed_at": now(), "protocol_path": str(Path(prereg).resolve()), "protocol_body_sha256": actual,
                "code": identity, "code_sha256": code_digest(identity), "prerequisites": prerequisites, "inputs": inputs,
                "owner_go": owner_go.strip(), "decisions": {"g4_rule": pr.G4_RULE, "c2_caliper": pr.C2_CALIPER_NAME}}
    _write_exclusive(target, document)
    return document


def consume(out: Path, stage: str, resume: bool = False) -> dict:
    """First call of a run, before any data is read."""
    out = Path(out)
    claimed = out / f"{stage}-claim.json"
    if not claimed.exists():
        raise ClaimError(f"{stage}: no claim — run `claim` first")
    document = json.loads(claimed.read_text())
    _check_protocol(stage, Path(document["protocol_path"]), document["protocol_body_sha256"])
    if document["code"] != code_identity():
        raise ClaimError(f"{stage}: the code changed since the claim")
    for name, digest in document["prerequisites"].items():
        if sha256_file(out / name) != digest:
            raise ClaimError(f"{stage}: prerequisite {name} changed since the claim")
    target = out / f"{stage}-consumed.json"
    if target.exists():
        previous = json.loads(target.read_text())
        allowed = int(pr.MAX_GUARD_RESUMPTIONS.get(stage, 0))
        no_output = not any((out / name).exists() for name in STAGE_OUTPUTS[stage])
        if (resume and previous.get("status") == "aborted_by_guard" and no_output
                and len(previous.get("resumptions", [])) < allowed):
            previous.setdefault("resumptions", []).append({"at": now(), "after": previous.get("reason")})
            previous.update({"status": "running", "reason": None})
            target.write_text(json.dumps(previous, indent=1, sort_keys=True) + "\n")
            return previous
        raise ClaimError(f"{stage}: already consumed ({previous.get('status')}, {len(previous.get('resumptions', []))} resumption(s), "
                         f"{allowed} allowed) — a second read is refused")
    record = {"stage": stage, "started_at": now(), "status": "running", "claim_sha256": sha256_file(claimed)}
    _write_exclusive(target, record)
    return record


def _update(out: Path, stage: str, **fields) -> dict:
    target = Path(out) / f"{stage}-consumed.json"
    record = json.loads(target.read_text())
    record.update(fields)
    target.write_text(json.dumps(record, indent=1, sort_keys=True) + "\n")
    return record


def finish(out: Path, stage: str) -> dict:
    return _update(out, stage, status="complete", finished_at=now(),
                   outputs={name: sha256_file(Path(out) / name) for name in STAGE_OUTPUTS[stage]})


def abort(out: Path, stage: str, reason: str) -> dict:
    return _update(out, stage, status="aborted_by_guard", reason=reason, aborted_at=now())


def events_sha256(out: Path) -> str:
    """The event-file hash recorded when the events stage finished (the outcome module's only accepted hash)."""
    record = json.loads((Path(out) / "events-consumed.json").read_text())
    if record.get("status") != "complete":
        raise ClaimError("outcomes: the events stage is not complete")
    return record["outputs"]["h2-events.json"]
