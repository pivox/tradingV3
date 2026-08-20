# #196 Golden scenario 15 snapshot-resync design

## Scope

Promote `websocket_disconnect_resync` from `partial` to `executable` without
adding exchange traffic or changing Fake runtime behavior.

## Decision

The golden runner will use a fresh file-backed Fake state and the existing
deterministic disconnect injection. An entry with an attached stop supplies a
complete local order, fill, position and protection snapshot. After two raw
events are acknowledged, the client must disconnect and expose
`requiresResync=true`. A second drain must fail before any further projection.

The runner then calls `ExchangeReconciliationService::reconcileBase()` against
the Fake adapter, verifies the authoritative local snapshot and passes the
result to `FakeExchangeWsClient::completeSnapshotResync()`. Only after that
handshake may it reconnect, create a second protected symbol and drain the new
events. A final drain must be empty and normalized projections must be unique.
The canonical golden test invokes the runner twice, so each execution gets an
independent state file and the exact result must match.

## Alternatives

- Reconnect directly: already covered by a unit test but does not exercise the
  snapshot/reconciliation contract named by scenario 15.
- Reuse scenario 16's sequence-gap fixture: proves out-of-order recovery, not a
  deterministic transport disconnect.
- Add disconnect persistence to the scenario DSL: useful future hardening, but
  unnecessarily expands this evidence-only P0.

## Safety

All data and execution remain local Fake. No private exchange client, credential,
demo/testnet write or mainnet write is reachable.
