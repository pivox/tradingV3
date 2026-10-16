"""H3: opportunity scoring and adaptive position management (research prototype, synthetic data only).

Question: can a portfolio's net expectancy be improved by ranking opportunities by predicted quality and by adapting
exits to what the market shows during the trade? No statistical edge is demonstrated for H3.

Packages (one owner each, joined only through ``contracts``):
- ``contracts``: versioned shared contracts and interfaces (schema version 1), and the H3 risk limits.
- ``synthetic``: builders of synthetic contract values for tests.
- ``scoring``: opportunity scoring engine (agent A).
- ``adaptive``: adaptive position management (agent B).
- ``validation``: B0/B1/B2/B3 experimental framework, metrics, power (agent C).
- ``integration``: risk admission, simulated execution, audit, mapping to TradingCore (agent D).
- ``e2e``: the end-to-end synthetic example wiring all of them (integrator); ``python -m scripts.research_h3.e2e``.

Nothing here places orders, reads secrets, reads market data or depends on the Symfony or Temporal runtime.
Docs: docs/handbook/research/H3-*.md.
"""
