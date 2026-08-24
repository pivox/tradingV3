# OKX compact retained-trade checkpoint design

## Problem

An OKX reconnect may retain up to 500 recent REST trades while proving overlap
with the durable frontier. A saturated acknowledged-identity ledger already
uses about 7,500 of the canonical checkpoint's 10,000 associative-key budget.
Persisting each trade as a seven-key map can therefore make a valid recovery
fail with `paper_canonical_json_keys_exceeded`.

## Decision

Persist each retained trade row as one canonical JSON string containing either
the legacy seven-element list or the complete modern nine-element REST shape in
this order:

```text
[instId, tradeId, px, sz, side, count, source, ts, seqId]
```

Each string consumes one node and no associative keys. Candle rows already use
an ordered list representation and remain unchanged. Existing seven-field maps
and lists remain readable so an in-progress checkpoint can migrate on its next
write; modern rows retain `count` and the original integer or string `seqId`
without coercion. All new writes use only the string representation.

An external content-addressed blob was rejected for this fix because it adds a
second durability protocol. Merely checking the remaining key budget was also
rejected because it would still terminate otherwise recoverable busy captures.

## Data flow and validation

The live source owns two strict conversions: an exchange REST trade map becomes
the canonical string immediately before persistence, and a retained row becomes
the exact REST map immediately after checkpoint load. Decoding accepts only a
canonical string, a transitional exact seven- or nine-element list, or the
corresponding exact-key map. The normal frontier, identity, decimal, side,
aggregate-count, sequence and timestamp validation remains the ultimate
semantic authority.

Every trade-pagination path uses decoded maps for sorting, overlap checks and
normalization, then compacts the full retained set before writing the next
checkpoint. Pending-event continuation also decodes before comparing its
frontier. Malformed or ambiguous rows fail closed as
`okx_paper_live_checkpoint_invalid` at load, or
`market_data_gap_unresolved` if runtime state cannot be reconstructed.

Before every pagination write, the source encodes the complete candidate
checkpoint, including the acknowledged-identity ledger and all other state.
The full suffix is persisted only when that enclosing representation stays
within the canonical and one-MiB storage budgets. It is never truncated; a
candidate that cannot fit terminates durably as
`market_data_gap_unresolved`. Terminal failures are not swallowed by the
ordinary history-overlap fallback.

## Verification

The saturated-ledger regressions use both a large accepted suffix that fits and
a valid padded suffix that exceeds only the remaining enclosing budget. They
prove compact persistence in the first case and durable fail-closed behavior in
the second. Existing
restart, pagination, conflict and replay-equality tests cover decoding and
continuity. The full OKX Live suite and targeted PHPStan remain required before
the PR can merge.
