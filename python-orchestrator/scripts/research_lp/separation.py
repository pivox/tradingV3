"""P-L2-1 (D14, 2026-10-22): the L2 capture pilot's development window, separated from every decisive use of the LP programme.

The owner reserved a short development exploration window for the L2 capture pilot (branch `claude/research-l2-capture-pilot`):
preferred slot 2026-11-02T00:00Z -> 2026-11-03T00:00Z, a later manual start shortens it and never shifts its end. The pilot's
content (400-level books and trades of the BTC and ETH X-Perps) may be explored for development. Nothing explored may then reach a
decisive test: the separation bears on the **observations used**, not on storage folders.

Rule (`BLOCK`, copied verbatim into the G5 Gate 2 spec as `pilot_separation` and checked for equality by its reader):

- reserved interval R = [2026-11-02T00:00Z ; 2026-11-03T00:00Z) (the pilot's hard end, whatever its start);
- margin 12 h, separation floor F = 2026-11-03T12:00Z, excluded interval E = [2026-11-02T00:00Z ; F);
- no observation timestamped in E enters a decisive computation of the programme, nor its footprints, look-backs, warm-ups, rodage
  day, calibration, quality rules or instrument selection (G5 Gate 2, G2, G4 Gate 2, P3 counts). Data captured in E are
  development data only.

Code enforcement (this module holds the constants; the callers apply them):

- `g5_eval.load_capture_series` drops the capture rows before F (counted) and refuses a residual one (`refuse_inside`);
- `g5_gate2` refuses a spec whose `pilot_separation` block differs from `BLOCK`, whose `window.start` or `freeze_fill.t0_utc` is before
  `G5_GATE2_T0_MIN_UTC` (= first 00:00 UTC >= F + `MAX_LOOKBACK_MIN`);
- `g5_index_fetch` refuses a read that starts before F;
- `research_lp simulate` / `events` (G2, G4 Gate 2) refuse every day file of 2026-11-02 and 2026-11-03 (`dates_touching`, day
  granularity, stricter than F) unless `--separation-waiver-reason TEXT` (development only, recorded);
- `research_lp count` (P3) refuses a `--not-before` before F (P3 never counts during the pilot).

The pilot itself (`scripts.research_l2`, spec `l2-pilot-spec/2`) refuses to capture or to rebuild its books unless the G5 Gate 2 spec it
is given carries this block (sha256 of its canonical JSON, `block_sha256`).
"""

from __future__ import annotations

import hashlib
import json

import numpy as np

from . import tape

DECISION_ID = "P-L2-1"
RESERVED_START_UTC = "2026-11-02T00:00:00Z"
RESERVED_END_UTC = "2026-11-03T00:00:00Z"  # the pilot's hard end, whatever its start
MARGIN_S = 12 * 3600
SEPARATION_FLOOR_UTC = "2026-11-03T12:00:00Z"  # RESERVED_END_UTC + MARGIN_S
MAX_LOOKBACK_MIN = 540  # G5 Gate 2: the 480-minute classifier look-back before t_arm + the 60-minute margin of g5_index_fetch
G5_GATE2_T0_MIN_UTC = "2026-11-04T00:00:00Z"  # first 00:00 UTC >= SEPARATION_FLOOR_UTC + MAX_LOOKBACK_MIN

