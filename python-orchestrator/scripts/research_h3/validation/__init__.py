"""H3 validation framework (agent C): B0/B1/B2/B3 factorial harness, metrics, comparisons and power, synthetic only.

The four variants share one candidate book and one market stream; their portfolios evolve independently:
- B0: reference entries (fixed, score-independent order), static management;
- B1: scored entries, static management;
- B2: reference entries, adaptive management;
- B3: scored entries, adaptive management.

Modules:
- ``records``: result types of a variant run (trades, refusals, equity curve), canonical and fingerprintable.
- ``plan``: the DRAFT statistical plan (one primary criterion, the secondary hierarchy, DRAFT thresholds).
- ``metrics``: portfolio metrics (C3): net PnL, return on capital, net R, profit factor, drawdown, costs, funding,
  refusals, extreme losses, temporal stability, concentration, cost stress.
- ``stats``: pure-Python statistics: circular block bootstrap, Holm, Spearman, effective sample size, Ljung-Box,
  design effect, normal and chi-square tails.
- ``compare``: the five comparisons, the primary intersection-union criterion, gatekept Holm secondaries, guards.
- ``comparators``: no-trading (cash) and passive-exposure comparators.
- ``scoring_checks``: scoring verification (C4): calibration, ranking, buckets fixed a priori, abstention, stability.
- ``adaptive_checks``: adaptive-management verification (C5): paired exits, oracle-exit and fill-realism checks,
  funding coverage.
- ``walkforward``: chronological splits, purge, embargo, train-only transforms, point-in-time universe, trial ledger.
- ``factorial``: the harness that runs B0-B3 from injected components and proves identical inputs and independent
  portfolios.
- ``power``: fully synthetic power simulation under block dependence.
- ``registry``: machine-readable data registry (mirrors docs/handbook/research/H3-DATA-ELIGIBILITY.md).

Nothing here reads market data, trains on real data, calls an exchange or places an order. Every number this package
produces on synthetic inputs is synthetic and is not evidence about any market.
"""
