# Paper market effect JSONB order design

## Context

The real Paper campaign persists each requested market effect as PostgreSQL
`JSONB`. PostgreSQL returns object keys in its own deterministic order. The
market effect codec authenticated canonical content correctly, but additionally
required PHP insertion order for the four envelope keys. Recovery therefore
failed on the first durable effect with `paper_market_effect_payload_invalid`.

## Decision

Validate the exact envelope key set independently of key order, matching the
existing `PaperMarketEvent` contract. Keep every type, schema, effect type and
canonical checksum check unchanged. Unknown or missing keys continue to fail
closed.

## Verification

- Reproduce a canonical JSON encode/decode round-trip before codec decode.
- Preserve tamper rejection.
- Resume the same failed campaign state and prove all 12 cells complete.
