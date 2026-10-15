# Amendment A3 to PREREGISTRATION-H1 — Gate 2 diagnostics for OKX Europe X-Perps (2026-10-15)

**Owner's decision:** explicit go for the historical Gate 2 of H1 (relayed, 2026-10-15: « H1 Gate 2 historique »),
in the frame recommended by `H1-XPERP-DECISION.md` point 6. Written and frozen **before any Gate 2 result was
computed**. v1 (`10d4f0c3…`), A1 (`5c04b1c6…`) and A2 (`b77d6fed…`) are kept unchanged.

## 1. Why

The audit `H1-XPERP-DECISION.md` found two facts that bear on whether H1 can be traded on OKX Europe X-Perps, the
only product open to a French account:

1. The H1 events that are live on X-Perps at T are all **OKX-first listings**. Their historical set is S_A, not S_B.
2. New-token X-Perps settle funding **every 4 h with a ±1 % cap**, and the interval **shortens when the cap is hit**.
   The protocol's pessimistic funding provision is 0.1 % per 8 h.

The historical Gate 2 (§15) measures neither. A3 adds diagnostics that do.

## 2. What A3 changes

**Nothing in the decision.** It does not change:

- the population, the short rule, the stop d = clamp(3 × σ24, 15 %, 60 %), the 14-day exit;
- the costs, the stress, the funding rule of §12;
- the portfolio (500 USDT, 1×, 0.5 % / 1 %, two positions at full risk, one per instrument);
- the ten criteria of §15 and the verdicts of §15 and §17.

The Gate 2 verdict is computed on S_A ∪ S_B exactly as frozen. **No diagnostic below can turn a fail into a pass, or
a pass into a fail.** No criterion is relaxed after the read.

## 3. Diagnostics added (reported with the verdict, never decisive)

**D1 — subsets.** Every §15 statistic is reported separately on:

- S_A (OKX-first events): the population X-Perps can actually trade at T;
- S_B (Binance-first events).

Each subset is run as its own 500-USDT portfolio with the same rule.

**D2 — X-Perp funding structure.** The H1 portfolio is re-run on S_A ∪ S_B with each trade's funding replaced by an
X-Perp-like schedule built from the same recorded rates:

1. **Rescaling.** Each recorded settlement rate r, over its own interval Δ, is spread evenly over Δ. Funding is then
   settled every 4 h at r × 4 h / Δ, clipped to ±1 %.
2. **Escalation.** When a 4-h settlement hits −1 % (the short pays the cap), the next 12 h settle every 2 h at the
   rescaled rate × 2 h / 4 h, floored at −1 % per settlement. This stylises OKX's one-level escalation and its
   12-hour revert.
3. **Gaps.** Settlements missing under §12 keep the §12 rule.

Reported: the ten §15 statistics under baseline and pessimistic costs, and the total funding paid.

**D3 — break-even funding.** The constant funding rate paid by the short, per 8 h, added to every H1 trade's real
funding, that brings the mean net R per trade (baseline costs) to zero. Found by bisection on [0, 2 %] per 8 h.

**D4 — exit at delisting.** The trade statistics with the unfavourable delisting exit of §4 (the highest close of the
last 24 h) instead of the last close.

**D5 — execution reality.** The distribution of stop distances and notionals of executed trades, and the share of
events skipped below the minimum size or for a full risk budget.

## 4. Labelling

Every Gate 2 output is labelled **"historical, not executable on X-Perps"**. The source venues are OKX Global USDT
swaps and Binance USDⓈ-M. Binance data is for statistics only.

- **A pass** authorises only further research (§16 redefined in a new protocol); never trading.
- **A fail** closes H1 (§17).

## Freeze

The body above this line is hashed (sha256 over the file content up to and including the line "## Freeze" and the
following blank line).

FROZEN 2026-10-15T09:03:56Z body sha256 41a8b614112a56d5a1e905d7675476398490212cbf104fea8564a855b6142edf
