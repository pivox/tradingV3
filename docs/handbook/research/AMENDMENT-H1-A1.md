# Amendment A1 to PREREGISTRATION-H1 — implementation details, before any read (2026-10-14)

Written and frozen before any post-listing price of S_A was read: `study.py` had not run. None changes a
threshold, a horizon, the event set or a gate. All concern how §4–§8 are computed from the on-disk OKX tape.

1. **OKX index without the 20 programme pairs.** The OKX tape on disk (`20261014T113000Z-tape-okx-swing-v2-monthly`,
   363 families) excludes the 20 programme pairs (BTC, ETH …) and stablecoins. The OKX index is therefore the
   equal-weight top 30 of the *archived* established perpetuals. It needs at least 10 members with prices at both
   dates. The **primary statistic is unaffected**: in the paired surplus S = (r_e − r_M) − mean(r_c − r_M) the index
   cancels. Only the index-relative AR (secondary) depends on it.
2. **Matching σ.** It uses 23 hourly returns, between the hour marks T − 23 h … T, × √24, for events and controls
   alike. A listing exactly on the hour has no price at T − 24 h.
3. **Resolution.** Prices of index members and controls are taken at hour marks, from the last one-minute candle of
   each hour (at most one hour old). The event itself uses the full one-minute series (gaps, MAE / MFE).
4. **Block bootstrap.** Blocks are built from the sorted calendar months *that contain events*, so a month without
   an event is skipped. 2-month and 3-month blocks join consecutive months of that list.
5. **Control prices after a control's delisting.** The control's last close (the same rule as the main event
   rule).
6. **Order of execution.** `prepare` (prices up to T only, diagnostics) writes `prepare.json` before `outcomes`
   runs. `outcomes` refuses to overwrite its output.

The S_A screen (§9.1) is authorised by the owner ("ok je valide, go", 2026-10-14). The S_B download and decisive
run (§9.2) need a separate authorisation after the screen.

## Freeze

The body above this line is hashed (sha256 over the file content up to and including the line "## Freeze" and the
following blank line).

FROZEN 2026-10-14T20:55:09Z body sha256 5c04b1c688d7849d95f538d0eef85457a05ecfe831e2e7d2104ba5fc022243f1
