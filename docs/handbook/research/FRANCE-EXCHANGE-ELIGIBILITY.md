# Exchange eligibility for a French retail resident (verified 2026-10-14)

Scope: which venues and products TradingV3 may use for **real** trading by a retail client resident in France.
Sources are official where available; press is marked as such.

Three levels are always kept apart:

1. technical access (a site or an API answers);
2. contractual eligibility (the platform's terms accept French residents for the product);
3. regulatory permission (the provider is authorised to offer the product to French retail).

Whatever needs a logged-in account is marked **`ACCOUNT_ELIGIBILITY_UNVERIFIED`**. No account, key or order was
used. No geographic workaround (VPN, false residence) is considered, ever.

## 1. Legal framework (France, EU)

| Rule | What it means for TradingV3 | Source |
|---|---|---|
| **MiCA** (Reg. (EU) 2023/1114) covers crypto-assets and crypto-asset service providers (CASPs), **not financial instruments** (Art. 2(4)(a)) | A MiCA (CASP) licence covers **spot** only, never perpetuals or futures | https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32023R1114 |
| **End of the French transitional period, 1 July 2026** | Platforms registered only as PSAN (France's pre-MiCA registration) must stop serving France until they are MiCA-authorised (penalties: CMF L.54-10-4, L.572-23; blacklists, website blocking) | AMF, 5 Feb 2026: https://www.amf-france.org/fr/actualites-publications/actualites/lamf-rappelle-que-la-periode-transitoire-pour-les-psan-pour-continuer-de-fournir-des-services-sur |
| **Crypto derivatives are MiFID II financial instruments** | Only an investment firm (MiFID licence, passported into France) may offer them; conduct rules, EMIR and the advertising ban apply | AMF, 22 Feb 2018: https://www.amf-france.org/en/news-publications/news-releases/amf-news-releases/amf-considers-offer-cryptocurrency-derivatives-requires-authorisation-and-it-prohibited-advertise |
| **ESMA statement, 24 Feb 2026** (ESMA35-243228190-8024) | Products "marketed as perpetual futures" are **likely in the scope of the CFD rules** whatever their name. An instrument that legally qualifies as a future is outside the CFD definition, case by case. Appropriateness assessment, PRIIPs KID (key information document) and a narrow target market are required. | https://www.esma.europa.eu/sites/default/files/2026-02/ESMA35-243228190-8024_-_Public_statement_on_derivatives_in_scope_of_the_CFD_product_intervention_measures.pdf |
| **CFD product intervention** (ESMA 2018/796; AMF permanent decision, 1 Aug 2019) | For retail crypto CFDs: **2:1 leverage maximum**, margin close-out, negative balance protection, no incentives | https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32018X0601(02) ; https://www.amf-france.org/fr/actualites-publications/communiques/communiques-de-lamf/options-binaires-et-cfd-lamf-adopte-des-mesures-dintervention-lechelle-nationale |
| **Appropriateness test** (MiFID) | Mandatory before derivatives, which are complex products | ESMA statement above |

**Consequence for TradingV3.** Its reference leverage is **1×**, so every leverage cap (2:1 or 10×) is
compatible. The binding constraints are the provider's authorisation, the appropriateness test and the product's
own specifications.

## 2. Venues

### OKX: through OKX Europe only

- **OKX Global** (okx.com) does not list France as restricted, but its terms of service route EEA residents to
  local terms and EEA entities. https://www.okx.com/help/terms-of-service (updated 17 Sep 2026);
  https://www.okx.com/help/risk-compliance-disclosure (updated 8 Jul 2026).
- **OKX Europe Limited** (Malta) is a **MiCA CASP authorised by the MFSA (Malta) on 27/01/2025, with France in its
  passport**. It covers spot, custody and order execution. Source: ESMA interim register
  https://www.esma.europa.eu/sites/default/files/2024-12/CASPS.csv. It also appears on the AMF white list as
  "CASP | Licensed MICA" (as OKCoin Europe Ltd).
- **OKX Europe Markets Limited** (Malta, C 95813) is a **MiFID II investment firm** (MFSA, authorisation "OEML").
  It provides the EEA derivatives, the **X-Perps**.
  https://www.okx.com/en-eu/help/okx-europe-market-ltd-oem-terms-of-service (updated 2 Oct 2026). Not checked
  directly on the MFSA register.
- **Former French PSAN** E2023-106 (OKX France Technology SAS): removed at the company's request on
  **28 Jul 2025**. https://www.amf-france.org/sites/institutionnel/files/private/2025-07/decision_de_radiation_okx.pdf
- **Products for French retail:**
  - spot on EUR / USDC pairs (USDT pairs withdrawn in the EEA, 2024 press report);
  - **X-Perps**: futures with a 5-year expiry and 8-hour funding, settled in USD (margin in USD / USDC / USDG /
    BTC / ETH / SOL), up to **10×** for retail, shorting allowed, negative balance protection, **mandatory
    appropriateness test**.
  - https://www.okx.com/en-us/help/okx-x-perps-eea-what-are-expiry-perps ;
    https://www.okx.com/en-us/learn/okx-x-perps-eea-appropriateness-assessment ; https://www.okx.com/en-eu/x-perps
- **Classic USDT swaps** (`BTC-USDT-SWAP`) are **not offered to EEA retail** according to OKX's own product pages
  (X-Perps as the EEA product). No page says so word for word, and the public endpoints return the global list on
  every host. **ACCOUNT_ELIGIBILITY_UNVERIFIED**, treated as unavailable.
- **API.** The same v5 API is allowed for OKX Europe accounts (the OEM terms mention access "through software,
  APIs"). EEA host: `https://eea.okx.com`. Docs: https://my.okx.com/docs-v5/en/
- **Verdict: CONDITIONAL.** OKX Europe is authorised for spot (MiCA) and X-Perps (MiFID); the French account and
  its derivatives access depend on the appropriateness test and the app (ACCOUNT_ELIGIBILITY_UNVERIFIED).
  **Classic swaps: NOT AVAILABLE.**

### Hyperliquid: not retained for real trading

- **Contract.** The terms exclude the US, Ontario and sanctioned territories; **France and the EU are not named**.
  §1.9 puts compliance with local law, "including … laws governing leveraged or derivative trading", on the user.
  https://app.hyperliquid.xyz/terms (updated 15 Jun 2026)
- **Regulation.** "Neither the Interface nor Hyperliquid are licensed, approved, authorized … in any
  jurisdiction" (terms §1.3).
  - Absent from the ESMA MiCA register (CASPS.csv, 7 Oct 2026) and from the AMF white list (fetched 2026-10-14).
  - No MiFID authorisation, although perpetuals are MiFID instruments.
  - UK FCA warning, unauthorised firm: https://www.fca.org.uk/news/warnings/hyperliquid (21 May 2026).
  - No AMF warning found (the AMF list is not exhaustive).
- **Technical.** The public API answers. Products: USDC perpetuals with hourly funding, shorting, minimum order
  $10, fees 0.015 % maker / 0.045 % taker.
- **Verdict: NOT RETAINED for execution.** It is technically and contractually reachable but offered by an entity
  with no EU authorisation for derivatives, so a French retail client has none of the MiFID protections. Whether a
  French resident may lawfully use it is a legal question this review does not settle; TradingV3 does not build its
  execution target on it. Research use of its public data stays possible.

### Binance: blocked

- **Former French PSAN** E2022-037 (Binance France SAS, 4 May 2022). **No MiCA authorisation** in any member state:
  absent from the ESMA register (7 Oct 2026) and from the current AMF white list.
- **Services in France stopped on 1 July 2026.** No new clients; trading, futures included, is closed. This comes
  from **press** reports quoting Binance's emails (Euronews, 25 Jun 2026; CoinDesk, 26 Jun and 18 Sep 2026); no
  official Binance notice for France was found.
- **Derivatives for French retail: unavailable.** No MiFID authorisation; ACCOUNT_ELIGIBILITY_UNVERIFIED for any
  individual account.
- **Public data.** data.binance.vision is reachable, but its **"Binance Vision Dataset Terms" (26 Aug 2026)**
  license the data under **CC BY-NC-SA 4.0**. §4.1 allows "algorithmic historical backtesting for purely personal
  non-production research". **§4.2 forbids use "for live proprietary trading execution, automated commercial order
  generation, or signal distribution".**
  https://github.com/binance/binance-public-data/blob/master/TERMS_AND_CONDITIONS.md
- **Verdict: BLOCKED for execution.** Binance data are usable **only for personal non-production research**. A
  strategy whose rules would drive live trading must be validated on data whose licence allows it: the target
  venue's own data.

### Other venues (verified alternatives)

| Venue | Entity and licence | Derivatives for French retail | Verdict |
|---|---|---|---|
| **Kraken (EEA)** | Payward Europe Digital Solutions (CY) Ltd, **CySEC licence 342/17**: https://www.cysec.gov.cy/en-GB/entities/investment-firms/cypriot/ | Since **20 May 2025**: perpetuals and fixed-maturity contracts; margined and settled in USD (EUR / GBP collateral); up to 10×; hourly funding (±0.5 %); minimum 0.0001 BTC; fees 0.02 % / 0.05 %; API. France is in its EEA list. https://support.kraken.com/articles/perpetual-contract-specifications-for-clients-in-the-eea (30 Sep 2026); https://support.kraken.com/articles/derivatives-offerings-for-eea-clients | **CONDITIONAL**, the best alternative. EEA history depth unverified. |
| **Coinbase (EEA)** | Coinbase Financial Services Europe Ltd, **CySEC 374/19** | Since **9 Mar 2026** (France included, press): perpetual-style futures with a 5-year expiry and hourly funding; 10× on BTC / ETH; minimum 10 USDC (API docs) | **CONDITIONAL**, second alternative; EEA API scope and fees unverified |
| Bybit EU | MiCA (Austria), spot / margin | MiFID application pending | Not for derivatives |
| Bitget | MiCA pending on 1 Jul 2026 (third-party sources) | — | Not retained |
| Crypto.com EU, Bitpanda, Deribit | — | No crypto perpetual for French retail found | Not retained |

## 3. Uncertainties that only the owner's account can settle

- Whether the French OKX Europe account can pass the appropriateness test and open X-Perps, and with which
  leverage and fee tier.
- Whether API keys can trade X-Perps.
- Whether the OKX demo environment (`eea.okx.com` with simulated trading, used by TradingV3) lists X-Perps.
- The same questions for Kraken EEA and Coinbase EEA.
- The legal reading of 10× crypto "futures" for retail under the ESMA statement of February 2026. Platforms could
  move to 2:1 or restrict access. This is harmless at TradingV3's 1× leverage.
