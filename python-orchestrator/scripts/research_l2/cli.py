"""Command line of the L2 capture pilot (`python -m scripts.research_l2.cli …`, from `python-orchestrator/`).

    spec-hash    --spec PATH                                  sha256 of the frozen spec and agreement with its sidecar
    plan         --out DIR --authorization-ref TEXT --separation-spec G5 --separation-ref P-L2-1… [--instruments-file F] [limits]
                                                              every guard, no network, nothing written; exit code = capture's
    capture      --out DIR --spec PATH --expect-spec-sha256 H --expect-code-digest H --authorization-ref TEXT
                 --separation-spec G5 --separation-ref P-L2-1… [limits]
                                                              THE pilot run (network: REST instruments once, then public WS)
    verify       --run DIR                                    manifest, segment sizes and sha256
    quality      --run DIR [--out-json F]                     header-only quality summary and frozen verdict (no price)
    replay-check --run DIR --separation-spec G5 --unsealed-ref P-L2-1… [--out-json F]
                                                              full book reconstruction, counts only (content: the separation recorded)

Spec l2-pilot-spec/2 (P-L2-1): the run is refused before 2026-11-02T00:00Z, at or after the hard end 2026-11-03T00:00Z, and unless the G5 Gate 2
spec given by --separation-spec carries the pilot_separation block the pilot spec pins (sha256); a later start shortens the run (stop
reason hard_end), never shifts its end. The v1 spec is kept unchanged and refused for a run.

Limits (`--max-duration-s`, `--max-bytes`, `--min-free-bytes`) can only be made stricter than the frozen spec. Exit codes:
0 run stopped on a limit, a signal or the STOP file; 2 refused by the date / floor guard; 3 lock held; 4 invalid (spec hash,
code digest, protected or non-empty directory, selection); 5 free space below the floor at start; 6 stopped on a fatal error
or on the reconnect cap. Nothing here places, cancels or signs an order; nothing schedules a run.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import sys
from pathlib import Path

from scripts.research_lp import tape

from . import pilot as pl
from . import replay as rp

NORMAL_STOPS = {"max_duration", "hard_end", "max_bytes", "min_free_space", "stop_file", "SIGINT", "SIGTERM"}


def _limits(args, spec: pl.LoadedSpec) -> pl.PilotLimits:
    return pl.PilotLimits.from_spec(spec, max_duration_s=args.max_duration_s, max_bytes_written=args.max_bytes, min_free_bytes=args.min_free_bytes)


def _add_limits(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--max-duration-s", type=float, default=None, help="lower than the spec cap only (86400)")
    parser.add_argument("--max-bytes", type=int, default=None, help="lower than the spec cap only (5 GiB)")
    parser.add_argument("--min-free-bytes", type=int, default=None, help="higher than the spec floor only (10 GiB)")


def _print(document: dict, out_json: str | None = None) -> None:
    text = json.dumps(document, indent=1, sort_keys=True, default=str)
    if out_json:
        hit = pl.protected_component(Path(out_json), json.loads(pl.DEFAULT_SPEC.read_text())["protected_path_tokens"])
        if hit is not None:
            raise pl.PilotRefused("protected_output_path", f"{out_json} (component {hit!r})")
        tape.atomic_write_text(Path(out_json), text + "\n")
    print(text)


def command_spec_hash(args) -> int:
    path = Path(args.spec)
    digest = pl.sha256_bytes(path.read_bytes())
    side = pl.sidecar_path(path)
    frozen = side.read_text().split()[0] if side.exists() and side.read_text().split() else None
    _print({"spec": str(path), "sha256": digest, "sidecar": frozen, "agrees": frozen == digest, "code_digest": pl.code_digest()})
    return 0 if frozen == digest else pl.EXIT_INVALID


def command_plan(args) -> int:
    verdicts: dict[str, str] = {}
    code = pl.EXIT_OK
    try:
        spec = pl.load_spec(Path(args.spec), args.expect_spec_sha256)
        verdicts["spec"] = f"ok {spec.sha256}"
        limits = _limits(args, spec)
        cfg = pl.PilotConfig(out_dir=Path(args.out), spec=spec, authorization_ref=args.authorization_ref or "", limits=limits, argv=list(sys.argv[1:]),
                             separation_spec=Path(args.separation_spec) if args.separation_spec else None, separation_ref=args.separation_ref or "")
        separation = pl.preflight(cfg, pl.SystemClock(), pl.free_bytes)
        verdicts["preflight"] = "ok"
        verdicts["separation"] = f"ok {separation['block_sha256']} in {separation['g5_gate2_spec']} ({separation['g5_gate2_spec_version']})"
        if args.instruments_file:
            rows = pl.parse_instruments(Path(args.instruments_file).read_bytes())
            chosen = pl.select_instruments(rows, spec.doc["selection"])
            verdicts["selection"] = ",".join(str(r["instId"]) for r in chosen)
        else:
            verdicts["selection"] = "not checked (no --instruments-file; capture fetches the snapshot at launch)"
    except pl.PilotRefused as error:
        verdicts["refused"] = f"{error.reason}: {error.detail}"
        code = error.exit_code
    _print({"plan": verdicts, "code_digest": pl.code_digest(), "network": "none", "written": "nothing"})
    return code


def command_capture(args) -> int:
    try:
        spec = pl.load_spec(Path(args.spec), args.expect_spec_sha256)
        digest = pl.code_digest()
        if digest != args.expect_code_digest:
            raise pl.PilotRefused("code_digest_mismatch", f"running {digest} != expected {args.expect_code_digest}")
        cfg = pl.PilotConfig(out_dir=Path(args.out), spec=spec, authorization_ref=args.authorization_ref, limits=_limits(args, spec), argv=list(sys.argv[1:]),
                             echo=not args.quiet, separation_spec=Path(args.separation_spec), separation_ref=args.separation_ref)
        pl.preflight(cfg, pl.SystemClock(), pl.free_bytes)  # before the instruments request: a refused run makes no network call
        venue = spec.doc["venue"]
        raw = pl.fetch_instruments_raw(venue["rest"], venue["instruments_path"])
        selected = pl.select_instruments(pl.parse_instruments(raw), spec.doc["selection"])
        capture = pl.PilotCapture(cfg, selected, raw)
    except pl.PilotRefused as error:
        pl.log_refusal(error)
        return error.exit_code
    try:
        asyncio.run(capture.run())
    except KeyboardInterrupt:  # only when the loop could not install its handler
        capture.request_stop("SIGINT", "KeyboardInterrupt")
    finally:
        capture.close()
    return pl.EXIT_OK if capture.stop_reason in NORMAL_STOPS else 6


def command_verify(args) -> int:
    result = rp.verify(Path(args.run))
    _print(result)
    return 0 if result["ok"] else pl.EXIT_INVALID


def command_quality(args) -> int:
    _print(rp.quality_summary(Path(args.run)), args.out_json)
    return 0


def command_replay_check(args) -> int:
    if not args.unsealed_ref or not args.unsealed_ref.strip():
        raise SystemExit("replay-check rebuilds the books (content): --unsealed-ref TEXT (the owner's unsealing decision) is required")
    # P-L2-1: the content is opened only once the separation is recorded (the block found in the G5 Gate 2 spec, the decision named).
    separation = pl.verify_separation(pl.load_spec(pl.DEFAULT_SPEC, None), args.separation_spec, args.unsealed_ref)
    document = rp.replay_check(Path(args.run))
    document["unsealed_ref"] = args.unsealed_ref
    document["separation"] = separation
    _print(document, args.out_json)
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="research_l2", description="OKX Europe X-Perps L2 capture pilot (public data, no order)")
    sub = parser.add_subparsers(dest="command", required=True)
    sh = sub.add_parser("spec-hash", allow_abbrev=False)
    sh.add_argument("--spec", default=str(pl.DEFAULT_SPEC))
    plan = sub.add_parser("plan", allow_abbrev=False)
    plan.add_argument("--out", required=True)
    plan.add_argument("--spec", default=str(pl.DEFAULT_SPEC))
    plan.add_argument("--expect-spec-sha256", default=None)
    plan.add_argument("--authorization-ref", default=None)
    plan.add_argument("--instruments-file", default=None, help="a saved instruments response, for the selection check only")
    plan.add_argument("--separation-spec", default=None, help="the G5 Gate 2 spec carrying the pilot_separation block (P-L2-1)")
    plan.add_argument("--separation-ref", default=None, help="the recorded decision, starting with P-L2-1")
    _add_limits(plan)
    cap = sub.add_parser("capture", allow_abbrev=False)
    cap.add_argument("--out", required=True)
    cap.add_argument("--spec", required=True)
    cap.add_argument("--expect-spec-sha256", required=True)
    cap.add_argument("--expect-code-digest", required=True)
    cap.add_argument("--authorization-ref", required=True)
    cap.add_argument("--separation-spec", required=True, help="the G5 Gate 2 spec carrying the pilot_separation block (P-L2-1)")
    cap.add_argument("--separation-ref", required=True, help="the recorded decision, starting with P-L2-1")
    cap.add_argument("--quiet", action="store_true")
    _add_limits(cap)
    ver = sub.add_parser("verify", allow_abbrev=False)
    ver.add_argument("--run", required=True)
    qual = sub.add_parser("quality", allow_abbrev=False)
    qual.add_argument("--run", required=True)
    qual.add_argument("--out-json", default=None)
    rc = sub.add_parser("replay-check", allow_abbrev=False)
    rc.add_argument("--run", required=True)
    rc.add_argument("--unsealed-ref", default=None, help="the recorded decision that opens the content, starting with P-L2-1")
    rc.add_argument("--separation-spec", default=None, help="the G5 Gate 2 spec carrying the pilot_separation block (P-L2-1)")
    rc.add_argument("--out-json", default=None)
    return parser


COMMANDS = {"spec-hash": command_spec_hash, "plan": command_plan, "capture": command_capture, "verify": command_verify, "quality": command_quality,
            "replay-check": command_replay_check}


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        return COMMANDS[args.command](args)
    except pl.PilotRefused as error:
        pl.log_refusal(error)
        return error.exit_code


if __name__ == "__main__":
    sys.exit(main())
