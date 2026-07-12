# HL-012 Controlled Hyperliquid Testnet Trading Design

## Status

Validated by the operator on 2026-07-12.

HL-012 adds a controlled Hyperliquid testnet execution path. It does not
authorize a testnet broadcast while DEMO-005 remains `blocked`, and it never
authorizes a mainnet write. The implementation is delivered fail-closed and is
validated with strict fake transports until the readiness decision explicitly
becomes `ready_for_demo_testnet_trading_attempt`.

## Goals

- Submit and cancel the minimum Hyperliquid perpetual actions required by a
  controlled testnet recipe.
- Require an entry stop loss and compensate immediately if protection is not
  accepted.
- Keep the Hyperliquid agent private key outside the PHP process.
- Reuse the existing safety policy, kill switch, private observability policy,
  action factory, lifecycle normalizer, metadata resolver, and persistent nonce
  manager.
- Preserve `mainnet_write_enabled=false` and reject every mainnet endpoint,
  network, domain, or environment before signing or dispatch.
- Produce redacted audit evidence for every allowed, blocked, submitted,
  compensated, or unresolved attempt.

## Non-goals

- Mainnet support or mainnet readiness.
- Strategy, MTF, EntryZone, risk formula, leverage policy, or SL/TP business
  changes.
- Spot, vault, subaccount, transfer, withdrawal, staking, TWAP, builder fee, or
  agent approval actions.
- PnL certification or implicit USDC-to-USDT conversion.
- Enabling the testnet sidecar or exchange gates by default.

## Architecture

### PHP control plane

A new `HyperliquidTestnetExecutionPort` implements `ExecutionPortInterface`
without changing the existing dry-run port. The testnet port is the only
component allowed to request a signed Hyperliquid mutation. Before reserving a
nonce or calling the signer sidecar it must validate:

- `exchange=hyperliquid`, `market_type=perpetual`, and `environment=testnet`;
- `HYPERLIQUID_NETWORK=testnet` and the exact API host
  `api.hyperliquid-testnet.xyz`;
- `mainnet_write_enabled=false` and `demo_testnet_write_enabled=true`;
- global and Hyperliquid-specific feature gates;
- effective kill switch disabled;
- non-empty market allow-list and membership of the requested market;
- positive maximum notional and requested notional within that maximum;
- executable order plan, resolved asset id, valid metadata and precision;
- a non-null stop loss;
- complete private observability under the existing policy;
- distinct account and agent addresses;
- readiness level `demo_testnet_candidate`;
- a ready persistent nonce scope.

The port receives the private observability status and readiness evidence as
typed collaborators or validated runtime metadata. It must not trust a free-form
boolean supplied by an orchestration request as proof of readiness.

### Dedicated signing sidecar

A dedicated Python service uses a pinned release of the official Hyperliquid
Python SDK. It is separate from the Python orchestrator because orchestration
and custody of an execution key have different ownership and exposure.

The sidecar:

- runs as a non-root container under an opt-in Docker Compose profile;
- exposes an internal Docker-network port only and publishes no host port;
- is the only service receiving
  `HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY`;
- derives the signer address from the private key and compares it with the
  configured testnet agent address;
- accepts only `order`, `cancel`, `cancelByCloid`, and `updateLeverage` actions;
- rejects mainnet chain names, URLs, domains, and configuration;
- signs L1 actions with the official SDK and sends them only to
  `https://api.hyperliquid-testnet.xyz/exchange`;
- never returns or logs the private key, canonical signing payload, or signature;
- returns a bounded redacted exchange response envelope.

The PHP client authenticates to the internal service with a dedicated sidecar
token. The token is redacted by the existing audit rules and is not committed.
The sidecar rejects missing or invalid tokens before parsing an action.

## Execution Flow

1. Validate the order plan and all static testnet guards.
2. Evaluate `DemoTradingKillSwitchService`; stop on any blocking reason or audit
   failure.
3. Validate private observability and runtime readiness.
4. Resolve the Hyperliquid asset id and quantized order values.
5. Reserve a nonce from `PersistentHyperliquidNonceManager`.
6. Submit `updateLeverage` when the requested effective leverage differs from
   the observed value.
7. Build one `order` action using `grouping=positionTpsl`, containing the entry
   and its reduce-only stop-loss trigger.
8. Reserve a fresh nonce and dispatch the grouped action through the sidecar.
9. Normalize every returned status and verify both entry and stop-loss results.
10. Return an accepted result only when the response proves protection is
    accepted with the entry.

