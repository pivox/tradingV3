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
- `cli`            `capture`, `count`, `simulate`, `events`, `verify`.
"""
