# #196 Paper certification campaign runner design

## Goal

Execute the exact versioned #132 certification matrix as a resumable Paper
campaign without sharing a replay clock between cells, contacting a private
exchange API, or claiming that a successful replay produced certified trades.

## Operator contract

The command accepts one versioned matrix specification, one private immutable
Paper configuration, one explicit absolute dataset directory for every exact
`paper_network/market_data_venue` scope, a campaign ID, an atomic state file and
a bounded per-process timeout. Dataset mappings must match the matrix scopes
exactly: missing, duplicate or additional mappings fail before a child process
starts.

The matrix is built in-process by `PaperCertificationMatrixBuilder`; strategy
identities are never reimplemented by the campaign runner. Each cell is then
executed through two fresh PHP processes:

1. `app:paper-market:runtime-check` with the exact modern identity;
2. `app:paper-market:replay` with the byte-identical identity and run ID.

This process boundary is mandatory because `PaperReplayClock` is monotonic and
different venue datasets may start at earlier timestamps. Cells run
sequentially and the first failed/timeout/non-eligible readiness or replay stops
the campaign.

## Determinism and resume

Each run ID is derived from the campaign ID, matrix digest, immutable input
digest and complete cell identity. Re-running the same campaign therefore
addresses the same persisted Paper cell and resumes from its database
checkpoint. Completed cells are still readiness-checked and replayed
idempotently; the state file is evidence, never an authority that can skip
database verification.

The state file contains the input digest, matrix digest, exact cells, run IDs,
attempt counts, statuses and redacted readiness evidence. It is replaced
atomically after every transition. The input digest covers the configuration
contents plus each dataset manifest and events file. A changed matrix,
configuration or dataset under the same campaign ID fails before execution.

## Safety and reporting

- Child argv is always an array; no shell interpolation is used.
- `PAPER_EXECUTION_ENABLED=1` is set only for Fake/Paper child commands.
- Successful readiness must prove `baseline_eligible`, Fake execution, disabled
  private clients and disabled mainnet/demo writes, plus the exact source and
  strategy identity.
- Raw private paths, configuration contents and child stderr are never written
  to command output or campaign state.
- `completed` means every replay process reached the verified dataset end. It
  does not mean that any trade exists, is closed, has complete costs/PnL, or
  meets the 50-trade certification threshold.
