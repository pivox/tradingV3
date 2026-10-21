"""Command line of the `lp` programme (OKX FUTURES X-Perps liquidity-provision research).

    python -m scripts.research_lp.cli count    --out DIR [--host global|eea] [--inst-ids A,B | --instruments-file F | --family xperp] [--duration-s N]
    python -m scripts.research_lp.cli capture  --out DIR … [--not-before 2026-11-02T00:00:00Z]
    python -m scripts.research_lp.cli simulate --tape DIR --dates 2026-11-03..2026-11-09 [--inst A,B] --rule queue --out orders.npz --summary summary.json
    python -m scripts.research_lp.cli events   --tape DIR --dates … --kind liquidation,excursion,clock --out events.npz --summary events.json
    python -m scripts.research_lp.cli verify   --tape DIR
    python -m scripts.research_lp.cli g5-fetch --out DIR --instruments-file F --inst-ids-file IDS --spec SPEC --phase pre_freeze --dry-run --plan-out plan.json
    python -m scripts.research_lp.cli g5-fetch --out DIR --instruments-file F --inst-ids-file IDS --spec SPEC --phase pre_freeze --plan plan.json \
        --max-requests N --decision-ref TEXT                                  (sealed funding history, before the freeze)
    python -m scripts.research_lp.cli g5-fetch … --phase post_freeze --funding-receipt DIR/funding-sealed-receipt.json …   (candles, mark, index)
    python -m scripts.research_lp.cli g5-eval  --tape DIR --spec SPEC --expect-spec-sha256 H --out-json R.json --out-md R.md [--expect-funding-seal-sha256 H]

`capture`, `count` and `g5-fetch` are the only commands that open a network connection (the public
WebSocket, and the public instruments list unless `--inst-ids` / `--instruments-file` is given;
`g5-fetch` the public REST history endpoints, never in `--dry-run`, and never without `--decision-ref`,
the G5 spec and its phase (`--spec`, `--phase`; `--funding-receipt` after the freeze), an instrument list
equal to the spec's universe (hashed), `--max-requests` and the dry-run `--plan` of the same configuration;
the global host also needs `--host-reason`; the funding history only in sealed mode, LP-G5 P-G5-3).
`g5-fetch` persists no row whose market timestamp is at or after `g5_fetch.END_EXCLUSIVE`
(2026-10-14T00:00:00Z, a constant); `g5-eval` reads the schema `g5-spec/2` only and refuses any window
touching the quarantine [2026-10-14, 2026-11-02), any unknown key or value of the spec and any capability
it requires but the code does not implement; it reads no market value unless the spec is FROZEN with a
complete `freeze_fill`, its sha256 equals `--expect-spec-sha256` and `code_digest()` equals the frozen
`freeze_fill.code_digest_sha256` (see `g5_eval`). `code_digest()` is the sha256 of `code_identity()` (one
sha256 per module of this package).

Quarantine guard (rule 13 of the theory report; `LP-PROGRAMME-GATES.md`): `--not-before` is
accepted only at or after `xperp_capture.QUARANTINE_FLOOR` (2026-11-02T00:00:00Z) in every
command — a lower value exits with code 2 and the reason `not_before_below_floor`, nothing
written. `simulate` and `events` refuse tape days before the not-before date unless
`--allow-before-reason TEXT` is given (synthetic tapes, explicitly released data); the reason,
the not-before date and the early dates read are copied into the summary's `meta` and into the
npz `meta`, and a WARN line is printed, so a read under a lifted guard is distinguishable after
the fact. They also refuse a counting tape (it holds no value). Nothing here places, cancels or
signs anything.

Contract specifications: `simulate` and `events --kind liquidation` need `ctVal` for every
instrument (notional = contracts × ctVal × price). It comes from `instruments-futures.json` (the
capture writes it) or `--instruments-file`, or from `--ct-val`; when neither has it the command
refuses (`SystemExit`) rather than assume 1.0. The summary records `ct_val_source`
(`instruments_file` | `cli`) and `tick_source` (`instruments_file` | `cli` | `inferred`).
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import time
from pathlib import Path

import numpy as np

from . import events as ev
from . import g5_eval, g5_fetch, lp_sim, tape, xperp_capture as xc


def code_identity() -> dict[str, str]:
    package = Path(__file__).resolve().parent
    return {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in sorted(package.glob("*.py"))}


def code_digest() -> str:
    """One sha256 for the whole package: of the canonical JSON of `code_identity()` (sorted keys, no spaces)."""
    return hashlib.sha256(json.dumps(code_identity(), sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def _json_default(value):
    if isinstance(value, np.integer):
        return int(value)
    if isinstance(value, np.floating):
        return None if np.isnan(value) else float(value)
    if isinstance(value, np.ndarray):
        return value.tolist()
    raise TypeError(f"not serialisable: {type(value)}")


def _write_json(path: Path, document: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(document, indent=1, sort_keys=True, default=_json_default) + "\n")


def _warn(message: str) -> None:
    print(f"{tape.iso_now()} level=WARN {message}", file=sys.stderr, flush=True)


# ----------------------------------------------------------------------------- capture / count


def _capture_instruments(args: argparse.Namespace) -> tuple[list[str], list[dict] | None]:
    if args.inst_ids:
        ids = [s for s in args.inst_ids.split(",") if s]
        return ids, None
    if args.instruments_file:
        rows = xc.select_instruments(xc.load_instruments_file(Path(args.instruments_file)), args.family, only_live=not args.include_not_live)
    else:
        rows = xc.select_instruments(xc.fetch_instruments(args.rest_host or args.host), args.family, only_live=not args.include_not_live)
    if not rows:
        raise SystemExit(f"no instrument selected (family {args.family})")
    return [row["instId"] for row in rows], rows


def command_capture(args: argparse.Namespace, counting_only: bool) -> int:
    counting = counting_only or bool(args.counting_only)
    # The floor is checked by `Capture` too; checking it here spares the instruments REST call of a refused command.
    if tape.parse_iso_utc(args.not_before) < xc.quarantine_floor_us():
        print(f"{tape.iso_now()} level=ERROR event=refused reason=not_before_below_floor not_before={args.not_before} floor={xc.QUARANTINE_FLOOR}", file=sys.stderr, flush=True)
        return 2
    inst_ids, rows = _capture_instruments(args)
    channels = tuple(c for c in args.channels.split(",") if c)
    cfg = xc.CaptureConfig(out_dir=Path(args.out), inst_ids=tuple(inst_ids), host=args.host, channels=channels, counting_only=counting,
                           not_before=args.not_before, checkpoint_every_s=args.checkpoint_every, duration_s=args.duration_s if args.duration_s > 0 else None,
                           max_connection_failures=args.max_connection_failures, instruments_rows=rows, liq_selected_only=bool(args.liq_selected_only),
                           echo=not args.quiet, argv=list(sys.argv[1:]))
    return xc.run_capture(cfg)


# ----------------------------------------------------------------------------- shared loaders


def _guard_tape(args: argparse.Namespace, dates: list[str]) -> tuple[dict | None, dict]:
    """The not-before / quarantine guard of the read commands. Returns the manifest and the guard
    record copied into every summary (`not_before`, `allow_before`, `allow_before_reason`, `dates_before_not_before`)."""
    manifest = tape.read_manifest(Path(args.tape))
    if manifest is not None and manifest.get("mode") == "counting":
        raise SystemExit(f"{args.tape}: a counting tape holds no value to simulate")
    not_before_us = tape.parse_iso_utc(args.not_before)
    if not_before_us < xc.quarantine_floor_us():
        raise SystemExit(f"not_before_below_floor: --not-before {args.not_before} is below the quarantine floor {xc.QUARANTINE_FLOOR}")
    not_before_day = tape.day_of_us(not_before_us)
    early = [d for d in dates if tape.day_of_date(d) < not_before_day]
    reason = (args.allow_before_reason or "").strip()
    if early and not reason:
        raise SystemExit(f"dates before {args.not_before} refused ({early[0]} …); pass --allow-before-reason TEXT only for synthetic or explicitly released data")
    if early:
        _warn(f"event=guard_lifted not_before={args.not_before} dates_before={early[0]}..{early[-1]} count={len(early)} reason={json.dumps(reason)}")
    record = {"not_before": args.not_before, "quarantine_floor": xc.QUARANTINE_FLOOR, "allow_before": bool(reason), "allow_before_reason": reason or None,
              "dates_before_not_before": early}
    return manifest, record


def _instrument_rows(args: argparse.Namespace) -> tuple[dict[str, dict], str | None]:
    """Contract rows by instId from `--instruments-file` or `<tape>/instruments-futures.json`."""
    path = Path(args.instruments_file) if args.instruments_file else Path(args.tape) / "instruments-futures.json"
    rows: dict[str, dict] = {}
    if not path.exists():
        return rows, None
    document = json.loads(path.read_text())
    items = document.get("instruments", document.get("data", [])) if isinstance(document, dict) else document
    for row in items:
        if isinstance(row, dict) and row.get("instId"):
            rows[str(row["instId"])] = row
    return rows, str(path)


def _ct_val(args: argparse.Namespace, rows: dict[str, dict], inst: str, rows_path: str | None) -> tuple[float, str]:
    """`(ctVal, source)`; refuses when nothing states it — a silent 1.0 would size every X-Perp order to 0 or scale every notional."""
    if args.ct_val:
        return float(args.ct_val), "cli"
    row = rows.get(inst) or {}
    if row.get("ctVal") not in (None, ""):
        return float(row["ctVal"]), "instruments_file"
    where = rows_path or f"{Path(args.tape) / 'instruments-futures.json'} (absent)"
    raise SystemExit(f"ct_val_missing: no ctVal for {inst} in {where} and no --ct-val; the contract value is mandatory (notional = contracts × ctVal × price)")


def _specs(args: argparse.Namespace, instruments: list[str], books: dict[str, lp_sim.Book]) -> tuple[dict[str, lp_sim.InstrumentSpec], dict[str, dict]]:
    rows, rows_path = _instrument_rows(args)
    specs, sources = {}, {}
    for inst in instruments:
        row = rows.get(inst) or {}
        ct_val, ct_source = _ct_val(args, rows, inst, rows_path)
        if args.tick:
            tick, tick_source = float(args.tick), "cli"
        elif row.get("tickSz") not in (None, ""):
            tick, tick_source = float(row["tickSz"]), "instruments_file"
        else:
            tick, tick_source = float(books[inst].inferred_tick()), "inferred"
        lot = float(row.get("lotSz") or 1.0)
        min_size = float(row.get("minSz") or row.get("lotSz") or 1.0)
        specs[inst] = lp_sim.InstrumentSpec(inst, tick, ct_val, lot, min_size)
        sources[inst] = {"ct_val_source": ct_source, "tick_source": tick_source, "instruments_file": rows_path}
    return specs, sources


_INSTRUMENT_LAYOUTS = ("books5", "trades", "funding", "mark", "liquidations")


def _instruments(args: argparse.Namespace, layouts: tuple[str, ...] = _INSTRUMENT_LAYOUTS) -> list[str]:
    if args.inst:
        return [s for s in args.inst.split(",") if s]
    for layout in layouts:
        found = tape.instruments_in(Path(args.tape), layout)
        if found:
            return found
    raise SystemExit(f"no instrument under {args.tape}/{{{','.join(layouts)}}}")


def _load_events(path: Path, inst: str) -> np.ndarray:
    with np.load(path, allow_pickle=False) as data:
        parts = [data[key] for key in (f"events_{inst}", "events__all") if key in data.files]
    if not parts:
        return ev.empty_events()
    rows = np.concatenate(parts).astype(ev.EVENT_DTYPE)
    return rows[np.argsort(rows["ts_us"], kind="stable")]


def _hours(spec: str) -> tuple[int, ...]:
    hours = tuple(int(h) for h in spec.split(",") if h.strip() != "")
    if any(h < 0 or h > 23 for h in hours):
        raise SystemExit(f"--funding-hours out of range: {spec}")
    return hours


# ----------------------------------------------------------------------------- simulate


def command_simulate(args: argparse.Namespace) -> int:
    started = time.time()
    dates = tape.parse_dates(args.dates)
    manifest, guard = _guard_tape(args, dates)
    instruments = _instruments(args, ("books5",))
    params = lp_sim.SimParams(rule=args.rule, latency_ms=args.latency_ms, ttl_s=args.ttl_s, stale_s=args.stale_s, post_only=not args.no_post_only, order_notional=args.order_notional,
                              maker_bp=args.maker_bp, taker_bp=args.taker_bp, exit_max_s=args.exit_max_s, funding_interval_h=args.funding_interval_h, funding_anchor_h=args.funding_anchor_h,
                              funding_rate=args.funding_rate_bp / 1e4, capital=args.capital, max_live=args.max_live, leverage=args.leverage, grid_s=args.grid_s, placement=args.placement)
    orders: dict[str, np.ndarray] = {}
    summaries: dict[str, dict] = {}
    receipts: dict[str, dict] = {}
    books: dict[str, lp_sim.Book] = {}
    for inst in instruments:
        book_rows, book_receipts = tape.load_days(Path(args.tape), "books5", inst, dates, allow_pending=args.allow_partial)
        if book_rows.size == 0:
            receipts[inst] = {"books5": book_receipts, "skipped": "no books5 rows"}
            continue
        books[inst] = lp_sim.Book.from_rows(book_rows)
        receipts[inst] = {"books5": book_receipts}
    specs, sources = _specs(args, list(books), books)
    for inst, book in books.items():
        trade_rows, trade_receipts = tape.load_days(Path(args.tape), "trades", inst, dates, allow_pending=args.allow_partial)
        prints = lp_sim.Prints.from_rows(trade_rows)
        funding_rows, funding_receipts = tape.load_days(Path(args.tape), "funding", inst, dates, allow_pending=args.allow_partial)
        if args.funding_source == "tape" and funding_rows.size:
            funding = lp_sim.FundingSchedule.from_tape(funding_rows)
        else:
            funding = lp_sim.FundingSchedule.constant(int(book.ts[0]), int(book.ts[-1]), params.funding_interval_h, params.funding_rate, params.funding_anchor_h)
        spec = specs[inst]
        if args.events:
            evs = _load_events(Path(args.events), inst)
            intents = lp_sim.event_intents(evs["ts_us"], evs["side"], book, spec, params, tags=evs["kind"].astype(np.int32), arm_ts_us=evs["arm_ts_us"], end_ts_us=evs["end_ts_us"])
        else:
            intents = lp_sim.grid_intents(book, spec, params)
        rows = lp_sim.simulate(book, prints, funding, spec, intents, params)
        both = lp_sim.summarize_both(rows, spec, params)
        orders[inst] = rows
        summaries[inst] = {**both, "instrument": spec.to_dict(), **sources[inst], "funding_source": funding.source, "settlements": int(funding.ts_us.size), "intents": int(intents.size),
                           "scheduled_intents": int(np.sum(intents["ttl_us"] > 0))}
        receipts[inst].update({"trades": trade_receipts, "funding": funding_receipts})
    meta = {"tape": str(args.tape), "dates": dates, "instruments": list(orders), "parameters": params.to_dict(), "events": args.events, "guard": guard,
            "ct_val_source": {inst: sources[inst]["ct_val_source"] for inst in orders}, "generated_at": tape.iso_now(), "code": code_identity()}
    if args.out:
        out = Path(args.out)
        out.parent.mkdir(parents=True, exist_ok=True)
        arrays = {f"orders_{inst}": rows for inst, rows in orders.items()}
        arrays["meta"] = np.frombuffer(json.dumps(meta, sort_keys=True).encode(), dtype=np.uint8)
        with out.open("wb") as handle:
            np.savez_compressed(handle, **arrays)
    document = {**meta, "manifest_mode": manifest.get("mode") if manifest else None, "symbols": summaries, "receipts": receipts, "elapsed_seconds": round(time.time() - started, 1)}
    if args.summary:
        _write_json(Path(args.summary), document)
    print(json.dumps({"instruments": len(orders), "rule": params.rule, "fills": {inst: s["unconstrained"]["fills"] for inst, s in summaries.items()}, "elapsed_seconds": document["elapsed_seconds"]}))
    return 0


# ----------------------------------------------------------------------------- events


def command_events(args: argparse.Namespace) -> int:
    started = time.time()
    dates = tape.parse_dates(args.dates)
    _, guard = _guard_tape(args, dates)
    kinds = [k for k in args.kind.split(",") if k]
    for kind in kinds:
        if kind not in ("liquidation", "excursion", "clock"):
            raise SystemExit(f"unknown event kind {kind}")
    arrays: dict[str, np.ndarray] = {}
    diagnostics: dict[str, dict] = {}
    ct_val_sources: dict[str, str] = {}
    funding_hours = _hours(args.funding_hours)
    instruments = _instruments(args, ("books5",) + tuple(l for l in _INSTRUMENT_LAYOUTS if l != "books5"))
    rows_by_inst, rows_path = _instrument_rows(args)
    for inst in instruments:
        parts = []
        diag: dict = {}
        if "liquidation" in kinds:
            ct_val, ct_val_sources[inst] = _ct_val(args, rows_by_inst, inst, rows_path)
            liq_rows, _ = tape.load_days(Path(args.tape), "liquidations", inst, dates, allow_pending=args.allow_partial)
            mark_rows, _ = tape.load_days(Path(args.tape), "mark", inst, dates, allow_pending=args.allow_partial)
            rows, d = ev.liquidation_clusters(liq_rows, ct_val, args.abs_notional, args.percentile, args.bin_s, args.lookback_bins, args.min_history_bins,
                                             mark_rows["ts_us"] if mark_rows.size else None, mark_rows["mark_px"] if mark_rows.size else None)
            parts.append(rows)
            diag["liquidation"] = {**d, "ct_val": ct_val, "ct_val_source": ct_val_sources[inst]}
        if "excursion" in kinds:
            book_rows, _ = tape.load_days(Path(args.tape), "books5", inst, dates, allow_pending=args.allow_partial)
            if book_rows.size:
                ts, mid = ev.mid_from_book_rows(book_rows)
                minute_ts, closes, _ = ev.minute_series(ts, mid)
                rows, d = ev.excursions(minute_ts, closes, k=args.k, min_days=args.min_days, median_days=args.median_days, cooldown_min=args.cooldown_min)
            else:
                rows, d = ev.empty_events(), {"minutes": 0, "events": 0, "note": "no books5 rows"}
            parts.append(rows)
            diag["excursion"] = d
        if "clock" in kinds:
            # Settlement instants per instrument from its own funding rows (4 h contracts, stress shortening); fixed hours only when the tape has none.
            funding_rows, _ = tape.load_days(Path(args.tape), "funding", inst, dates, allow_pending=args.allow_partial)
            instants = ev.funding_times_from_tape(funding_rows, dates)
            rows = ev.clock_windows(dates, arm_min=args.arm_min, end_min=args.end_min, funding_hours=funding_hours, funding_times_us=instants if instants.size else None)
            parts.append(rows)
            diag["clock"] = {"events": int(rows.size), "funding_source": "tape" if instants.size else "fixed", "funding_instants": int(instants.size), "funding_hours": list(funding_hours),
                             "funding_rows": int(funding_rows.size), "arm_min": args.arm_min, "end_min": args.end_min}
        merged = np.concatenate(parts) if parts else ev.empty_events()
        arrays[f"events_{inst}"] = merged[np.argsort(merged["ts_us"], kind="stable")]
        diagnostics[inst] = {**diag, **ev.summarize_events(arrays[f"events_{inst}"])}
    meta = {"tape": str(args.tape), "dates": dates, "kinds": kinds, "instruments": instruments, "guard": guard, "ct_val_source": ct_val_sources, "generated_at": tape.iso_now(),
            "code": code_identity(), "thresholds": {"abs_notional": args.abs_notional, "percentile": args.percentile, "bin_s": args.bin_s, "k": args.k, "min_days": args.min_days,
                                                    "arm_min": args.arm_min, "end_min": args.end_min, "funding_hours": list(funding_hours)}}
    if args.out:
        out = Path(args.out)
        out.parent.mkdir(parents=True, exist_ok=True)
        arrays["meta"] = np.frombuffer(json.dumps(meta, sort_keys=True).encode(), dtype=np.uint8)
        with out.open("wb") as handle:
            np.savez_compressed(handle, **arrays)
    document = {**meta, "diagnostics": diagnostics, "elapsed_seconds": round(time.time() - started, 1)}
    if args.summary:
        _write_json(Path(args.summary), document)
    print(json.dumps({"kinds": kinds, "events": {key: int(rows.size) for key, rows in arrays.items() if key != "meta"}, "elapsed_seconds": document["elapsed_seconds"]}))
    return 0


# ----------------------------------------------------------------------------- verify


def command_verify(args: argparse.Namespace) -> int:
    report = tape.verify(Path(args.tape))
    print(json.dumps(report, indent=1, sort_keys=True))
    return 0 if report.get("ok") else 1


# ----------------------------------------------------------------------------- parser


def _add_capture_args(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--out", required=True)
    parser.add_argument("--host", default="global", help="global | eea | a wss:// URL")
    parser.add_argument("--rest-host", default=None, help="global | eea | https:// URL for the instruments list (default: same alias as --host)")
    parser.add_argument("--inst-ids", default="", help="comma-separated instrument ids (no REST call)")
    parser.add_argument("--instruments-file", default="", help="saved instruments response or list of ids (no REST call)")
    parser.add_argument("--family", choices=xc.FAMILIES, default="xperp")
    parser.add_argument("--include-not-live", action="store_true")
    parser.add_argument("--channels", default=",".join(xc.CHANNELS))
    parser.add_argument("--not-before", default=xc.DEFAULT_NOT_BEFORE, help=f"value capture start / row admission instant; must be >= the quarantine floor {xc.QUARANTINE_FLOOR}")
    parser.add_argument("--checkpoint-every", type=float, default=None, help=f"seconds between segment flushes (default {xc.DEFAULT_CHECKPOINT_S['values']:g} in value mode, "
                                                                            f"{xc.DEFAULT_CHECKPOINT_S['counting']:g} when counting): the RAM window a SIGKILL could lose")
    parser.add_argument("--duration-s", type=float, default=0.0, help="stop after this many seconds (0 = until STOP file or signal)")
    parser.add_argument("--max-connection-failures", type=int, default=None)
    parser.add_argument("--liq-selected-only", action="store_true", help="persist liquidation-orders rows of the selected instruments only (the channel pushes every FUTURES instrument)")
    parser.add_argument("--quiet", action="store_true")


def _add_tape_args(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--tape", required=True)
    parser.add_argument("--inst", default="", help="comma-separated instrument ids (default: every instrument in the tape)")
    parser.add_argument("--dates", required=True, help="2026-11-03..2026-11-09[,2026-11-12]")
    parser.add_argument("--instruments-file", default="", help="contract specifications (ctVal, tickSz, lotSz, minSz); default <tape>/instruments-futures.json")
    parser.add_argument("--ct-val", type=float, default=None, help="contract value when the instruments file lacks it (mandatory one way or the other)")
    parser.add_argument("--not-before", default=xc.DEFAULT_NOT_BEFORE, help=f"days before it are refused; must be >= the quarantine floor {xc.QUARANTINE_FLOOR}")
    parser.add_argument("--allow-before-reason", default=None, metavar="TEXT", help="read days before --not-before anyway; the reason is recorded in every summary (synthetic or released data only)")
    parser.add_argument("--allow-partial", action="store_true", help="read pending segments of unfinalized days")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="research_lp", allow_abbrev=False)  # no prefix matching: `--allow-before` is not `--allow-before-reason`
    sub = parser.add_subparsers(dest="command", required=True)
    cap = sub.add_parser("capture", help="value capture (refused before --not-before)", allow_abbrev=False)
    _add_capture_args(cap)
    cap.add_argument("--counting-only", action="store_true")
    cap.set_defaults(func=lambda a: command_capture(a, counting_only=False))
    cnt = sub.add_parser("count", help="counting capture: messages per channel and instrument, no value persisted", allow_abbrev=False)
    _add_capture_args(cnt)
    cnt.set_defaults(counting_only=True, func=lambda a: command_capture(a, counting_only=True))
    sim = sub.add_parser("simulate", allow_abbrev=False)
    _add_tape_args(sim)
    sim.add_argument("--tick", type=float, default=None)
    sim.add_argument("--rule", choices=lp_sim.RULES, default="queue")
    sim.add_argument("--latency-ms", type=float, default=250.0)
    sim.add_argument("--ttl-s", type=float, default=60.0, help="order lifetime (grid and detected events); a clock window lives from its arming to its end instead")
    sim.add_argument("--stale-s", type=float, default=10.0)
    sim.add_argument("--no-post-only", action="store_true")
    sim.add_argument("--grid-s", type=float, default=60.0)
    sim.add_argument("--placement", default="touch", help="touch | inside | ticks:k")
    sim.add_argument("--order-notional", type=float, default=100.0)
    sim.add_argument("--capital", type=float, default=500.0)
    sim.add_argument("--max-live", type=int, default=4)
    sim.add_argument("--leverage", type=float, default=1.0)
    sim.add_argument("--maker-bp", type=float, default=2.0)
    sim.add_argument("--taker-bp", type=float, default=5.0)
    sim.add_argument("--exit-max-s", type=float, default=3600.0)
    sim.add_argument("--funding-source", choices=("tape", "constant"), default="tape")
    sim.add_argument("--funding-interval-h", type=float, default=8.0)
    sim.add_argument("--funding-anchor-h", type=float, default=0.0)
    sim.add_argument("--funding-rate-bp", type=float, default=1.0, help="constant schedule rate per settlement, bp (default 1 bp = the 0.01 % interest term)")
    sim.add_argument("--events", default="", help="events npz from the `events` command: detected events are decided at the next minute, clock windows at their arming instant")
    sim.add_argument("--out", default="")
    sim.add_argument("--summary", default="")
    sim.set_defaults(func=command_simulate)
    evp = sub.add_parser("events", allow_abbrev=False)
    _add_tape_args(evp)
    evp.add_argument("--kind", default="liquidation,excursion,clock")
    evp.add_argument("--abs-notional", type=float, default=50_000.0)
    evp.add_argument("--percentile", type=float, default=99.0)
    evp.add_argument("--bin-s", type=int, default=300)
    evp.add_argument("--lookback-bins", type=int, default=2016)
    evp.add_argument("--min-history-bins", type=int, default=288)
    evp.add_argument("--k", type=float, default=1.5)
    evp.add_argument("--min-days", type=int, default=30)
    evp.add_argument("--median-days", type=int, default=30)
    evp.add_argument("--cooldown-min", type=int, default=15)
    evp.add_argument("--arm-min", type=int, default=5)
    evp.add_argument("--end-min", type=int, default=1)
    evp.add_argument("--funding-hours", default="0,8,16", help="fixed settlement hours (UTC) used only for instruments whose tape holds no funding row")
    evp.add_argument("--out", default="")
    evp.add_argument("--summary", default="")
    evp.set_defaults(func=command_events)
    ver = sub.add_parser("verify", allow_abbrev=False)
    ver.add_argument("--tape", required=True)
    ver.set_defaults(func=command_verify)
    g5_fetch.add_parser(sub)
    g5_eval.add_parser(sub)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
