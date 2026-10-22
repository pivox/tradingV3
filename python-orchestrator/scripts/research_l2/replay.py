"""Offline reading of a pilot run: integrity (`verify`), header-only quality summary (`quality_summary`) and the book replay in
availability order (`replay_records`, `Replayer`).

Availability order = the reception order of the collector (`rx`, which is also the write order). The replay is a single pass:
an observation depends only on the records before it and on itself (no look-ahead, no reordering buffer). Across any break
— a seqId gap, an out-of-order message, a malformed update, a checksum failure, a reconnect, a resubscription, an unsubscribe
— the book is INVALID and no book view is exposed until a new full snapshot arrives.

`quality_summary` reads only the record headers (identifiers, seqId / prevSeqId, counts, local clocks): the `raw` field, which
holds prices, is dropped unread. Its output carries no price, no size, no signal and no PnL (`contains_prices: false`).
"""

from __future__ import annotations

import gzip
import json
import math
import zlib
from dataclasses import dataclass
from pathlib import Path

from scripts.research_lp import tape

from . import book as bk
from . import pilot as pl

MINUTE_NS = 60 * 10**9
HOUR_NS = 3600 * 10**9


# ----------------------------------------------------------------------------- integrity


def _tokens_for(run_dir: Path) -> list[str]:
    copy = Path(run_dir) / "pilot-spec.json"
    source = copy if copy.exists() else pl.DEFAULT_SPEC
    return list(json.loads(source.read_text())["protected_path_tokens"])


def assert_readable_run(run_dir: Path) -> None:
    """The readers refuse a path inside a protected tree, like the collector does for its output."""
    tokens = json.loads(pl.DEFAULT_SPEC.read_text())["protected_path_tokens"]
    hit = pl.protected_component(Path(run_dir), tokens)
    if hit is not None:
        raise pl.PilotRefused("protected_input_path", f"{run_dir} (component {hit!r})")


def read_manifest(run_dir: Path) -> dict:
    return json.loads((Path(run_dir) / pl.MANIFEST_NAME).read_text())


def verify(run_dir: Path) -> dict:
    """Every closed segment of the manifest exists with its size and sha256; segment files on disk that the manifest does not
    list (a segment left open by a kill) are reported; the spec copy hashes to the manifest's spec sha256."""
    run_dir = Path(run_dir)
    assert_readable_run(run_dir)
    manifest = read_manifest(run_dir)
    problems: list[str] = []
    listed = set()
    for entry in manifest.get("segments", []):
        path = run_dir / entry["file"]
        listed.add(entry["file"])
        if not path.exists():
            problems.append(f"missing {entry['file']}")
            continue
        if path.stat().st_size != entry["bytes"]:
            problems.append(f"size {entry['file']}")
        if tape.sha256_of(path) != entry["sha256"]:
            problems.append(f"sha256 {entry['file']}")
    unlisted = sorted(str(p.relative_to(run_dir)) for p in (run_dir / pl.SEGMENTS_DIR).glob("seg-*.jsonl.gz") if str(p.relative_to(run_dir)) not in listed)
    copy = run_dir / "pilot-spec.json"
    if not copy.exists() or tape.sha256_of(copy) != manifest["spec"]["sha256"]:
        problems.append("spec copy hash")
    raw = run_dir / "instruments-futures-raw.json"
    if not raw.exists() or tape.sha256_of(raw) != manifest["instruments"]["raw_sha256"]:
        problems.append("instruments snapshot hash")
    return {"ok": not problems and manifest.get("status") == "stopped", "status": manifest.get("status"), "problems": problems, "unlisted_segments": unlisted,
            "segments": len(manifest.get("segments", [])), "stop_reason": (manifest.get("stop") or {}).get("reason")}


def segment_files(run_dir: Path) -> list[Path]:
    return sorted((Path(run_dir) / pl.SEGMENTS_DIR).glob("seg-*.jsonl.gz"))


