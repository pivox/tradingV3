"""Event-driven liquidity-provision research on OKX Europe X-Perps (programme `lp`, 2026-10-20).

Research tooling only: a public-data collector for `instType FUTURES` instruments (X-Perps
`<BASE>-USD_UM_XPERP-<YYMMDD>` and dated `<BASE>-USD_UM-<YYMMDD>`), a passive-fill simulator
on the captured tape, event detectors (liquidation clusters, conditional excursions, clock
windows) and a command line. No credential, no private endpoint, no order; nothing here can
mutate an exchange account. See `docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md`
(P3, G2, G4, G5, G1 and the transversal rules of §3.5) for the programme this serves.

Modules:

- `tape`           npz layouts, in-memory buffers, the segment-based day store (a restart can never
                   overwrite what an earlier process wrote), the directory lock, loaders, `verify`;
- `xperp_capture`  frame parsing, instrument selection, the counting mode (no value decoded or
                   written), the not-before guard and its quarantine floor (`QUARANTINE_FLOOR`,
                   2026-11-02: nothing of the forward test window 2026-10-14..31 may be captured
                   or read), the health journal, the WebSocket loop with SIGTERM / SIGINT handling;
- `lp_sim`         passive fills (touch / through / queue), post-only, latency, mark-outs, exit,
                   funding, fees, unconstrained and 500 USDT portfolio readings; event intents
                   decided at the next minute (detected events) or at the arming instant (clock windows);
- `events`         detectors;
- `g5_fetch`       REST fetcher of the X-Perp 1-minute history for G5 Gate 1 (candles, mark, index,
                   funding history), paginated backwards from the constant `END_EXCLUSIVE`
                   (2026-10-14T00:00:00Z — no row at or after it is ever persisted), documented rate
                   limits, backoff, resume through the segment store, dry run; driven by the G5 spec
                   (per-stream starts, phases), the funding history in sealed mode (P-G5-3), its store archived
                   into one hashed file read in memory by the evaluator (`SealedArchive`);
- `g5_eval`        the G5 Gate 1 / Gate 2 evaluator of the frozen machine specs `specs/g5_gate1_spec.json`
                   and `specs/g5_gate2_spec.json` (schema `g5-spec/2`, strict): one classifier P̂(t_arm), fill
                   proxy, controls at the type's minute of the hour with footprint exclusions, instant unit,
                   day-block bootstrap, C1–C7, C6 validation, Holm, sealing of INCONCLUSIVE / NOT_INFORMATIVE,
                   economic reading, descriptive reports, Gate 2 simulation on the capture;
- `g5_gate2`       the strict reader of the Gate 2 spec (owner decisions, identity with the Gate 1, calcul
                   d'audit, calendars covering the window) and its runtime helpers (T0, per-type windows,
                   unusable instrument-days, inscription read from the Gate 1 result, capture manifest);
- `cli`            `capture`, `count`, `simulate`, `events`, `verify`, `g5-fetch`, `g5-eval`.
"""
