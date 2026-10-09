# Historical research campaign — approved option 1

## Decision and scope

The user approved option 1 on 2026-10-09: historical candle research on ten
pairs from 2023 through the latest available closed candle, followed by separate
Paper validation on suitable recorded public data. The user authorizes setup
experiments, economical agents, one consolidated review/correction cycle per PR,
and merge/continuation after required checks. This is not permission to enable
exchange writes, change operational risk limits, purchase data, overwrite
published `1.1.0` contracts, or relabel simulated execution as certified Paper.

Mode: `day_trading`. Initial direction: long, matching the existing baseline.
Universe: Binance USD-M perpetual BTCUSDT, ETHUSDT, BNBUSDT, XRPUSDT, ADAUSDT,
DOGEUSDT, SOLUSDT, LTCUSDT, LINKUSDT, AVAXUSDT. This universe was chosen today,
not prospectively in 2023: survivorship/selection bias must remain an explicit
limitation. A listed archive is not proof of continuous coverage.

## Overall deliverable

1. Immutable/checksummed raw public archives and an independently reproducible
   coverage inventory for every requested pair and time range.
2. A baseline and versioned setup variations with retained parameters, code and
   data hashes, decision counts, rejected opportunities, trades and cost inputs.
3. A bounded search, not an infinite loop until a desirable statistic appears.
   Default first batch: baseline plus at most 12 distinct candidates. Additional
   batches require a recorded scientific reason and leave final holdout untouched.
4. CSV/JSON statistics and a readable report: trade count, net win rate, net PnL,
   profit factor (undefined denominators remain explicit), average net R,
   marked-to-market drawdown, exposure, fees/funding, and per-pair/year results.
5. Selection frozen before final holdout evaluation, followed by an explicit
   transfer assessment and Paper replay where corresponding data/profile support
   actually exists. Binance findings are not automatically OKX/Hyperliquid proofs.

No profitability, global optimum, full overnight completion, or live readiness
is promised. A partial report identifies completed work and missing evidence;
it does not replace the full requested campaign's completion criteria.

## Research protocol

- Data start: `2023-01-01T00:00:00Z`; acquire earlier indicator warmup separately
  if needed and do not count its returns. Freeze a precise UTC exclusive end
  before acquisition. Never use a still-open candle or move the end during a run.
- Training: 2023–2024. Validation: 2025. Final holdout: 2026 through frozen end.
  Quality checks may inspect all years, but neither signals, profits nor candidate
  selection may read holdout results before the winner is frozen.
- Default objective: positive, stable net expectancy with drawdown reported and
  constrained by the unchanged research risk policy; win rate alone is not the
  optimization target. A profile with insufficient trades or missing costs stays
  inconclusive instead of winning by a convenient denominator.
- Compare variants on identical data, starting balance, exposure limits and cost
  assumptions. Record all attempted variants, including losing/zero-trade ones.
- Indicators and decisions use only closed/available observations. Higher
  timeframes must close before entering context. No same-bar optimistic fills.
- Preserve authoritative PHP strategy/indicator/plan semantics through a tested
  bridge where applicable. Any research-specific execution model or setup has
  its own explicit version and differences; never pretend an approximate Python
  rewrite is the published strategy.
- OHLCV cannot prove queue priority, spread, passive fills or intrabar path.
  Conservative fill/cost assumptions and sensitivities are labeled. Missing
  funding is not silently zero; separate projected from historical costs.

## Delivery decomposition

### A — Public historical acquisition and coverage

Independent first PR, no trading behavior. Python module under
`python-orchestrator/app/backtesting/research/`, using standard library plus
the existing `httpx` dependency. Raw files stay outside Git under
`tradingV3-private/research/`. No database, private endpoint or credential.

Use official Binance USD-M monthly `1m` kline archives for complete months;
daily archives for the remaining completed days. Verify each supplied `.CHECKSUM`
before parsing. Archive schema, exact source URL, retrieval time, checksum,
first/last close, row count and gap/error status are recorded. A partial current
tail is fetched from the fixed public klines endpoint, retained verbatim and
locally hashed with `integrity_evidence=download_sha256`, not archive checksum.
If that API is unavailable, report the uncovered tail rather than bypass access
restrictions or invent data. Monthly funding archives are fetched/verified
separately; their timestamps and interval metadata are retained without making
an unsupported complete-funding claim.

Reject duplicate/out-of-order/non-finite/invalid OHLC records, wrong close time,
wrong interval, ZIP path traversal, multiple unexpected members and unreasonable
sizes. Gaps are first-class evidence, not forward-filled candles. Treat a missing
archive, checksum mismatch, HTTP error and malformed data as different states.
Retry only bounded transient failures. Resume verifies bytes again; existing
conflicting output is never silently replaced. Roots must be explicit private
directories outside the repository; outputs use private permissions and atomic
publication. Enforce one acquisition writer and bounded memory/disk usage.

### B — Research identity, canonical bridge and OHLCV execution

Separate PR after the acquisition interface is tested. Binance provenance and
fake/local execution are distinct fields. The current canonical allowlists only
recognize BTC/ETH and OKX/Hyperliquid: extension must be explicit, tested and
research-only, not a venue alias or loosening of production execution guards.
Benchmark a small training window before scheduling the full campaign. Stream or
partition data instead of raising existing protocol/event caps arbitrarily.

### C — Experiment ledger, bounded search and reports

Separate PR/run after B. Persist run manifests and progress after each completed
unit. Resume from validated checkpoints, never infer completion from a lock file.
Freeze candidate selection before exposing final test metrics. Keep results
with the complete parameter and provenance identity; failed/incomplete runs are
not scored as zero-return successes. Produce the requested report from actual
ledger rows and independent arithmetic checks.

### D — Transfer/Paper validation

Use unchanged recorded public captures initially; preserve `1.1.0` baseline.
Publish a new compatible profile only when its exact semantics are represented
and schema/validators explicitly support its version. Test selected hypotheses
through existing Paper paths. Report venue/universe/data differences and do not
activate demo/testnet/mainnet writes. A failed transfer is a valid scientific
result, not a reason to relax safety or certification gates.

## Resources and evidence

Initial local capacity: 4 CPUs, 15 GiB RAM, approximately 74 GiB free disk.
Acquisition budget: 20 GiB total, at least 20 GiB free retained, sequential or at
most two bounded public downloads. Research workers must leave capacity for the
existing workspace; no cloud billing or purchased S3 traffic.

Official source documentation:

- https://github.com/binance/binance-public-data — formats, daily/monthly files,
  archive checksums and subsequent corrections.
- https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Kline-Candlestick-Data
- https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Get-Funding-Rate-History

Observed public probes: BTC 1m January 2023 archive returns HTTP 200; funding
January 2023 archive also returns 200 and has columns
`calc_time,funding_interval_hours,last_funding_rate`. A probe of the October 8,
2026 daily kline archive returned 404 on October 9. Do not interpret that as
proof that the market did not trade or that all 2026 archives are missing.

## Completion checklist

- [x] User chose historical research followed by distinct Paper validation.
- [x] Existing replay and diagnostic evidence preserved; no rerun for discovery.
- [ ] A: tested collector, retained coverage/provenance for all ten pairs.
- [ ] B: explicit research identity and faithful tested decision/execution path.
- [ ] C: baseline, bounded candidates, frozen selection, final holdout and reports.
- [ ] D: selected hypothesis transfer/Paper validation and limitations reported.

The campaign remains incomplete until each requested phase has evidence; a
merged collector alone is not completion.
