# Paper canonical funding source design

## Problem

The modern Paper cost contract requires an observed funding rate from the venue schedule. Treating an unknown rate as zero would make net risk and PnL evidence non-representative. Existing OKX Paper capture authenticates candles, trades, books and instrument metadata, but does not capture the public funding schedule.

## Decision

Add an OKX-only public funding evidence channel and an unwired canonical source.

The live capture fetches `GET /api/v5/public/funding-rate` once per symbol during each resumable warmup epoch. The REST client retains only the required public response fields. The normalizer emits a strict `paper-funding-rate.v1` event with:

- the current funding rate and venue observation timestamp (the event timestamp remains the receipt-time snapshot boundary);
- the current and following settlement timestamps;
- the interval derived exactly from those settlement timestamps;
- method, formula and settlement-state provenance;
- source epoch and public REST origin.

The authenticated dataset verifier and checkpoint frontier validate the event shape and identity. `PaperCanonicalFundingSource` then binds the exact current replay trigger and cell scope, applies exchange/receipt no-lookahead, selects the latest available rate, verifies the configured funding interval exactly, and returns its rate, observation time and event-ID lineage.

The closed OKX live checkpoint schema advances to v3 because the funding stream adds two mandatory frontier slots. Older in-progress checkpoints fail closed instead of being silently interpreted under a changed v2 shape.

## Invariants

- only OKX mainnet public GET data is accepted;
- no API key, private endpoint or trading write is introduced;
- the native instrument and normalized symbol must agree;
- rates are canonical signed decimals strictly between -1 and 1;
- source observation and settlement timestamps are canonical milliseconds;
- `funding_time < next_funding_time` and their difference must be a whole positive number of seconds;
- replay availability is ordered by receipt time, observation time and event identity;
- events not yet observable at the replay clock are ignored;
- a rate older than its declared settlement interval is unavailable rather than silently reused;
- a stale or forged trigger, malformed payload, duplicate identity or policy interval mismatch fails closed;
- missing evidence returns no canonical funding snapshot, never an implicit zero.

## Scope

This slice captures, authenticates, replays and sources OKX public funding evidence. It does not assemble the complete execution-cost snapshot, source fees/slippage, construct the private portfolio snapshot, or enable modern Paper execution.
