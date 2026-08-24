# Paper dataset chunked line-reader design

## Problem

`PaperDatasetLineReader` passes the full 6,491,457-byte bound to `fgets()` for
every NDJSON event. PHP may reserve that entire buffer even for a small line.
During a long capture, the recorder's durable identity indexes legitimately use
most of the supported 128 MiB process budget, so this one-shot reservation can
terminate the process before the live handoff.

## Decision

Read with `fgets()` in chunks of at most 65,536 payload bytes. Accumulate chunks
only until the first newline, EOF or the unchanged
`MAX_CANONICAL_EVENT_LINE_BYTES` limit. Return `false` only when EOF occurs
before any byte is read. A partial EOF, a line reaching the maximum without a
newline, or any oversized line keeps the caller-provided fail-closed error.

Increasing `memory_limit` was rejected because it leaves allocation proportional
to the maximum contract instead of the actual event. Reworking recorder indexes
was rejected as a separate architecture change.

## Verification

A filesystem spy records every requested `fgets()` length. A multi-chunk valid
line must round-trip exactly and no request may exceed 65,537 bytes (payload plus
the PHP length sentinel). Existing exact-limit, oversized and unterminated-line
tests preserve boundary semantics. Recorder/verifier suites and targeted
PHPStan are required before merge.
