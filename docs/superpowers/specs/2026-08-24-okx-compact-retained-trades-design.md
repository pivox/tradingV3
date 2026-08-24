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

## Live r19 healthy-stop finding

r19 ran on schema v6 without reconnect or resync and reached 3,465 durable
events. Its 450-second timer fired in the same event-loop iteration as an
already-admitted WebSocket frame. The old stop precondition required both
durable queues to be empty immediately, so this transient and recoverable state
was finalized incomplete as `okx_paper_public_healthy_stop_invalid`.

An operator-stop request now first invalidates subsequent socket callback
generations and cancels heartbeat timers. Any frame already admitted and
persisted is still normalized, appended and acknowledged. The source writes the
`stopping` transition only after both queues and the active frame are empty.
Socket freshness, subscription readiness, reconnect/resync absence and pending
event guards remain fail-closed both before the request and at the durable
transition. r19 remains immutable, incomplete and non-certifiable.

r20 reached 3,442 durable events with no reconnect or resync, then exposed the
second half of the same timer boundary. A socket may be just beyond its
20-second idle threshold while an already-armed heartbeat is waiting to run or
its pong deadline is still open. That socket is not yet proven stale. The stop
request now waits for the existing heartbeat proof before quiescing admission.
A valid pong or market frame permits the normal drain and stop transition; pong
timeout or a reconnect still fails the requested healthy stop. r20 remains
immutable, incomplete and non-certifiable.

r21 reached 3,420 durable events without reconnect or resync. While the source
waited for the quiet business socket's liveness proof, the active public socket
could still admit a burst and exhausted the bounded 256-frame queue. The
operator request is now also the cutoff for new market-data admission. Frames
received afterward are decoded and may refresh socket liveness, but are not
queued or persisted. Once both sockets are proven fresh, callback generations
are quiesced and only the frames durable before the request are drained. A
300-frame burst regression pins this boundary. r21 remains immutable,
incomplete and non-certifiable.

## Live r22 completion evidence

r22 completed its 450-second public mainnet capture on schema v6 with execution
forced disabled. It persisted 3,454 events across the required candle, funding,
instrument, trade, book, snapshot and connection-state channels. The final
checkpoint is `complete`, connection epoch is 1, reconnect attempt is zero and
neither symbol entered resync.

`PaperDatasetVerifier::verifyForBaseline()` independently scanned the immutable
dataset and accepted its network provenance, event count, channel set,
timestamps, identities and checksum. A separate SHA-256 recalculation matched
the manifest value
`9b8299caad6573e6a857e892cf1a2092bcc4f871df99246c33e3b360671168ef`.
This short capture proves the hardened acquisition and healthy-stop boundary;
it does not claim 24-hour representativeness or 50 certified trades per cell.
