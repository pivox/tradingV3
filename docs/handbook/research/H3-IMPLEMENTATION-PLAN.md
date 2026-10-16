# H3 — Progressive implementation plan (DRAFT, 2026-10-15)

Agent D of the H3 mission. This page turns `H3-RISK-AND-EXECUTION.md` into a sequence of small pull requests.

**Rules that apply to every step:**

- **One PR per step**, with tests, reviewed, merged by the owner.
- **Nothing is activated without the owner's explicit go.** No production configuration is changed and no demo or
  live flag is touched (`OKX_LIVE_ENABLED`, `OKX_DEMO_TRADING_ENABLED`, `DEMO_TRADING_ENABLED`,
  `HYPERLIQUID_*` stay as they are).
- **Temporal is not modified** by any step below.
- **Data.** A step that would touch real data needs a FROZEN protocol, and the contracts enforce it. Already-read
  data can only ever be "development".
- **Stop conditions.** A step that fails its gate stops the sequence. Steps 0 to 4 are Python research only;
  steps 5 and later change PHP and need the owner's go for each.

**Status today: none of the economic questions is answered.** H3-A, H3-B and H3-C have no evidence; the plan only
makes them testable.

## Phase R — research only (Python, synthetic, then development data)

| Step | Content | Gate to pass | Rollback |
|---|---|---|---|
| R0 (this branch) | `research_h3/integration`: risk gate, `run_variant`, audit journal, synthetic candidates, TradingCore map; this page and `H3-RISK-AND-EXECUTION.md` | 52 synthetic tests green; Python 3.11 syntax check; no file outside the D scope | delete the package |
| R1 integration | the integrator wires A (scorer), B (policies) and C (B0–B3 harness) to `run_variant`; one synthetic end-to-end experiment; contract change requests CR-1 to CR-4 decided | the same candidates, bars, costs and gate configuration for the four variants (asserted); audit chains verify; no synthetic number reported as a result | revert the integration PR |
| R2 candidate source | one common generator from a candidate-source dossier (CS-FUND, CS-UNLOCK, CS-XMOM or another) with an explicit economic hypothesis, independent of any score; if none is defensible, say so and stop | owner review of the hypothesis; generator tested on synthetic data only | keep the synthetic provider |
| R3 protocol | the validation framework's DRAFT preregistration completed with the DRAFT parameters of `H3-RISK-AND-EXECUTION.md` §11 | owner freezes the protocol (sha256); every threshold has an a-priori reason | none (nothing has run) |
| R4 development run | the frozen protocol on development data only (already-read windows), B0–B3 | only the protocol's metrics are reported, gap losses included; no claim beyond "development" | report only |
| R5 independent validation | only if R4 is not a stop under the protocol: never-read data per the protocol | protocol gates; two independent reviews | report only |

`run_variant` stays the research simulator through phase R. It is not a production engine and must not grow into
one. A positive R5 is required before any PHP work beyond step P0.

## Phase P — TradingCore (PHP), only after the owner's go, each step independent

Each PHP step adds code that no production service uses until a later, separate activation decision. Every
published mode or setup contract is a new immutable version, never an edit of `1.1.0`.

| Step | Content | Gate | Notes |
|---|---|---|---|
| P0 CI coverage | add `tests/TradingCore/{Risk,OrderPlan,Execution}` to a path-filtered workflow | the tests run green in CI (the known red tests are listed and excluded explicitly) | GAP-CI-COVERAGE; useful even without H3 |
| P1 portfolio inputs | `CanonicalPortfolioSnapshot` gains occupied instruments; the portfolio policy gains an open-risk cap (percent of equity); the admission engine checks both | unit tests including the H3 cases (0.5 % / 1 % / 1x, instrument already open); existing modes unchanged (their tests still green, their config hashes unchanged) | GAP-OCCUPANCY, GAP-TOTAL-RISK |
| P2 adaptive transitions | reservation transitions for stop update and partial reduction that never increase reserved risk or notional, each with a state hash; an ACTION decision equivalent to `validate_action` | port of the Python action tests (same reason codes); property: reserved risk is non-increasing | GAP-ADAPTIVE-TRANSITIONS |
| P3 order plan | a stop kind "explicit" (provenance-checked candidate stop) and an IOC limit expressible as a plan (zone [reference, collar]), as a new plan version | validator and authority tests; existing plans unchanged | GAP-STOP-KIND, GAP-MARKET-ENTRY |
| P4 H3 mode contract | a new mode/setup contract version for H3 (0.5 % trade budget, open-risk cap 1 %, exposure 100 %, leverage 1, stop required), status shadow, writes disabled | resolver, compiler and hash tests; `mainnet_write_enabled` and `write_enabled` stay false | GAP-MODE-CONTRACT; quote currency waits for P6 |
| P5 cross-language parity | shared synthetic fixtures (canonical JSON) replayed through `run_variant` and through the PHP engines; field-by-field comparison of sizes, risks, refusals and audit subjects | identical results on every fixture, or each difference listed and accepted by the owner | first time parity may be claimed, and only for the fixtures |
| P6 X-Perp support | resolver, `instType`, normalisers, USD/USDC currency rules per `H1-XPERP-TECHNICAL-GAP.md`; read-only first | read-only runtime-check probes by the owner; no write path | GAP-XPERP; owner decision |
| P7 protective replacement | an amend path, or place-new-then-cancel-old with reconciliation, for stop tightening; tests of every failure ordering (place fails, cancel fails, timeout, already filled) | no window without a stop in any tested ordering | GAP-STOP-REPLACE |
| P8 Paper replay | H3 B0–B3 through the canonical Paper path (Fake/Paper adapters), shadow only | equality with P5 fixtures; disposable Postgres only | needs P1–P5 |

**Never in this plan:**

- demo or live orders;
- changing a flag or a production configuration;
- moving scoring or decisions into Temporal;
- replacing `CanonicalRiskEngine` or the admission engine with the simplified Python gate.

The Python gate is a research mirror of the canonical rules plus the H3 additions. If H3 goes further, PHP is the
place where those additions are implemented, never the Python gate promoted to production.

## Order and dependencies

```
R0 → R1 → R2 → R3 → R4 → (R5)                       research track, owner gates at R2, R3, R5
P0                                                   independent, any time
P1 → P2 → P3 → P4 → P5 → P8                         only after a positive R5 and the owner's go
P6, P7                                               independent prerequisites of anything beyond shadow
```
