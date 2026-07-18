# Fake multi-profile recipe v1 design

## Scope

Prompt 6 closes Fake/Paper golden scenario 20 without changing strategy, MTF,
EntryZone, sizing, scheduling frequency, or exchange permissions. The recipe uses
only `exchange=fake`, `environment=demo`, and an effective `dry_run=true`.

The existing #188 runtime runner remains the single operator entrypoint. A new
versioned fixture gives `regular`, `scalper`, and `scalper_micro` the same
`BTCUSDT` target and includes one disabled control set. Existing R1-R16 fixtures
keep their current purpose.

## Decisions

### Set identity and lineage

Each effective orchestration payload receives a deterministic `sha256:` hash of
its canonical JSON before runtime-only fields are added. The hash includes the
profile and materialized symbols, so the three same-symbol sets keep distinct
configuration identities. Python propagates it as `config_hash`; Symfony's
existing `LineageContext` persists it with the orchestration run and set IDs.

### Contention contract

The recipe reports two lock layers separately:

- orchestration locks are scoped by profile, exchange, market type, and symbol;
  distinct profiles may therefore coexist inside the same Fake dry-run;
- the Symfony business lock is scoped only by exchange, market type, and symbol;
  it blocks incompatible opening activity with `cross_profile_symbol_locked`, but
  a dry-run that creates no intent has no business owner to acquire or release.

The R12 business-lock section therefore records `evidence_status=not_exercised`
and `observed=false`. Its separate contract fields remain
`contract_conflict_status=blocked` and
`contract_conflict_reason=cross_profile_symbol_locked`; they describe the rule
for real concurrent opening activity and are not scenario evidence. The expected
non-exercise does not make this coexistence recipe `BLOCKED`.

An observed orchestration conflict is `skipped` with the stable `locked` code.
Missing runtime evidence that R12 is designed to collect is `BLOCKED`; it is
never promoted to `PASS`.

### Deterministic reports

R12 produces a normalized scenario-20 report whose ordering and content do not
depend on timestamps, durations, dashboard database IDs, or secrets. The runner
writes both JSON and Markdown from the same normalized object. The normal #188
report remains available for raw redacted runtime evidence.

The scenario key is derived from the fixture hash. Repeating the command after a
runner restart therefore replays the same persisted run instead of dispatching
the sets again. A fixture change naturally creates a new key.

### Network safety

The fixture contains no OKX, Hyperliquid, or Bitmart set. The command forces
dry-run at both fixture application and run request. Tests instrument the HTTP
boundary and fail on any target exchange or non-Fake payload. Golden scenario 20
uses the versioned fixture only and never constructs a real exchange client.

## Output contract

The normalized report contains:

- fixture/schema versions and deterministic recipe hash;
- the three observed sets, profiles, symbols, lineage IDs, and config hashes;
- disabled-set exclusion;
- separate orchestration-lock observations and business-lock evidence/contract;
- replay/restart semantics;
- bounded-parallelism contract;
- explicit zero-exchange-call proof;
- overall `PASS`, `FAIL`, or `BLOCKED` result.

## Verification

Tests cover same symbol, distinct symbols, a disabled set, orchestration lock
conflict, explicit non-exercise of the business lock, idempotent replay, a second
runner instance, bounded parallelism, strict absence of exchange calls,
redaction, deterministic JSON/Markdown, and executable golden scenario 20.
Dedicated repository tests remain the proof of the actual business lock. Broader
Python/PHP suites, PHPStan, Symfony lint, MkDocs strict, secret/network scans, and
`git diff --check` complete the gate.

## Rollback

Remove the scenario-20 fixture/handler/export, remove `config_hash` from the
derived orchestration payload, and restore scenario 20 to `partial` with
`multi_profile_fake_recipe_not_consolidated`. No schema or exchange state needs
rollback.
