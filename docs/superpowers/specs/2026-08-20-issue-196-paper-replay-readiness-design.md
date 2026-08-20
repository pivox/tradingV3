# Issue #196 Paper replay readiness design

## Status and scope

- Status: approved from the standing autonomous-delivery instruction
- Date: 2026-08-20
- Parent design: `2026-07-19-paper-market-replay-and-live-execution-design.md`
- Scope: close the remaining P0 gap between the implemented public replay source
  and an operator-visible, fail-closed Paper runtime readiness decision
- Out of scope: strategy tuning, profile promotion, Bitmart, private exchange APIs,
  demo/testnet writes and mainnet writes

## Problem

The repository already contains versioned public OKX and Hyperliquid datasets,
redaction and checksum verification, a controlled replay clock, a persistent
Paper coordinator and a Fake-only execution boundary. The operator contract is
still incomplete:

- the generic Fake runtime check cannot express the concrete replay source;
- the Paper replay command owns its preparation checks privately;
- the audit and runbook still describe the Paper source as absent;
- no stable result distinguishes technical runtime readiness from baseline
  eligibility.

## Decision

Introduce a dedicated `app:paper-market:runtime-check` command backed by one
preparation service shared with `app:paper-market:replay`.

The service resolves an immutable execution preparation from four explicit
inputs: dataset directory, private JSON configuration, strategy profile and run
ID. Before any Paper state is registered, it must prove:

1. both paths are absolute and contain no symlink component;
2. the configuration is a private regular file, bounded in size, valid JSON,
   redacted and accepted by the canonical snapshot factory;
3. the completed dataset has certifiable network provenance, a supported
   versioned model where applicable, exact checksum/event facts and redacted
   canonical events;
4. the controlled replay clock can advance to the dataset start without
   regression;
5. the exact network, venue, configuration hash, profile and run ID form a valid
   Paper execution cell;
6. the coordinator accepts the Paper database, enabled flag, Fake execution
   exchange, write-disabled boundary and symbol allowlist.

The check is read-only. It does not register a snapshot or cell, bind a dataset,
write a checkpoint, consume an event or dispatch a Fake effect.

## Output contract

Successful JSON uses `paper-replay-readiness-v1` and contains only redacted
identities and facts:

- `ready`, `runtime_ready`;
- dataset ID, checksum, schema version, network, venue, quality,
  symbols and channels;
- controlled-clock status;
- `execution_mode=paper`, `execution_exchange=fake`, and explicit false values
  for private clients and exchange writes;
- configuration snapshot ID, execution cell ID, profile, run ID and profile
  eligibility;
- a separate `baseline_eligible` boolean.

Failures return the same schema with `ready=false` and one stable blocker code.
Filesystem paths, configuration contents, credentials and raw exception traces
are never emitted.

Current legacy profiles remain `reference_only`. A technically ready replay is
therefore allowed to report `ready=true` and `baseline_eligible=false`; promotion
belongs to #306, #307 and #308.

## Replay integration

The replay command delegates all preparation to the same service, then performs
the existing registration, dataset binding, checkpoint resume and event
consumption. This keeps the operator check and the execution preconditions in
one contract. The dataset verifier used for preparation is the baseline verifier,
so legacy or uncertifiable provenance cannot enter the execution path.

## Verification

Tests must prove:

- a valid public replay source reports ready without writes;
- a reference-only profile is never reported baseline eligible;
- disabled Paper execution, wrong DB, legacy provenance, unsafe configuration,
  relative/symlink paths and clock regression fail closed with stable codes;
- the replay command invokes the shared preparation before registration;
- service wiring, PHPStan and the focused Paper regression suite remain green.
