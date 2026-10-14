# H1 (post-listing drift) — executability review for a French account (2026-10-14)

**Practical decision: `H1_EXECUTION_MARKET_UNVERIFIED`.** It concerns execution only; the scientific verdict is
unchanged.

The scientific record stays as frozen: protocol v1, A1, A2 and `H1-SA-SCREEN-RESULT.md` (PR #502). The S_A screen
verdict is **`S_A_SCREEN_PASS`**:

- 83 events;
- mean paired surplus −11.4 % at 14 days;
- absolute log return −16.6 %;
- insufficient time stability (positive in 2023–24, negative in 2025–26);
- the risk-normalised test is not conclusive with 2-month blocks;
- strong squeeze exposure (P(MAE > +50 %) = 28 %);
- no decisive validation.

Nothing here downloads S_B or reruns Gate 1.

## 1. Where H1's events came from, and whether they are tradable from France

| Data set | Product | Tradable by a French retail account? |
|---|---|---|
| S_A (OKX archive) | OKX Global USDT swaps (`*-USDT-SWAP`) | **No**: EEA retail is routed to X-Perps (`FRANCE-EXCHANGE-ELIGIBILITY.md`) |
| S_B (Binance, not downloaded) | Binance USDⓈ-M perpetuals | **No**: Binance has no MiCA authorisation, French services stopped on 2026-07-01, and the dataset licence forbids live-trading use |
| S_C (prospective, protocol §16) | "OKX and Binance listings" | Must be redefined: only a venue tradable from France counts |

**H1's measured effect concerns the price behaviour of new tokens on products that a French account cannot
short.** The underlying tokens are the same everywhere, but the instrument, funding and liquidity are not.

## 2. Do new listings reach a tradable market?

OKX Europe **X-Perps** (MiFID, shortable, 5-year expiry, 8 h funding). Metadata of 2026-10-14:

- **160 crypto X-Perps.**
  - Launched 2026-03-30; listings per month: 2 / 8 / 9 / 19 / 28 / 42 / 46 (March to September 2026).
  - Most are established tokens being added, not new tokens.
- **New tokens.** Of the 203 global USDT swaps listed after 2026-04-15, **29** also have an X-Perp:
  - 10 listed as X-Perps **the same day or within 1 day**: O, GRAM, NES, VVV, AEON, GRVT, KII, XDP, CT, QUANT;
  - about 17 within 2 days;
  - the others after 4 to 119 days.
- **Rhythm.** Since June 2026, about **4 new tokens per month** reach an X-Perp within 2 days of their global
  listing. That is a usable event stream for a prospective test (about 50 per year), if it continues.
- **Shorting:** allowed.
- **Minimum orders:** median 0.85 USD; one contract above 14.65 USD. Compatible with 500 USDT at stops ≤ 15 %. With
  H1's 3σ stop (15–60 %) the allowed notional falls to about 4 USD at 60 %, and some contracts then become
  unexecutable.
- **Liquidity.** Several of the new-token X-Perps trade 1–1.5 M USD per day (CT, PONS). Many are thinner.
  Squeeze moves of +50 % to +200 % on thin books make the stop simulation the weakest point.
- **History.** None before 2026-03-30, and only weeks for most new tokens. **A historical test of H1 on X-Perps is
  impossible.** Only a prospective one is.

## 3. What remains unverified (why "UNVERIFIED", not "IDENTIFIED")

- The owner's French OKX Europe account: appropriateness test passed, X-Perps enabled, API keys allowed to trade
  them, effective leverage and fees (ACCOUNT_ELIGIBILITY_UNVERIFIED).
- Whether the demo environment lists X-Perps, which would allow a Paper / demo forward test.
- **Product differences that matter for H1.**
  - Funding is paid every 8 h with a cap of ±0.375 %. Crowded shorts on new tokens may pay that cap repeatedly.
  - X-Perps track the price through funding and an index; how they differ from the global swap in the first days
    of a listing is unknown.
  - Price limits and the new-listing rules on X-Perps are unknown.
- **The stop.** It can only be tested credibly on X-Perp data, captured live (books) or from X-Perp 1-minute
  candles going forward.

## 4. Recommendation for H1

1. **Do not download S_B or run the Binance Gate 1.** Binance is not a target market, and its data licence
   forbids live-trading use. A Binance-only decision cannot justify a French strategy.
2. **Do not close H1 scientifically.** Its screen result stands as `S_A_SCREEN_PASS`, with its weaknesses.
3. **Pause H1 operationally** until gate M1 is checked on the owner's account: OKX Europe X-Perps open and
   API-tradable.
4. If M1 passes, **H1's only valid continuation is prospective on X-Perps.** That means a new pre-registration
   (amendment to §16): every new X-Perp of a new token, the same 14-day horizon and the same −5 % threshold, the
   squeeze and funding as measured on X-Perps, 12 calendar months, technical monitoring only. This needs the
   owner's explicit go.
5. **If M1 fails** (no X-Perp access for the account), recommend the **operational closure of H1**: no
   executable market in the current scope.

Even then, H1 stays a difficult short. Its effect was unstable in time and carried a heavy squeeze tail on the
historical set.