BLOCK = {
    "decision": ("P-L2-1 (LP-OWNER-DECISIONS-2026-10-21.md D14 ; owner mission of 2026-10-22): reserve a short development exploration window for the L2 "
                 "capture pilot, preferred slot 2026-11-02T00:00Z -> 2026-11-03T00:00Z (a later manual start shortens it, never shifts the end), separated "
                 "from every decisive use of the LP programme by the observations used, not by storage folders"),
    "reserved_interval_utc": [RESERVED_START_UTC, RESERVED_END_UTC],
    "margin_s": MARGIN_S,
    "separation_floor_utc": SEPARATION_FLOOR_UTC,
    "excluded_interval_utc": [RESERVED_START_UTC, SEPARATION_FLOOR_UTC],
    "rule": ("no observation timestamped in excluded_interval_utc (prints, books, bbo, mark, index, funding messages, liquidations, open interest, message "
             "counts) enters a decisive computation of the LP programme, nor its footprints, look-backs, warm-ups, rodage day, calibration, quality rules or "
             "instrument selection ; data captured in that interval, by the pilot or by any LP collector, are development data only"),
    "g5_gate2_t0_min_utc": G5_GATE2_T0_MIN_UTC,
    "max_lookback_min": MAX_LOOKBACK_MIN,
    "applies_to": {
        "g5_gate1": "absent: historical window 2026-04-28 .. 2026-10-14 (exclusive), fetched and read under g5_fetch.END_EXCLUSIVE",
        "g5_gate2": ("T0 >= g5_gate2_t0_min_utc = first 00:00 UTC >= separation_floor_utc + max_lookback_min (480-minute classifier look-back before t_arm, "
                     "60-minute margin of g5_index_fetch ; every footprint starts at or after T0) ; capture rows before separation_floor_utc dropped and counted "
                     "before any computation (g5_eval.load_capture_series), a residual row refused ; g5_index_fetch refuses a start before separation_floor_utc"),
        "g2": ("T0 = first 00:00 UTC >= max(2026-11-02 ; separation_floor_utc ; end of P3 ; freeze + 24 h) ; rodage day T0 ; research_lp simulate and events "
               "refuse the day files of 2026-11-02 and 2026-11-03 (day granularity, stricter than the floor) unless --separation-waiver-reason TEXT "
               "(development only, recorded in the guard record)"),
        "g4_gate2": ("the look-back history (2 016 five-minute bins, at least 288) and the 24-hour sigma and notional hold no bin before separation_floor_utc: "
                     "research_lp events refuses the day files of 2026-11-02 and 2026-11-03 ; bins before the first readable day are absent, never imputed"),
        "p3": ("research_lp count refuses a --not-before before separation_floor_utc: P3 never runs during the pilot, its counts, coverage table and "
               "selection file hold no minute of excluded_interval_utc"),
        "pilot": ("l2-pilot-spec/2: start >= reserved_interval_utc[0], hard end reserved_interval_utc[1] whatever the start, duration = min(24 h, hard end - "
                  "start) ; capture and replay-check refused unless this block is found, sha256 of its canonical JSON equal, in the G5 Gate 2 spec given"),
    },
    "reading": ("pilot content (prices, books, trades) opened only once this separation is recorded (P-L2-1 in LP-OWNER-DECISIONS-2026-10-21.md and in "
                "REGISTRY.md, this block in the G5 Gate 2 spec) ; before that, quality and volume summaries only ; a protocol frozen after the pilot content "
                "is opened declares that exposure among its prior exposures (development data, never a threshold, a window or a universe choice) ; the "
                "pilot's volume measures never decide the P3 / G2 collector ceiling (G2 3.4: P3 facts only)"),
}


class SeparationViolation(RuntimeError):
    """An observation of the excluded interval reached a decisive reader."""


def canonical_json(value) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def block_sha256(block: dict | None = None) -> str:
    """sha256 of the canonical JSON of the block (sorted keys, no spaces, UTF-8): the identity the pilot checks."""
    return hashlib.sha256(canonical_json(BLOCK if block is None else block)).hexdigest()


def reserved_start_us() -> int:
    return tape.parse_iso_utc(RESERVED_START_UTC)


def floor_us() -> int:
    return tape.parse_iso_utc(SEPARATION_FLOOR_UTC)


def t0_min_us() -> int:
    return tape.parse_iso_utc(G5_GATE2_T0_MIN_UTC)


def inside(ts_us) -> np.ndarray:
    """Mask of the timestamps (µs) inside the excluded interval [RESERVED_START_UTC ; SEPARATION_FLOOR_UTC)."""
    ts = np.asarray(ts_us, dtype=np.int64)
    return (ts >= reserved_start_us()) & (ts < floor_us())


def refuse_inside(ts_us, where: str) -> None:
    hits = int(inside(ts_us).sum())
    if hits:
        raise SeparationViolation(f"pilot_separation ({DECISION_ID}): {hits} observation(s) of {where} inside the excluded interval "
                                  f"[{RESERVED_START_UTC} ; {SEPARATION_FLOOR_UTC})")


def dates_touching(dates) -> list[str]:
    """The UTC day files (YYYY-MM-DD) whose day meets the excluded interval: 2026-11-02 and 2026-11-03."""
    lo, hi = reserved_start_us(), floor_us()
    out = []
    for d in dates:
        start = tape.day_of_date(str(d)) * tape.DAY_S * tape.SECOND_US
        if start < hi and start + tape.DAY_S * tape.SECOND_US > lo:
            out.append(str(d))
    return out


def check_block(block, where: str = "pilot_separation") -> str:
    """Equality with BLOCK (every key and text); returns its sha256."""
    if block != BLOCK:
        raise ValueError(f"{where}: differs from research_lp.separation.BLOCK (P-L2-1, the implemented rule ; sha256 {block_sha256()})")
    return block_sha256(block)
