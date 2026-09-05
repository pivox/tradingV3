# Paper public capture resilience

## Context

The failed 24-hour captures have two distinct, evidenced causes. OKX reconnected but exhausted a fixed 50-page/5,500-row overlap budget before reaching the last durable trade frontier. Hyperliquid exhausted six reconnect attempts after a public-network interruption. macOS sleep and disk exhaustion were excluded for those failures.

Hyperliquid exposes candle history but no credential-free, certifiable public-trade history. A live trade gap therefore cannot be reconstructed or certified. OKX does expose paginated public-trade history and can prove exact overlap.

## Decision

Keep certification fail-closed and address each venue according to its available evidence.

For OKX, widen the finite recovery envelope to 250 history pages and 25,500 retained rows, enlarge the checkpoint ceiling to 4 MiB, and allow 15 minutes for one exact-overlap resync. These limits cover the observed 17,284-trade recovery suffix with margin. Recovery still fails if the exact durable frontier is not found, the deadline expires, the checkpoint exceeds its byte ceiling, pagination stops progressing, or any identity conflicts.

For Hyperliquid, do not restore continuity after a disconnect and do not stitch datasets. Add an operational supervisor that starts each capture in an isolated subprocess and, after a terminal failure, starts a fresh uniquely named dataset. A bounded `--attempts` option prevents an accidental infinite disk-consuming loop. A successful attempt exits immediately. Every failed attempt remains terminal and excluded from certification.

The supervisor accepts one venue, a canonical dataset prefix, the capture duration, and the maximum number of attempts. It emits only a small canonical JSON summary and never exposes local paths or nested exceptions. Dataset IDs use the prefix plus a zero-padded attempt number and retain the required `-mainnet` suffix.

macOS sleep prevention remains an invocation concern: the documented launch wraps the supervisor in `caffeinate`. Detachment from the interactive Codex terminal is also documented; the application itself does not mutate OS startup configuration.

## Data flow

1. The supervisor validates all options before creating a subprocess.
2. It invokes the existing single-attempt command with a derived dataset ID.
3. Exit code zero ends the supervisor successfully.
4. A non-zero exit starts the next fresh dataset until the configured attempt bound is reached.
5. Exhaustion returns failure with attempt counts only.

The existing single-attempt command remains unchanged and is the sole writer of dataset events and manifests.

## Verification

- Policy tests pin the expanded OKX limits.
- An OKX recovery test proves that a frontier beyond the former 50-page bound can recover exactly.
- Supervisor command tests prove retry-to-success, bounded exhaustion, unique canonical IDs, argument validation, and redacted output.
- Existing OKX, Hyperliquid, capture, checkpoint, replay-equality, static-analysis, and coding-standard suites must remain green.
- A live relaunch uses `PAPER_EXECUTION_ENABLED=0`; no authenticated or mainnet execution endpoint is introduced.

## Non-goals

- No relaxation of dataset certification.
- No Hyperliquid trade-gap backfill or cross-source stitching.
- No deletion of failed datasets.
- No mainnet trading writes.
