# H1 on OKX Europe X-Perps — decision (2026-10-15)

**Verdict: `H1_XPERP_CONDITIONAL`.** Exploiting H1 on X-Perps is plausible, but four important verifications
remain. As frozen, the prospective test (12 months, ≥ 30 executed trades) would very likely end `INCONCLUSIVE`.

This is a feasibility audit: metadata, official documentation and code only. No backtest, no PnL, no order. The H1
scientific record is unchanged: v1 `10d4f0c3…`, A1 `5c04b1c6…`, A2 `b77d6fed…`, S_A screen pass, S_B Gate 1 pass
(PR #505). **Gate 1 passing is not an economic validation**, and it did not weigh on this verdict.

Supporting notes: `H1-XPERP-MARKET-ACCESS.md`, `H1-XPERP-LISTING-INVENTORY.md`, `H1-XPERP-500USDT-FEASIBILITY.md`,
`H1-XPERP-TECHNICAL-GAP.md`.

## 1. Answers

### 1. How many new tokens are really on X-Perps?

- **30 of the 160 crypto X-Perps** are new tokens: their first OKX / Binance perpetual is less than 180 days before
  the X-Perp.
- 130 are established tokens being added, and 83 X-Perps are not crypto.
- The first new-token X-Perp appeared on 2026-06-03.

### 2. How many would have met the original H1 definition?

**9:** GRAM, O, NES, AEON, GRVT, KII, XDP, CT, QUANT, from 2026-06-17 to 2026-10-01. Each is a new token, its
first OKX / Binance perpetual, not pre-market, with the X-Perp live at T = ceil_hour(t0 + 24 h).

- 4 more were live only at T + 48 h. They are a diagnostic only and not eligible.
- The other 17 new tokens got their X-Perp 12 days to 6 months later.
- **All 9 are OKX-first listings.** No Binance-first token reached an X-Perp in time.
- The historical evidence for this subset is therefore S_A (OKX: −11.4 %, a screen, unstable in time), not S_B
  (Binance-first only, the Gate 1 pass). **The S_B result does not transfer automatically.**
- OKX chooses which tokens get a same-day X-Perp, which is a further selection risk.

### 3. Can they be shorted with 500 USDT?

Yes in principle, under conditions:

- **Allowed:** shorts are permitted, there is negative balance protection, and 1× isolated margin is available.
- **Margin currency:** USDT must be converted to USDC / USD.
- **Size:** at the typical H1 stop (60 %, the cap) the position is about **4 USD of notional**.
  - At today's prices, 7 of the 9 contracts accept that size. O and KII would be skipped under the never-round-up
    rule.
  - One-contract granularity uses only 50–90 % of the risk budget on some contracts.
- **Unverified:**
  - stop fills in a gap: price bands of ±4 % for the first 10 minutes, then capped at 30 % from the index;
    triggered-stop behaviour is undocumented;
  - suspension rules;
  - the funding actually paid. **New-token X-Perps pay every 4 h with a ±1 % cap, and the interval shortens when the
    cap is hit.** That is far above the protocol's pessimistic provision (0.1 % per 8 h).

  → `EXECUTION_RISK_UNVERIFIED`.
- **Account:** `ACCOUNT_ELIGIBILITY_UNVERIFIED` (France is not named; the account is not checked).

### 4. Are TradingV3's APIs compatible?

**No, not today.**

- The OKX code is USDT-SWAP only:
  - `instType=SWAP` is hardcoded about 54 times;
  - the instrument resolver throws on `_UM_XPERP` ids and maps `BTCUSD` to the inverse swap;
  - the event normalisers drop non-SWAP fills and stops;
  - USDT is assumed in balances, fees, PnL and budgets.
- **Already compatible:**
  - the demo host `eea.okx.com`;
  - `ctVal` sizing;
  - the public X-Perp endpoints (candles, funding) answer.
- **Minimal change, in order:**
  1. a read-only X-Perp metadata and public-data capture (a research script, no adapter);
  2. read-only runtime-check probes (X-Perps visible, position mode, account currency, key permissions);
  3. only after Gate 2 and the owner's go: an OKX product setting (`instType`, resolver, normalisers, USD).

  The execution path is M to L.

### 5. Would twelve months suffice?

**Very likely not.**

- **Observed:** 9 eligible events in 4 months (about 2.2 per month), 6 of them in the last 3.5 months. The history
  is 4 months long, so every extrapolation below is **indicative only**.
- **Distinguish:** listings (about 40 crypto X-Perps a month) ≫ new tokens (30 in 4 months) ≫ H1 events (9) ≥
  signals (H1 has no extra filter: every event is a signal) ≥ executable orders ≥ executed trades.
- **Assumptions:**
  - executable orders lose about 2 in 9 to minimum sizes;
  - executed trades are further limited by the 2-slot, 14-day risk budget (Poisson approximation).

| Scenario (12 months) | H1 events | Executable orders | Executed trades | Months to reach 30 trades |
|---|---:|---:|---:|---:|
| Low (1 per month) | ≈ 12 | ≈ 9 | ≈ 9 | ≈ 41 |
| Recent (2.2 per month) | ≈ 26 | ≈ 21 | ≈ 17 | ≈ 22 |
| High (4 per month) | ≈ 48 | ≈ 37 | ≈ 22 (budget-limited) | ≈ 17 |

- **All three stay below 30 executed trades.** The 2-slot budget caps the high scenario.
- The last events also need 14 days of holding plus the 31-day capture after the end.
- §16 fixes exactly 12 months and forbids extension. **A test long enough to conclude would need a new protocol**
  (S_C redefined to X-Perp events, a longer fixed period). That is not an amendment to make by stretching H1.

### 6. Should the historical Gate 2 be run?

**Conditional GO.**

- **For:**
  - The data is on disk: S_A's OKX tape and S_B's one-minute tape (512 MB). It is cheap.
  - Protocol §15 is already frozen.
  - **A failure closes H1** and saves a prospective programme of about 2 years.
- **Against:** a pass would not prove X-Perp profitability. The venues differ, X-Perp funding is not modelled, and
  the OKX-first subset is the weak one.
- **Conditions:**
  1. Run §15 exactly as frozen, without changing any stop, threshold or horizon.
  2. **Before any read,** freeze amendment A3, which only adds diagnostics:
     - the OKX-first subset reported separately;
     - a funding stress at the X-Perp cap structure (±1 % per 4 h).
  3. Label the result "historical, non-executable".
  4. Run it only after the account check below passes. If the account cannot trade X-Perps, H1 closes
     operationally and Gate 2 is moot.

### 7. The single next step

**The owner runs the read-only account check** (`H1-XPERP-MARKET-ACCESS.md` §3). It takes about 30 minutes, needs
no order and shares no key. It covers:

- X-Perps unlocked for a France-resident account, and the classification;
- position mode and maximum leverage;
- USDC margin;
- a Read-only API key: `account/config` and `account/instruments?instType=FUTURES`;
- X-Perps listed in Demo.

It is the permanent rule's first gate (M1), and its answer decides between closing H1 operationally and the
conditional Gate 2.

## 2. Uncertainties that could change the verdict

- France is not named in OKX's country rollout, and the account is unchecked.
- The behaviour of a triggered stop at a price band, and the funding paid on new X-Perps during the first 14 days.
- The rate of same-day X-Perps for new tokens is a 4-month sample of OKX's choices.
- Withdrawn X-Perps are not fully inventoried.
- The `listTime` field is the only source for most launch dates; announcements cover only 3 X-Perp listings.
