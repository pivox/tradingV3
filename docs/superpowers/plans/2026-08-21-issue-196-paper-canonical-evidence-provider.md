# Issue #196 Paper canonical evidence provider implementation plan

1. Extend the canonical preparation/assembly/provider contracts with the exact
   durable recorder build version and add regression tests at the coordinator
   boundary.
2. Extend the public instrument source to return one instrument-plus-tick
   evidence object selected from the same authenticated metadata event.
3. Add `PaperCanonicalOrderPlanEvidenceSource` tests, then compose indicator,
   book, instrument, costs, portfolio and existing canonical engines.
4. Add `PaperCanonicalStrategyEvidenceProvider` tests, then implement exact
   effective-config, timeframe, lineage and deterministic decision identity
   composition.
5. Register the provider/preparation/runtime services, inject the canonical
   bridge into the coordinator, and update modern replay readiness/runnability
   tests without changing Fake-only execution safety.
6. Run focused tests while iterating, then the adjacent suite, PHPStan,
   container/YAML/PHP lint and whitespace verification before the PR cycle.
