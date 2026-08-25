# Paper durable capture batches

## Goal

Keep public Paper capture ahead of bursty venue traffic without weakening its
crash-safety or deterministic replay contract. Hyperliquid trade frames are
checkpointed in bounded groups, but the dataset recorder still
publishes an append intent, flushes, and authenticates the complete file for
every individual trade.

## Chosen design

Add an optional batch-boundary capability to live sources. For the current
event it reports how many events, including that event, remain protected by the
same durable source checkpoint. Hyperliquid exposes this capability only for
its compact trade continuations; every other event has a boundary of one.

The public capture loop may acknowledge intermediate events before writing the
dataset only while that capability reports that the durable boundary has not
been reached. At the final event it appends the complete buffered group with one
atomic recorder operation, then acknowledges the boundary event. Sources that
do not implement the capability keep the existing append-before-ack behavior.

`PaperDatasetRecorder::appendBatch()` accepts a non-empty list bounded to 256
events. Hyperliquid uses the same bound for compact trade chunks; the resulting
checkpoint remains below its independently enforced one-megabyte limit. It
validates identities, ordering, gaps, manifest facts, and canonical size for the
whole list before mutation. One versioned append intent authenticates the
original file prefix and concatenated NDJSON suffix. The suffix is flushed once,
verified once, and then applied to in-memory facts in event order. Existing v1
single-event intents remain readable; new batch intents use v2.

## Crash behavior

- Before the dataset batch is durable, the source checkpoint still points to
  the first event, so restart replays the whole group.
- After the dataset batch is durable but before the final source acknowledge,
  restart also replays the group; the recorder authenticates every identity and
  returns `REPLAYED` for the batch.
- A partial suffix is truncated to the authenticated original boundary during
  intent recovery. A complete suffix is retained.
- Non-batched events preserve the current per-event durability boundary.

The observer and stop controller run only after the complete batch is durable
and its final source acknowledgement succeeds.

## Rejected alternatives

- Larger in-memory websocket buffers only postpone exhaustion during a 24-hour
  capture.
- Removing durability flushes weakens the certification evidence.
- A raw-frame journal with asynchronous projection is viable future work, but
  introduces a second dataset format and recovery pipeline beyond this fix.

## Verification

Tests cover ordinary sources retaining append-before-ack semantics, an
eight-trade Hyperliquid batch using one recorder commit, replay after each crash
window, partial-intent recovery, ordering and identity conflicts, and observer
ordering. The final gate is the complete Hyperliquid suite, targeted PHPStan,
and a five-minute credential-free mainnet smoke with one source epoch, healthy
pongs, no sequence gaps, and a complete authenticated manifest.
