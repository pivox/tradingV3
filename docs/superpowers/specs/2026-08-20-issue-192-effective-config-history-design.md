# Issue #192 — Effective config history and diff design

## Scope

This atomic backend lot turns the existing effective-config preview into an
auditable historical read model. It adds immutable persistence, centralized
redaction, historical lookup, and deterministic diffing. The Symfony/Twig UI is
owned by #318 and is not part of this change. Run/set/trade usage endpoints and
additional business-validation rules remain subsequent #192 lots.

The resolver delivered by #133 remains the only configuration authority. The
viewer never merges YAML or recalculates a historical snapshot.

## Chosen architecture

Add a dedicated effective-config registry rather than mining the duplicated
JSON stored on order intents and lifecycle events or coupling the feature to
`paper_configuration_snapshot`.

A `PersistentEffectiveTradingConfigResolver` decorates the existing concrete
resolver in the Symfony container. It resolves first, converts the result to a
safe viewer document, registers that document idempotently, and returns the
original runtime snapshot unchanged. Every production consumer of
`EffectiveTradingConfigResolverInterface`, including preview and Shadow
runtimes, therefore registers the exact snapshot it used. Direct construction
of `EffectiveTradingConfigResolver` remains available for isolated unit tests.

Persistence failure is fail-closed: the decorated resolution fails rather than
returning an unregistered runtime snapshot. Invalid or non-executable requests
continue to return the existing structured errors and are not persisted.

## Immutable registry

Create `effective_trading_config_snapshot` with these fields:

- `snapshot_hash` (`sha256:` plus 64 lowercase hex characters), primary key;
- `config_hash` and `condition_catalog_hash` with the same hash contract;
- `schema_version`, read from the effective config;
- `resolver_version`, a code constant independent from the config hash;
- the exact mode/setup versions, exchange, environment, side, and nullable
  execution capability from the request;
- `validation_status`, initially restricted to `valid` because the canonical
  resolver only returns executable snapshots;
- `redacted_snapshot` as JSONB;
- `redacted_content_checksum` over deterministic canonical JSON;
- `created_at`, set only on first registration.

Indexes support `config_hash` and the complete versioned request identity. A
PostgreSQL trigger rejects updates and deletes. Re-registering the same
`snapshot_hash` is an idempotent replay only when every stored identity field,
the redacted document, and its checksum match. Any mismatch is a conflict.

`snapshot_hash`, not `config_hash`, is the historical identity. Two snapshots
may have the same effective values and `config_hash` while retaining different
source provenance. This also matches the existing lineage reference format
`effective-config-snapshot:{snapshot_hash}`.

## Redaction and hash boundary

Introduce one recursive `EffectiveConfigRedactor` used before database writes,
HTTP responses, and JSON exports. Key matching is case-insensitive after
camelCase and punctuation normalization. API keys, secrets, passwords,
passphrases, tokens, credentials, private keys, signatures, wallet/signer
material, and credential-bearing DSNs are replaced by `***REDACTED***` at any
depth. The safe document records the redacted paths without recording their
original values.

The current strict resolver schema contains no secret-bearing value fields;
exchange configuration contains only public trading parameters. Therefore the
existing `config_hash` and `snapshot_hash` contracts remain unchanged. The
redactor is a defense-in-depth viewer boundary, not a second resolver and not a
hash implementation. A future schema that intentionally admits a secret
reference must define normalization in the resolver before changing the hash
contract.

No raw snapshot is logged or persisted by the registry. Structured logs include
only hashes, canonical request identity, layer count, redaction count, resolver
version, validation status, and duration.

## Read API

Preserve the current preview route:

```text
GET /api/trading/config/effective?...canonical identity...
```

Its successful response is presented through the redactor and gains
`document_kind=current_preview`, `resolver_version`, and
`validation_status=valid`. Existing fail-closed 400/422 responses remain.

Add read-only routes:

```text
GET /api/trading/config/effective/snapshots/{snapshot_hash}
GET /api/trading/config/effective/snapshots?config_hash={config_hash}
GET /api/trading/config/effective/diff?left={snapshot_hash}&right={snapshot_hash}
```

Historical lookup returns only the stored redacted document and marks it
`document_kind=historical_snapshot`. Hashes must use the exact canonical syntax;
malformed hashes return 400 and unknown hashes return 404. The config-hash query
returns every provenance-distinct snapshot in deterministic creation/hash order.

## Deterministic diff

The diff service loads two immutable records and recursively flattens their
redacted `config` and `provenance` maps. It returns paths in lexical order with
one of four classifications:

- `added`: absent on the left and present on the right;
- `removed`: present on the left and absent on the right;
- `changed`: both values exist but differ canonically;
- `same_but_different_source`: values are equal but provenance differs.

Unchanged paths are counted but omitted from the detail list. Values remain
redacted. The future `invalidated` classification is intentionally deferred
until #192 persists versioned warning/invalid validation results; inventing it
for snapshots that can only be `valid` would create a false contract.

## Existing lineage relationship

Modern lineage already carries `config_hash`, the canonical snapshot, and
`effective_config_reference`. The decorator registers the same snapshot hash
before runtime use, so those references become resolvable without changing the
lineage payload in this lot. Dedicated run/set/trade navigation and usage counts
will query these existing identifiers in the next #192 integration lot.

## Failure behavior

- No resolver fallback, alias, current-file reconstruction, or Paper snapshot
  fallback is introduced.
- A registry conflict, checksum mismatch, malformed stored JSON, or failed
  redaction rejects the operation.
- Historical reads never invoke the resolver.
- Diff never accepts `config_hash` as a substitute for exact snapshot identity.
- Mainnet private execution remains forbidden; these read APIs do not alter
  execution capability.

## Verification

Unit tests cover nested redaction, normalized sensitive-key variants,
deterministic redacted output, diff classifications, lexical order, and
malformed identifiers. Resolver-decorator tests prove register-before-return,
idempotent replay, and fail-closed storage errors.

PostgreSQL integration tests apply the migration, verify append-only triggers,
register and reload a snapshot, reject hash conflicts, preserve historical JSON
after source files change, and list provenance-distinct snapshots sharing a
config hash. Controller tests cover preview redaction, historical 200/404/400,
config-hash history, diff responses, and the absence of secret values from all
serialized output.

## Deferred #192 lots

- usage endpoints and counts for orchestration runs, sets, decisions, and
  trades;
- persisted `warning` and `invalid` snapshots plus the `invalidated` diff state;
- versioned trading-specific validation rules and metrics;
- Front Ops list/detail/diff screens in #318;
- retention policy, which is unnecessary while snapshots are content-addressed
  and immutable at the current scale.

## Implemented

Implemented on 20 August 2026 with resolver contract version `1.0.0` and
PostgreSQL migration `Version20260820150000`. The implementation preserves the
mainnet-private execution prohibition and does not activate any write path.
