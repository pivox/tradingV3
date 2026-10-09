# Public research source complements

Continue the approved ten-pair candle research without changing the collector's frozen acquisition manifest or pretending current specifications are historical observations.

## Deliverable

Implement a small tested `app.backtesting.research.source_complements` module/CLI. It captures (1) the exact public current Binance USD-M exchangeInfo response and normalized research precision assumptions; (2) the missing funding tail from 2026-10-01 inclusive to 2026-10-09T06:00:00Z exclusive for the ten approved symbols. All output is fresh, private, immutable, bounded and outside repositories. This lot is not a trading simulation.

## Required behavior

- Use only fixed public `https://fapi.binance.com/fapi/v1/exchangeInfo` and `/fapi/v1/fundingRate` endpoints, without credentials, redirects or authenticated leverage-bracket requests. Timeouts, response caps, bounded retries and refusal of 403/451 must match the collector's safety posture.
- The exact approved universe and order come from binance_history.SYMBOLS. No arbitrary endpoint or symbol injection. Funding request bounds are explicit UTC milliseconds; endTime sent as exclusive_end-1. Validate ascending unique observations, symbol, finite signed rates, timestamps in range, and positive markPrice when supplied. Preserve millisecond jitter and raw bytes with SHA-256. Pagination advances last timestamp+1 with a bounded page count and detects non-progress/duplicates.
- Treat successful pagination with a terminal short/empty page as completed retrieval, NOT proof of gap-free historical funding. Label coverage `observed_only`; no implicit eight-hour schedule, no missing=zero, and preserve empty/rejected/failed outcomes. The parent will reconcile archive+tail schedules before PnL.
- Capture exchangeInfo once with a 10 MiB cap. Validate all ten unique symbols are USD-M USDT perpetual; extract PRICE_FILTER tickSize, LOT_SIZE stepSize/minQty/maxQty and MIN_NOTIONAL notional from exact raw bytes, not pricePrecision/quantityPrecision. Missing/duplicate/malformed filters fail closed. Preserve decimal strings in evidence and bind retrieval time/source URL/raw SHA.
- Produce `research-instrument-assumptions.v1` matching the actual PHP ResearchInstrumentAssumptions in the research-plan-worker worktree. This manifest may use numeric fields for PHP protocol, but retain the original decimal-string evidence. Explicit CLI inputs provide contract-size, leverage-cap, MMR proxy and liquidation-fee hypotheses; never infer usable MMR from ignored exchangeInfo percentages or attest historical leverage limits. Require leverage<=2 for this campaign. No hidden defaults for unknown instrument specifications.
- Label status `current_metadata_not_historical` and a validity statement containing `not attested`: these current precision/limit observations are hypotheses when replayed in 2023. Every hypothesis and source binding must be hashed. Reuse existing PHP-compatible `app.modern_trading_contracts._canonical_json` for the manifest's `sha256:` canonical hash; test compatibility rather than ordinary json.dumps floats.
- Root must be absolute/private/fresh/outside repos, no symlinks, collisions or chmod of broad/foreign directories. Reuse proven primitives where their contracts fit, but do not modify acquire.py in this lot. No resume claim is needed. Preserve status and any partial evidence on failure, never report complete merely on process exit/EOF.
- Tests use fake HTTP transports for success, paging, invalid rows, truncation, errors, oversized response, raw hash verification, filter extraction, private storage and foreign roots. Coverage gate stays 95%; no exclusions.
- Run focused collector/complement tests, one consolidated review/correction cycle, then merge. Do not fetch actual supplements until parent authorizes the tested command; do not run full-suite PHP bridge tests without explicit isolated DB environment.

## Protocol coordination

PHP assumptions schema currently requires keys `schema_version,source_url,retrieved_at,raw_sha256,validity_statement,assumption_status,symbols,manifest_hash`; symbol rows `symbol,tick_size,quantity_step,min_quantity,max_quantity,min_notional,contract_size,leverage_cap,mmr_proxy_rate,liquidation_fee_rate`. Read the actual final implementation and notify parent about schema changes.
