# OKX reconnect stop regression

## Context

The bounded reconnect backlog introduced at `023c3712` deliberately discards
book frames that cannot authorize the current recovery. The end-to-end replay
fixture still assumed that the ETH book frame received before BTC recovery
would remain available. It therefore requested a healthy stop while ETH was
waiting for a fresh websocket book, which must remain a terminal fail-closed
condition.

The same path exposed a second issue: `reconnectRecoveryFlow()` caught the
terminal healthy-stop exception and attempted to fail the already-failed
checkpoint again as `market_data_gap_unresolved`. The checkpoint store correctly
rejected that reason change, masking the authoritative failure as
`okx_paper_live_checkpoint_invalid`.

## Design

- Keep healthy completion forbidden while reconnect continuity is unresolved.
- Teach the replay-equality fixture to deliver a fresh ETH book frame linking to
  the REST snapshot before it requests the healthy stop.
- If book recovery has already stopped the source terminally, propagate that
  first exception without a second checkpoint transition.
- Retain the existing translation of non-terminal book-recovery failures to
  `market_data_gap_unresolved` and the special identity-conflict handling.

## Verification

- A focused regression must prove that a stop requested during the book-overlap
  wait reports `okx_paper_public_healthy_stop_invalid` in both the exception and
  checkpoint.
- The disconnect/restart/replay equality scenario must complete after receiving
  the fresh ETH book authority.
- The complete Paper capture test slice must pass before a public 24-hour launch.

No private exchange client, credential use, Paper execution enablement, strategy
tuning, or mainnet write is introduced.
