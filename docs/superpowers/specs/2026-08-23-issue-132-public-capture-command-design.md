# #132 Public Paper capture command design

## Goal

Make the already implemented OKX and Hyperliquid public live sources operable
without credentials so an operator can produce the immutable mainnet datasets
required by the exact #132 Paper campaign. This command records market data; it
does not execute a strategy, create a Paper trade, or contact a private exchange
surface.

## Chosen boundary

Expose one command that captures exactly one venue per process:

```text
app:paper-market:public-capture
```

OKX and Hyperliquid captures are launched as independent processes. This keeps
their event loops, reconnect budgets, checkpoints, failures and healthy-stop
transitions isolated. A dual-venue supervisor is deliberately excluded: process
supervision already belongs to the operator/container layer and coupling both
venues would make one public-source failure contaminate the other dataset.

The command is capture-only. Running a strategy while recording would mix live
wall-clock execution with corpus acquisition and weaken replay determinism. The
complete immutable datasets are instead consumed afterward by the campaign
runner merged in #407.

## Operator contract

Required options are:

- `--venue=okx|hyperliquid`;
- `--dataset-id=<canonical-id-ending-in--mainnet>`;
- `--duration-sec=<300..604800>`.

The network is fixed to `mainnet`, meaning the public market-data network only.
Execution remains absent and no mainnet write capability is introduced. Symbols
are fixed to `BTCUSDT` and `ETHUSDT`; native symbols and the complete channel set
come from the existing venue contracts and cannot be overridden on the CLI.

The dataset root comes only from `PAPER_MARKET_DATA_ROOT`. It is not accepted as
an argument and is never printed. The venue acquisition flag must be explicitly
enabled (`PAPER_MARKET_ACQUISITION_ENABLED=1` for OKX or
`HYPERLIQUID_PAPER_PUBLIC_ACQUISITION_ENABLED=1` for Hyperliquid). All existing
URI allowlists remain authoritative.

The command creates a new schema-v2 recording manifest or resumes the exact
same non-terminal dataset after an abrupt process loss. A stored identity,
venue, network, quality, symbol map or recorder-version mismatch fails closed.
A complete or incomplete dataset is immutable and cannot be reopened by this
command.

## Components

### Manifest factory

`PaperPublicLiveManifestFactory` owns the canonical initial manifest. It maps:

- OKX to `BTC-USDT-SWAP` and `ETH-USDT-SWAP`;
- Hyperliquid to `BTC` and `ETH`.

Both use schema v2, recorder version `paper-recorder.v2`, mainnet provenance,
quality `recorded_public_book_and_trades`, recording state, zero events and no
model. The factory rejects a dataset ID that does not end in `-mainnet`.

### Source factory port

An exchange-neutral `PaperPublicLiveSourceFactoryInterface` exposes the existing
`create(datasetDirectory, loop)` boundary. The OKX and Hyperliquid live source
factories implement it with covariant concrete return types. The runner chooses
the factory from an exact two-entry map; there is no fallback between venues.

### Dataset-only capture

`PaperPublicDatasetCapture` provides the record-only state machine:

1. receive one normalized event from the acknowledged public source;
2. append it durably through `PaperDatasetRecorder`;
3. acknowledge it only after the append is durable;
4. complete the manifest only when the source reports a healthy completion;
5. otherwise stop the source and persist `incomplete`.

This is intentionally separate from `PaperLiveDatasetCapture`, whose consumer
contract requires a second durable side effect such as Paper execution. In the
dataset-only flow, the recorder append is itself the sole authoritative effect
and checkpoint, so introducing a fake/no-op consumer would violate that
contract.

### Capture runner and command

`PaperPublicCaptureRunner` validates the bounded request, constructs the
recorder, selects the exact source factory and uses one injected React event
loop. It schedules `requestHealthyOperatorStop()` at the requested duration and
registers SIGINT/SIGTERM as healthy-stop requests when supported. The source
still controls whether queues, pending acknowledgements, subscription freshness
and continuity are sufficient to complete.

The Symfony command performs input/output adaptation only. Success emits one
canonical JSON object containing:

```text
schema_version
dataset_id
source_network
source_venue
state
quality
event_count
start_exchange_timestamp
end_exchange_timestamp
channels
events_file_sha256
certification_status
```

`certification_status` is always `not_evaluated`. Neither a complete capture nor
its event count claims representative coverage, one trade, or the minimum 50
certified trades per cell. Filesystem paths, frames, remote payloads and nested
exception text never enter command output.

## Safety and failure semantics

- The dependency graph contains only public clients, source factories, dataset
  storage and the event loop. It has no execution adapter, account client,
  wallet, signer, credential, OrderIntent or database dependency.
- Only the configured public mainnet allowlists are usable. There is no private,
  demo/testnet write or mainnet write surface.
- Child processes and shell evaluation are not used.
- Dataset paths are derived from the configured root and canonical ID. Existing
  recorder and source-factory symlink, ownership, type, size and permission
  guards remain authoritative.
- A protocol, continuity, durability, backpressure or verification failure uses
  the stable source/recorder reason internally, freezes the dataset as
  `incomplete`, emits only `paper_public_capture_failed`, and exits non-zero.
- An abrupt kill may leave a `recording` dataset. Re-running the byte-identical
  request lets the existing recorder and source checkpoints recover it.
- A bounded timer or supported termination signal requests a healthy stop; it
  does not force a complete manifest. If the source cannot drain and prove
  continuity, completion fails closed.

## Verification strategy

All automated tests use deterministic sources and loops; CI makes no public
network request.

Tests cover:

- canonical manifest identity for both venues;
- invalid venue, dataset ID, duration and mainnet suffix;
- new dataset creation and exact recording-state resume;
- refusal to reopen terminal or identity-conflicting datasets;
- record-before-ack ordering, replayed append acknowledgement and identity
  conflict failure;
- timer and signal healthy-stop requests;
- incomplete persistence on abnormal stop or source failure;
- redacted success and failure output;
- Symfony container wiring and a dependency-graph audit proving the absence of
  private clients and execution services.

Delivery includes the operator runbook with two independent process examples,
the acquisition flags, private-root permissions, expected terminal JSON and the
explicit sequence: capture, verify, run #407, then evaluate the #132 population
gate. No real dataset is committed to Git and no strategy configuration changes
in this PR.