def iter_records(run_dir: Path, stats: dict | None = None):
    """Records of every segment, in segment order then line order (= rx order). A truncated last segment (killed process) is
    read up to its last complete line and counted (`truncated_segments`); an unreadable line is counted (`bad_lines`)."""
    stats = stats if stats is not None else {}
    for path in segment_files(run_dir):
        try:
            with gzip.open(path, "rb") as handle:
                for line in handle:
                    try:
                        yield json.loads(line)
                    except ValueError:
                        stats["bad_lines"] = stats.get("bad_lines", 0) + 1
        except (EOFError, OSError, zlib.error):
            stats["truncated_segments"] = stats.get("truncated_segments", 0) + 1


# ----------------------------------------------------------------------------- replay


@dataclass(frozen=True)
class Observation:
    """One record as seen at its availability instant. `book` is set only for a VALID `books` state (a frozen copy)."""

    rx: int
    recv_ns: int
    conn: int
    kind: str  # book | books5 | trades | event | meta | other
    inst: str
    verdict: str
    valid: bool
    book: bk.BookView | None = None
    rows: tuple | None = None
    detail: str = ""


class _ReplayState:
    def __init__(self, depth: int | None, stats: dict) -> None:
        self.depth = depth
        self.trackers: dict[str, bk.SeqTracker] = {}
        self.books: dict[str, bk.Book] = {}
        self.stats = stats
        self.stats.update({"records": 0, "rx_non_monotonic": 0, "recv_clock_backwards": 0, "checksum_checked": 0, "checksum_failures": 0,
                           "checksum_zero_or_absent": 0, "crossed_books": 0, "malformed_levels": 0, "invalidations": {}, "verdicts": {}})
        self.last_rx = 0
        self.last_recv = None

    def tracker(self, inst: str) -> bk.SeqTracker:
        if inst not in self.trackers:
            self.trackers[inst] = bk.SeqTracker()
            self.books[inst] = bk.Book()
        return self.trackers[inst]

    def invalidate_all(self, reason: str) -> None:
        for inst in self.trackers:
            self.invalidate(inst, reason)

    def invalidate(self, inst: str, reason: str) -> None:
        self.tracker(inst).invalidate(reason)
        self.books[inst].clear()
        self.stats["invalidations"][reason] = self.stats["invalidations"].get(reason, 0) + 1

    def count(self, verdict: str) -> None:
        self.stats["verdicts"][verdict] = self.stats["verdicts"].get(verdict, 0) + 1


