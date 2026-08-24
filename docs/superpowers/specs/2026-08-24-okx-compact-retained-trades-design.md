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

## Live r16 finding and frontier schema v6

Capture `first-baseline-okx-r16-20260824-mainnet` passed warm-up and reached
streaming, then stopped incomplete after 3,556 events on
`market_event_identity_conflict`. Read-only comparison with the public OKX
history endpoint proved that a WebSocket `trades` row may aggregate several
fills under the final `tradeId`, while the REST row for that same identity
contains one constituent size. Price, side, source and exchange timestamp were
identical; only `sz` differed. This is a valid cross-transport representation,
not a market identity conflict.

Checkpoint schema v6 therefore stores two frontier digests. The
`canonical_digest` still includes the complete validated trade size and remains
the fail-closed comparison inside one origin. The `overlap_digest` excludes only
`size_contracts` for public trades and is used only across REST/WebSocket
origins. Candles, books and control events have identical canonical and overlap
digests. Observed identities are partitioned by their actual `rest` or `ws`
origin. The bounded durable history also retains the canonical digest for each
origin after it is first observed, using compact four-element entries:

```text
[natural_identity_sha256, overlap_digest, rest_canonical_digest_or_reserved, ws_canonical_digest_or_reserved]
```

Each missing-origin slot is a fixed 64-byte non-digest sentinel, so replacing it
after a proven opposite-origin overlap never grows the checkpoint beyond the
budget already reserved. The canonical digest is persisted before continuing,
so a restart cannot weaken later same-origin comparisons. Schema-v5 and earlier
checkpoints are rejected instead of being interpreted with the new history
semantics. A new capture must start with schema v6; the incomplete r16 through
r18 datasets remain immutable and non-certifiable.

## Live r17 memory finding

r17 reached schema-v4 streaming and 3,496 durable events, then the 128-MiB
process exhausted memory while the store rebuilt a 737,653-byte checkpoint
string solely to verify that its durable hash had not changed before a queue
write. The checkpoint was already held in decoded and newly encoded forms at
that point. The immutability check now hashes the pinned file incrementally in
8-KiB chunks while retaining the same size, ownership, type, identity and
before/after metadata checks. Full content loading remains limited to actual
checkpoint restore and reconciliation. A saturated checkpoint regression pins
both the resulting SHA-256 and a sub-256-KiB incremental memory delta.

r18 passed the former memory failure point on schema v5 and reached 3,581
durable events. A subsequent real reconnect could not prove bounded market-data
continuity and therefore finalized correctly as incomplete with
`market_data_gap_unresolved`; it is not certification evidence.
