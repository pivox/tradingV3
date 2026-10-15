# Research eligibility gates M1 → M5 (permanent rule, from 2026-10-14)

**Accessible market → tradable instrument → compatible data → economic hypothesis → rigorous backtest →
independent validation.**

Every new TradingV3 campaign passes these gates **in order**. A campaign blocked at M1 or M2 consumes no
backtesting resource. A gate is never relaxed retroactively, and the existing scientific gates (pre-registration,
controls, holdouts) are unchanged.

## Gate M1 — Market authorised and accessible

All required, documented with sources and the date of verification:

- **The provider is authorised for the product in France.**
  - MiCA CASP (spot) and/or MiFID investment firm (derivatives), passported into France.
  - Checked on the ESMA and AMF registers, or the home regulator's register.
- **The terms accept French residents** for the product.
- **The owner's account** has the product enabled (appropriateness test passed, client classification), checked
  in the account. Otherwise the gate is `ACCOUNT_ELIGIBILITY_UNVERIFIED` and **the gate is not passed**.
- **API trading** is permitted for that account and product.
- **No geographic workaround**, ever (VPN, false residence, foreign entity).

Status today (`FRANCE-EXCHANGE-ELIGIBILITY.md`):

| Venue and product | M1 status |
|---|---|
| OKX Europe X-Perps | Conditional, account unverified |
| Kraken EEA, Coinbase EEA | Conditional |
| OKX Global USDT swaps | Not available |
| Hyperliquid | Not retained |
| Binance | Blocked |

## Gate M2 — Instruments effectively tradable

- Precise instruments, with their real ids and specifications recorded in an **InstrumentSpec**
  (`TRADINGV3-EXCHANGE-COMPATIBILITY.md` §3).
- The minimum order and lot are compatible with the reference portfolio: 500 USDT, 1×, 0.5 % per trade, 1 % in
  total, at the stop distances the hypothesis needs.
- Liquidity is acceptable for the holding period, with a threshold declared in advance (e.g. ≥ 1 M USD a day).
- Fees and funding rules are known.
- The instrument's risks are manageable: stops, price limits, expiry, liquidation rules.

## Gate M3 — Compatible data

- **History of the same products**, or a rigorous, declared justification of transferability.
  - What is the same: the same underlying.
  - What differs: book, funding, liquidity, and how they are modelled.
- Sufficient coverage, with funding and costs available.
- **Independent validation data:** a never-read period on the target product, or a prospective test.
- **The data licence allows the intended use.** Binance Vision data, for example, is research-only (CC BY-NC-SA,
  no live-trading use).
- Quality checked: gaps, delistings, survivorship.

## Gate M4 — Scientific hypothesis

- A plausible economic mechanism.
- **A real difference from the invalidated families** (`../reports/research-strategy-landscape-2026-10.md`).
- A comparable control and a falsifiable test.
- **A pre-registration frozen (sha256) before any read.**

## Gate M5 — Exploitability and validation

- Positive net expectancy against a pertinent control.
- A realistic portfolio: concurrent positions, legal sizes, the 500-USDT budget.
- Stops and extreme losses modelled (gaps, squeezes, liquidation).
- Pessimistic costs; stability by period and instrument; not dependent on the top trades.
- Validation outside training, then **forward or Paper on new data of the target product**.
- A pass never authorises real trading by itself. That is the owner's separate decision, with demo flags first.

## Application to current work

| Work | Status |
|---|---|
| **H1** | **Closed** (2026-10-15). The historical Gate 2 (amendment A3) ran once: `GATE2_FAIL` (#508, `H1-GATE2-RESULT.md`); under v1 §17 H1 is closed. The S_B `GATE1_PASS` stays a scientific result. X-Perp audit `H1_XPERP_CONDITIONAL` (moot since the Gate 2 fail; API unverified). See `H1-NEXT-DECISION.md`. |
| **H2** | **Closed** (2026-10-15). Gate 1 pre-registered (#510), amended (A1) and run once: `FAIL`, not due to G4 alone; the owner recorded `H2_GATE1_FAIL_CLOSED` (#511, open at the time of writing). Nothing is authorised: no Gate 2, setup or trading. See `RESEARCH-CAMPAIGN-STATUS.md`. |
| **`fwd-okx-r1`** (running, frozen) | Tests OKX global USDT swaps, a product a French account cannot trade. It is left untouched; its result informs the underlying behaviour only. |