def replay_records(records, depth: int | None = 25, stats: dict | None = None):
    """Generator of `Observation`, strictly in the order of `records` (availability order). See the module docstring. `stats`
    (optional dict) receives the counters of the pass."""
    state = _ReplayState(depth, stats if stats is not None else {})
    for record in records:
        state.stats["records"] += 1
        rx, recv_ns, conn = int(record.get("rx", 0)), int(record.get("recv_ns", 0)), int(record.get("conn", 0))
        if rx <= state.last_rx:
            state.stats["rx_non_monotonic"] += 1
        state.last_rx = max(state.last_rx, rx)
        if state.last_recv is not None and recv_ns < state.last_recv:
            state.stats["recv_clock_backwards"] += 1
        state.last_recv = recv_ns if state.last_recv is None else max(state.last_recv, recv_ns)
        if record.get("k") == "meta":
            event = str(record.get("event", ""))
            if event in ("connected", "disconnected", "connect_failed"):
                state.invalidate_all(event)
            elif event == "resync_sent" and record.get("inst"):
                state.invalidate(str(record["inst"]), "resync")
            yield Observation(rx, recv_ns, conn, "meta", str(record.get("inst", "")), event, False, detail=event)
            continue
        header = record.get("h") or {}
        if header.get("t") == "event":
            inst = header.get("inst", "")
            if header.get("event") in ("unsubscribe",) and header.get("ch") in bk.BOOK_CHANNELS and inst:
                state.invalidate(inst, "unsubscribed")
            yield Observation(rx, recv_ns, conn, "event", inst, str(header.get("event")), False, detail=str(header.get("code", "")))
            continue
        if header.get("t") != "data":
            yield Observation(rx, recv_ns, conn, "other", "", str(header.get("t")), False)
            continue
        channel, inst = header.get("ch", ""), header.get("inst", "")
        try:
            message = json.loads(record["raw"])
            data = message.get("data") or []
        except (KeyError, ValueError, AttributeError):
            yield Observation(rx, recv_ns, conn, "other", inst, "unreadable", False)
            continue
        if channel in bk.BOOK_CHANNELS:
            yield _books_observation(state, rx, recv_ns, conn, inst, message, data)
        elif channel in bk.SNAPSHOT_CHANNELS:
            first = data[0] if data and isinstance(data[0], dict) else {}
            view = bk.BookView(bids=tuple(tuple(str(x) for x in lvl) for lvl in (first.get("bids") or [])[:depth]),
                               asks=tuple(tuple(str(x) for x in lvl) for lvl in (first.get("asks") or [])[:depth]),
                               seq=bk._int_or_none(first.get("seqId")), depth_bids=len(first.get("bids") or []), depth_asks=len(first.get("asks") or []))
            yield Observation(rx, recv_ns, conn, "books5", inst, "snapshot", bool(first), book=view)
        elif channel in bk.TRADE_CHANNELS:
            yield Observation(rx, recv_ns, conn, "trades", inst, "trades", True, rows=tuple(json.dumps(row, sort_keys=True) for row in data))
        else:
            yield Observation(rx, recv_ns, conn, "other", inst, channel, False)


def _books_observation(state: _ReplayState, rx, recv_ns, conn, inst, message, data) -> Observation:
    first = data[0] if data and isinstance(data[0], dict) else {}
    tracker = state.tracker(inst)
    book = state.books[inst]
    seq, prev = bk._int_or_none(first.get("seqId")), bk._int_or_none(first.get("prevSeqId"))
    verdict = tracker.classify(message.get("action"), prev, seq)
    state.count(verdict)
    try:
        if verdict == bk.SNAPSHOT:
            book.load_snapshot(first.get("bids"), first.get("asks"))
        elif verdict in bk.APPLIED and tracker.valid:
            book.apply(first.get("bids"), first.get("asks"))
        elif verdict in bk.BREAKS:
            state.invalidate(inst, verdict)
    except bk.LevelError:
        state.stats["malformed_levels"] += 1
        state.invalidate(inst, "malformed_level")
    if tracker.valid and verdict in bk.APPLIED:
        delivered = bk._int_or_none(first.get("checksum"))
        if delivered:
            state.stats["checksum_checked"] += 1
            if book.checksum() != delivered:
                state.stats["checksum_failures"] += 1
                state.invalidate(inst, "checksum")
        else:
            state.stats["checksum_zero_or_absent"] += 1
        if tracker.valid and book.crossed():
            state.stats["crossed_books"] += 1
    if tracker.valid:
        return Observation(rx, recv_ns, conn, "book", inst, verdict, True, book=book.view(state.depth, tracker.last_seq))
    return Observation(rx, recv_ns, conn, "book", inst, verdict, False, detail=tracker.reason)


class Replayer:
    """`Replayer(run).observations()` — the run's records replayed in availability order; `stats` after the pass."""

    def __init__(self, run_dir: Path, depth: int | None = 25) -> None:
        assert_readable_run(Path(run_dir))
        self.run_dir = Path(run_dir)
        self.depth = depth
        self.read_stats: dict = {}
        self.stats: dict = {}

    def observations(self):
        self.stats = {}
        yield from replay_records(iter_records(self.run_dir, self.read_stats), self.depth, self.stats)


