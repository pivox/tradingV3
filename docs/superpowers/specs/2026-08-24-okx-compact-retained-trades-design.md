# OKX compact retained-trade checkpoint design

## Problem

An OKX reconnect may retain up to 500 recent REST trades while proving overlap
with the durable frontier. A saturated acknowledged-identity ledger already
uses about 7,500 of the canonical checkpoint's 10,000 associative-key budget.
Persisting each trade as a seven-key map can therefore make a valid recovery
fail with `paper_canonical_json_keys_exceeded`.

## Decision

Persist each retained trade row as one canonical JSON string containing an
exact seven-element list in this order:

```text
[instId, tradeId, px, sz, side, source, ts]
```

Each string consumes one node and no associative keys, so even the 3,500-row
contract maximum remains below both structural budgets. Candle rows already use
an ordered list representation and remain unchanged. Existing associative maps
and seven-element lists remain readable so an in-progress checkpoint can
migrate on its next write; all new writes use only the string representation.

An external content-addressed blob was rejected for this fix because it adds a
second durability protocol. Merely checking the remaining key budget was also
rejected because it would still terminate otherwise recoverable busy captures.

## Data flow and validation

The live source owns two strict conversions: an exchange REST trade map becomes
the canonical string immediately before persistence, and a retained row becomes
the exact REST map immediately after checkpoint load. Decoding accepts only the
canonical string, the transitional seven-element list or the existing exact
seven-key map. The normal frontier, identity, decimal, side and timestamp
validation remains the ultimate semantic authority.

Every trade-pagination path uses decoded maps for sorting, overlap checks and
normalization, then compacts the full retained set before writing the next
checkpoint. Pending-event continuation also decodes before comparing its
frontier. Malformed or ambiguous rows fail closed as
`okx_paper_live_checkpoint_invalid` at load, or
`market_data_gap_unresolved` if runtime state cannot be reconstructed.

## Verification

The saturated-ledger regression uses a large accepted suffix, proves the
checkpoint stays canonical, and asserts that persisted rows are compact. Existing
restart, pagination, conflict and replay-equality tests cover decoding and
continuity. The full OKX Live suite and targeted PHPStan remain required before
the PR can merge.