Every action uses a distinct persistent nonce. A nonce is never reused after an
ambiguous transport result; reconciliation determines the exchange state.

## Protection and Compensation

The grouped `positionTpsl` action minimizes the interval between entry and
protection but does not make response handling optional.

- If the entry is rejected, return rejected and do not compensate.
- If both entry and stop loss are accepted, return accepted.
- If the entry is resting and the stop loss is rejected, cancel the entry by
  client order id and confirm through bounded account polling.
- If the entry filled and the stop loss is rejected, submit a reduce-only IOC
  close with an explicit slippage cap and confirm through bounded account
  polling.
- If entry state is ambiguous, do not guess from symbol or time. Reconcile by
  client order id or exchange order id.
- If cancellation, close, or reconciliation cannot be proven, record
  `unknown_requires_resync`, request the exchange kill switch, and expose a
  blocking incident result.

The code never reports a protected or completed execution from a partial or
ambiguous response.

## API Contracts

The internal sidecar request contains only:

- a schema version;
- action and nonce;
- expected account and agent addresses;
- `environment=testnet`, `network=testnet`;
- optional expiry bounded by the service;
- correlation and idempotency identifiers that contain no secret.

The response contains only:

- schema version;
- accepted/rejected/ambiguous outcome;
- redacted Hyperliquid status rows;
- exchange order ids and client order ids when supplied by Hyperliquid;
- normalized reason codes;
- correlation identifier.

Request and response sizes, timeouts, and status row counts are bounded.

## Configuration and Defaults

The committed defaults remain non-mutative:

```dotenv
DEMO_TRADING_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
HYPERLIQUID_MAINNET_ENABLED=0
HYPERLIQUID_ENV=testnet
HYPERLIQUID_NETWORK=testnet
HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz
HYPERLIQUID_SIGNER_BROADCAST_ENABLED=0
```

The account address and agent address are visible identifiers. The sidecar
authentication token and agent private key are secrets and must be supplied
only through local or deployment secret storage. The PHP service does not
receive the agent private key in the HL-012 Compose topology.

## Failure Handling

- Configuration, readiness, safety, and observability failures occur before
  nonce reservation and dispatch.
- Sidecar authentication and schema failures are hard failures.
- Network timeout after dispatch is ambiguous, not rejected; it triggers
  identifier-based reconciliation.
- HTTP 429 and transient service errors are normalized and never retried with
  the same nonce.
- Exchange business errors are normalized through the Hyperliquid lifecycle
  normalizer.
- Audit failure blocks the action.
- Compensation failure requests kill-switch activation and leaves an explicit
  unresolved state for operator action.

## Testing

PHPUnit covers all gates, mainnet endpoint rejection, account/agent separation,
nonce allocation and restart, grouped entry/SL serialization, response
normalization, resting-entry cancellation, filled-entry close, ambiguous
reconciliation, compensation failure, audit failure, and redaction.

Pytest covers sidecar authentication, schema bounds, action allow-list, exact
testnet endpoint enforcement, signer-address derivation, official SDK signing
vectors, fake transport submission, redaction, and refusal to start when
broadcast is enabled with an invalid configuration.

Integration tests run the PHP client against a strict fake sidecar and exercise
PostgreSQL-backed nonce allocation. A real testnet smoke run is an operator gate,
not a CI requirement, while DEMO-005 remains blocked.

Required verification includes targeted PHPUnit and pytest, PHPStan on touched
PHP files, Symfony container and YAML lint, Docker Compose config validation,
MkDocs strict build, secret scans, and `git diff --check`.

## Rollback

Operational rollback is immediate and does not require a deployment:

1. Set `HYPERLIQUID_TESTNET_TRADING_ENABLED=0`.
2. Set `HYPERLIQUID_SIGNER_BROADCAST_ENABLED=0`.
3. Enable the Hyperliquid kill switch.
4. Stop the signer-sidecar Compose profile.
5. Reconcile open orders and positions read-only by account address.

Code rollback removes the testnet execution port and sidecar while retaining
the existing Hyperliquid read-only and local dry-run implementations.

## Delivery State

HL-012 may be merged with the implementation disabled by default and strict
fake-transport evidence. It must not claim `demo_testnet_execution_validated`
and must not send a real testnet order until DEMO-005 is explicitly reevaluated
as `ready_for_demo_testnet_trading_attempt` and the runtime check reports
`demo_testnet_candidate` with `Schedule ready: yes`.