def replay_check(run_dir: Path) -> dict:
    """Full book reconstruction over the run, counts only (no price leaves this function): verdicts, invalidations, checksum
    checks and failures, crossed books, malformed levels, valid / invalid book observations per instrument."""
    replayer = Replayer(run_dir, depth=0)
    per_inst: dict[str, dict] = {}
    for obs in replayer.observations():
        if obs.kind == "book":
            cell = per_inst.setdefault(obs.inst, {"valid": 0, "invalid": 0})
            cell["valid" if obs.valid else "invalid"] += 1
    return {"kind": "l2-pilot-replay-check", "contains_prices": False, "per_instrument": per_inst, "stats": replayer.stats, "read": replayer.read_stats}


# ----------------------------------------------------------------------------- quality (headers only)


def _split_by_hour(start: int, end: int, into: dict) -> None:
    while start < end:
        hour = start // HOUR_NS
        stop = min(end, (hour + 1) * HOUR_NS)
        into[hour] = into.get(hour, 0) + (stop - start)
        start = stop


def _p95(values: list[float]) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    return ordered[min(len(ordered) - 1, int(math.ceil(0.95 * len(ordered))) - 1)]


def quality_summary(run_dir: Path, do_verify: bool = True) -> dict:
    """Volumes, message counts, seqId gaps, resyncs, reconnects, checksum fields delivered, coverage per minute and per hour,
    INVALID book time — from the headers only. Applies the frozen `quality_criteria` of the run's spec copy."""
    run_dir = Path(run_dir)
    assert_readable_run(run_dir)
    manifest = read_manifest(run_dir)
    spec = json.loads((run_dir / "pilot-spec.json").read_text())
    criteria = spec["quality_criteria"]
    insts = list(manifest["instruments"]["selected"])
    read_stats: dict = {}
    trackers = {inst: bk.SeqTracker() for inst in insts}
    invalid_from: dict[str, int | None] = {}
    invalid_ns = {inst: 0 for inst in insts}
    invalid_by_hour = {inst: {} for inst in insts}
    resync_latency_s: dict[str, list[float]] = {inst: [] for inst in insts}
    cells: dict[str, dict] = {}
    minutes: dict[str, set] = {}
    by_hour_msgs: dict[int, dict] = {}
    events: dict[str, int] = {}
    errors: list[dict] = []
    meta_counts: dict[str, int] = {}
    first_ns = last_ns = None

    def mark_invalid(inst: str, at: int) -> None:
        if invalid_from.get(inst) is None:
            invalid_from[inst] = at

    def mark_valid(inst: str, at: int) -> None:
        start = invalid_from.get(inst)
        if start is not None:
            invalid_ns[inst] += at - start
            _split_by_hour(start, at, invalid_by_hour[inst])
            resync_latency_s[inst].append((at - start) / 1e9)
        invalid_from[inst] = None

    for record in iter_records(run_dir, read_stats):
        line_bytes = len(record.pop("raw", "") or "")  # dropped unread: only its length is kept
        recv = int(record.get("recv_ns", 0))
        if first_ns is None:
            first_ns = recv
            for inst in insts:
                invalid_from[inst] = recv  # INVALID until the first snapshot
        last_ns = recv
        if record.get("k") == "meta":
            event = str(record.get("event"))
            meta_counts[event] = meta_counts.get(event, 0) + 1
            if event in ("connected", "disconnected", "connect_failed"):
                for inst in insts:
                    trackers[inst].invalidate(event)
                    mark_invalid(inst, recv)
            elif event == "resync_sent" and record.get("inst") in trackers:
                trackers[record["inst"]].invalidate("resync")
                mark_invalid(record["inst"], recv)
            continue
        header = record.get("h") or {}
        if header.get("t") == "event":
            name = str(header.get("event"))
            events[name] = events.get(name, 0) + 1
            if name == "error":
                errors.append({"code": header.get("code"), "msg": header.get("msg")})
            if name == "unsubscribe" and header.get("ch") in bk.BOOK_CHANNELS and header.get("inst") in trackers:
                trackers[header["inst"]].invalidate("unsubscribed")
                mark_invalid(header["inst"], recv)
            continue
        if header.get("t") != "data":
            continue
        channel, inst = header.get("ch", ""), header.get("inst", "")
        key = f"{channel}|{inst}"
        cell = cells.setdefault(key, {"channel": channel, "inst": inst, "messages": 0, "rows": 0, "raw_chars": 0, "snapshots": 0, "checksum_nonzero": 0,
                                      "checksum_zero": 0, "checksum_absent": 0, "verdicts": {}, "max_levels_bids": 0, "max_levels_asks": 0})
        cell["messages"] += 1
        cell["rows"] += int(header.get("n") or 0)
        cell["raw_chars"] += line_bytes
        cell["max_levels_bids"] = max(cell["max_levels_bids"], int(header.get("nb") or 0))
        cell["max_levels_asks"] = max(cell["max_levels_asks"], int(header.get("na") or 0))
        minutes.setdefault(key, set()).add(recv // MINUTE_NS)
        hour_cell = by_hour_msgs.setdefault(recv // HOUR_NS, {})
        hour_cell[key] = hour_cell.get(key, 0) + 1
        if channel in bk.BOOK_CHANNELS:
            if "cs" not in header:
                cell["checksum_absent"] += 1
            elif header.get("cs"):
                cell["checksum_nonzero"] += 1
            else:
                cell["checksum_zero"] += 1
            if inst in trackers:
                verdict = trackers[inst].classify(header.get("action"), header.get("prev"), header.get("seq"))
                cell["verdicts"][verdict] = cell["verdicts"].get(verdict, 0) + 1
                if verdict == bk.SNAPSHOT:
                    cell["snapshots"] += 1
                    mark_valid(inst, recv)
                elif not trackers[inst].valid:
                    mark_invalid(inst, recv)
    if last_ns is not None:
        for inst in insts:
            if invalid_from.get(inst) is not None:
                start = invalid_from[inst]
                invalid_ns[inst] += last_ns - start
                _split_by_hour(start, last_ns, invalid_by_hour[inst])
                invalid_from[inst] = None
    span_ns = (last_ns - first_ns) if first_ns is not None else 0
    first_min = first_ns // MINUTE_NS if first_ns is not None else 0
    last_min = last_ns // MINUTE_NS if last_ns is not None else -1
    n_minutes = max(0, last_min - first_min + 1)
    instruments: dict[str, dict] = {}
    for inst in insts:
        books = cells.get(f"books|{inst}", {})
        verdicts = books.get("verdicts", {})
        gaps = sum(verdicts.get(v, 0) for v in bk.BREAKS)
        instruments[inst] = {
            "books_messages": books.get("messages", 0), "books5_messages": cells.get(f"books5|{inst}", {}).get("messages", 0),
            "trades_messages": cells.get(f"trades|{inst}", {}).get("messages", 0), "trades_rows": cells.get(f"trades|{inst}", {}).get("rows", 0),
            "books_snapshots": books.get("snapshots", 0), "books_breaks": gaps, "books_verdicts": verdicts,
            "book_valid_fraction": (1.0 - invalid_ns[inst] / span_ns) if span_ns > 0 and books else 0.0,
            "invalid_seconds": invalid_ns[inst] / 1e9,
            "resync_snapshot_latency_s_p95": _p95(resync_latency_s[inst][1:]),  # the first entry is the start-up wait
            "minute_coverage": {ch: (len(minutes.get(f"{ch}|{inst}", ())) / n_minutes if n_minutes else 0.0) for ch in ("books", "books5", "trades")},
            "max_levels": {"books": [books.get("max_levels_bids", 0), books.get("max_levels_asks", 0)]},
            "checksum": {"nonzero": books.get("checksum_nonzero", 0), "zero": books.get("checksum_zero", 0), "absent": books.get("checksum_absent", 0)},
        }
    hours = []
    for hour in sorted(by_hour_msgs):
        lo, hi = max(hour * HOUR_NS, first_ns or 0), min((hour + 1) * HOUR_NS, last_ns or 0)
        width = max(hi - lo, 1)
        hours.append({"hour_utc": tape.iso_utc(hour * HOUR_NS // 1000), "messages": by_hour_msgs[hour],
                      "book_valid_fraction": {inst: max(0.0, 1.0 - invalid_by_hour[inst].get(hour, 0) / width) for inst in insts}})
    summary = {"kind": "l2-pilot-quality", "contains_prices": False, "run": str(run_dir), "spec_sha256": manifest["spec"]["sha256"],
               "stop_reason": (manifest.get("stop") or {}).get("reason"), "bounds": manifest.get("bounds"), "span_s": span_ns / 1e9,
               "bytes": manifest.get("bytes"), "segments": len(manifest.get("segments", [])), "records_read": sum(c["messages"] for c in cells.values()),
               "read_anomalies": read_stats, "reconnects": manifest.get("reconnects"), "resyncs_sent": meta_counts.get("resync_sent", 0), "meta": meta_counts,
               "events": events, "errors": errors[:50], "depth_status": manifest.get("depth_status"), "instruments": instruments, "by_hour": hours,
               "cells": sorted(cells.values(), key=lambda c: (c["channel"], c["inst"]))}
    summary["verify"] = verify(run_dir) if do_verify else None
    summary["criteria"], summary["verdict"] = apply_criteria(summary, criteria, insts)
    return summary


def apply_criteria(summary: dict, criteria: dict, insts: list[str]) -> tuple[dict, str]:
    """The frozen pass/fail rule of the spec (`quality_criteria`)."""
    checks: dict[str, bool] = {}
    reservations: list[str] = []
    depth = summary.get("depth_status") or {}
    for inst in insts:
        cell = summary["instruments"][inst]
        if depth.get(inst) != "books":
            reservations.append(f"{inst}: depth {depth.get(inst)}")
            checks[f"{inst}.books5_minute_coverage"] = cell["minute_coverage"]["books5"] >= criteria["minute_coverage_min"]
            continue
        checks[f"{inst}.book_valid_fraction"] = cell["book_valid_fraction"] >= criteria["book_valid_fraction_min"]
        checks[f"{inst}.books_minute_coverage"] = cell["minute_coverage"]["books"] >= criteria["minute_coverage_min"]
        checks[f"{inst}.gaps"] = cell["books_breaks"] <= criteria["gaps_per_instrument_max"]
        p95 = cell["resync_snapshot_latency_s_p95"]
        checks[f"{inst}.resync_latency_p95"] = p95 is None or p95 <= criteria["resync_snapshot_within_s_p95_max"]
        checks[f"{inst}.out_of_order"] = cell["books_verdicts"].get(bk.OUT_OF_ORDER, 0) <= criteria["out_of_order_max"]
        if cell["checksum"]["nonzero"]:
            reservations.append(f"{inst}: non-zero checksums delivered, run replay-check for failures")
    checks["reconnects"] = (summary.get("reconnects") or 0) <= criteria["reconnects_max"]
    if summary.get("verify") is not None:
        checks["verify"] = bool(summary["verify"]["ok"])
    if summary.get("stop_reason") != "max_duration":
        reservations.append(f"stopped by {summary.get('stop_reason')} before the full duration")
    if not all(checks.values()):
        verdict = "FAIL"
    elif reservations:
        verdict = "PASS_WITH_RESERVATIONS"
    else:
        verdict = "PASS"
    return {"checks": checks, "reservations": reservations, "thresholds": criteria}, verdict
