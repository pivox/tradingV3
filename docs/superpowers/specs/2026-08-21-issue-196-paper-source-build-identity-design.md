# Issue #196 durable Paper source-build identity

## Scope

The canonical indicator dataset binding needs the exact recorder build version
from the verified Paper manifest. The durable Paper execution store currently
keeps only the recorder-owned dataset ID and events-file checksum, so a restart
cannot reconstruct the same derived dataset checksum graph without inventing a
version.

This slice preserves that missing source fact. It does not implement the full
strategy evidence provider and does not enable modern Paper execution.

## Contract

`PaperExecutionStoreInterface::bindDataset()` accepts the verified manifest's
`recorder_version` as canonical `source_build_version`. The execution cell
persists these three source facts atomically:

- recorder-owned Paper `dataset_id`;
- raw lowercase `events_file_sha256`;
- exact, non-empty and whitespace-preserving `source_build_version`.

For modern cells all three facts are mandatory. Rebinding is idempotent only
when every supplied fact is byte-identical. A row created before this migration
may have the new column null; presenting its exact verified manifest may fill
that one missing column transactionally, but no migration or runtime default
guesses the value. A conflicting value fails closed.

Legacy cells remain readable with a null source build version so existing
reference-only checkpoints are not falsely backfilled. The modern canonical
path rejects a missing or corrupt version before strategy preparation.

## Migration and safety

The migration is additive and nullable, adds only shape constraints, and has no
`UPDATE`. The source build version is stored but never emitted by readiness or
operator output; it can influence only authenticated derived checksums. No
private exchange client or execution permission changes in this slice.
