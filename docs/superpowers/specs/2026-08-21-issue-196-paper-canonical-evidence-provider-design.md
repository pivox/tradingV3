# Issue #196 production Paper canonical evidence provider

## Problem

The modern Paper coordinator, canonical preparation bridge and every individual
evidence source exist, but the service container deliberately leaves the bridge
disabled. No production component composes one immutable, dataset-bound runtime
request. The current provider contract also omits the recorder build version,
although indicator dataset identity requires that exact durable fact.

## Decision

Add a production `PaperCanonicalStrategyEvidenceProvider` and a focused
`PaperCanonicalOrderPlanEvidenceSource`.

For the exact modern cell and current replay trigger, the provider:

1. reconstructs the Paper `EffectiveTradingConfigRequest`, resolves it, and
   requires both resolved hashes to equal the durable cell identity;
2. compiles execution and portfolio policies;
3. projects exactly the indicator timeframes referenced by the setup and its
   execution policy, using the coordinator-owned dataset checksum and recorder
   build version;
4. reads the current public order book, OKX v2 instrument/tick metadata,
   venue funding and deterministic Fake cost model;
5. snapshots the same per-cell private Fake runtime used by dispatch;
6. builds entry-zone, protection, risk and net-R decisions with the existing
   canonical engines; and
7. emits a deterministic decision key and complete modern lineage snapshot.

The instrument source exposes an atomic pair containing both the existing risk
instrument and the price tick derived from the same metadata record. No second
metadata selection or fallback is allowed. The execution pair is revalidated at
the current replay clock while retaining the exact metadata-record hash; the
standalone source snapshot keeps the original metadata timestamp for audit.

The order-plan evidence source uses the book-side maker price (bid for long,
ask for short), indicator VWAP/ATR values selected by policy, portfolio equity
as the risk engine's available quote balance, and exact instrument constraints.
Portfolio admission remains the authority for aggregate loss, concurrency,
exposure and reservations. A market price outside the configured entry zone or
a canonical economic rejection produces no evidence for that event; malformed
identity/provenance inputs throw stable fail-closed errors.

## Runtime activation

Propagate `source_build_version` from the durable coordinator dataset identity
through preparation and assembly into the provider. Register the concrete
provider, preparation bridge and canonical runtime explicitly, inject the
bridge into `PaperExecutionCoordinator`, and remove the unconditional modern
readiness blocker. Modern readiness follows the same register/checkpoint/resume
path as legacy cells and remains `REFERENCE_ONLY` until certification.

`PaperReplayPreparation::assertRunnable()` accepts a modern cell only when no
readiness blocker exists. This activates Fake/Paper replay, not exchange
execution: the execution adapter is still Fake, private exchange clients are
absent, all environment write flags remain false, and mainnet remains public
read-only.

## Invariants

- exact cell/config/catalog/dataset/build/checksum/trigger identity throughout;
- no implicit price, tick, costs, funding, instrument, balance or indicator;
- the trigger must be the latest projected event and all evidence is
  no-lookahead at the shared replay clock;
- only complete OKX v2 perpetual instrument evidence is executable;
- one private-state snapshot and one public evidence graph per decision;
- deterministic decision and request identifiers across restart/retry;
- canonical engines remain the only entry-zone, stop, sizing and net-R
  authorities;
- no legacy strategy alias or fallback;
- no private exchange endpoint, credential, tuning or real/mainnet write.

## Tests

Tests first cover the order-plan evidence composition and negative cases, then
the full provider identity/determinism/missing-evidence boundary. Wiring tests
prove the concrete bridge reaches the coordinator, and readiness tests prove a
modern cell is registered, resumable and runnable without weakening legacy
behaviour. Adjacent Paper/TradingCore tests, PHPStan, container/YAML lint and
diff checks complete verification.
