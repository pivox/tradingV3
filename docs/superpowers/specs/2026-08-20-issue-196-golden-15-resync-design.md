# #196 Golden scenario 15 snapshot-resync design

## Scope

Promote `websocket_disconnect_resync` from `partial` to `executable` without
adding exchange traffic. Harden the local Fake runtime so snapshot completion
cannot consume events that arrived after the snapshot watermark.

## Decision

The golden runner will use a fresh file-backed Fake state and the existing
deterministic disconnect injection. An entry with an attached stop supplies a
complete local order, fill, position and protection snapshot. After two raw
events are acknowledged, the client must disconnect and expose
`requiresResync=true`. A second drain must fail before any further projection.

The runner then calls the canonical `ExchangeReconciliationService::reconcile()` against
the Fake adapter, verifies the authoritative local snapshot and passes the
result to `FakeExchangeWsClient::completeSnapshotResync()`. The reconciliation
result carries the maximum canonical event sequence captured before the
snapshot. An event is then appended before completion to exercise the race:
completion acknowledges only sequences at or below that watermark. Only after
that handshake may the client reconnect and drain the newer event. A final
drain must be empty. The canonical golden test invokes the runner twice, so
each execution gets an independent state file and the exact result must match.

## Alternatives

- Reconnect directly: fail-closed because a disconnect requires the
  snapshot/reconciliation contract named by scenario 15.
- Reuse scenario 16's sequence-gap fixture: proves out-of-order recovery, not a
  deterministic transport disconnect.
- Add disconnect persistence to the scenario DSL: useful future hardening, but
  unnecessary for this deterministic local runtime path.

## Safety

All data and execution remain local Fake. No private exchange client, credential,
demo/testnet write or mainnet write is reachable.
