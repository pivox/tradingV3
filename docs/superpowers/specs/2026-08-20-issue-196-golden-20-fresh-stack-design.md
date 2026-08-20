# #196 Golden scenario 20 fresh-stack design

## Scope

Promote `dry_run_multi_profiles_same_symbol` from `partial` to `executable` by
running the existing R12 recipe through two independently created application
stacks. The proof remains strictly `exchange=fake`, `dry_run=true`,
`environment=demo`, `workers=1`; it cannot reach a private exchange client or
dispatch exchange-capable asynchronous work.

## Decision

A dedicated Python proof runner starts two stacks sequentially. Every stack
gets a new temporary SQLite orchestration database, a new Paper Fake state
directory, a new Symfony process, and a new FastAPI/uvicorn process. The
processes communicate over loopback TCP, so neither the orchestrator API nor
the Symfony boundary is replaced by an in-memory HTTP double.

The Symfony process boots the real test Kernel through `public/index.php`.
The orchestrator database is initialized from the real SQLAlchemy metadata,
then the real FastAPI application serves the existing dashboard, run and
history routes. The existing `RecipeRunner` applies the versioned R12 fixture,
runs all three enabled sets, replays the idempotency key, reads persisted run
evidence, and exports its normalized report.

The proof runner removes both stacks, compares the two normalized reports byte
for byte, and emits one canonical JSON result. Runtime-only values such as
ports, PIDs, timestamps and temporary paths are used to prove isolation but are
never exported. The PHP golden runner invokes this proof directly; its normal
determinism test therefore executes the complete proof twice, with fresh state
on every invocation.

## Fail-closed rules

- missing Python/PHP dependencies, port startup failure, non-200 health checks,
  timeout, malformed output, or either R12 status other than `PASS` fails the
  golden scenario;
- both process pairs and database paths must be distinct;
- both normalized R12 reports must be identical;
- all three sets must retain distinct lineage/config hashes on `BTCUSDT`;
- every order count and every Bitmart/OKX/Hyperliquid call count must be zero;
- child processes are terminated in `finally`, and temporary state is removed.

## Alternatives

- Keep `FakeRecipeApi`/`httpx.MockTransport`: fast unit coverage, but it is the
  exact certification gap identified by the audit.
- Parse the fixture from PHP: deterministic but does not execute orchestration,
  persistence, HTTP, replay, or the Symfony runtime.
- Require Docker/PostgreSQL/Redis: closer to deployment topology but unsuitable
  for the deterministic local golden suite and unnecessary for this dry-run
  path. Real FastAPI and Symfony processes with file-backed state exercise the
  application boundary without external infrastructure.

## Safety

Servers bind only to `127.0.0.1` on ephemeral ports. Symfony runs in `test`, its
Paper Fake state root is temporary, and the R12 safety evidence must report zero
calls for Bitmart, OKX and Hyperliquid. No mainnet or demo/testnet write is
permitted.
