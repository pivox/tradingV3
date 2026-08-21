# Paper canonical order-book source implementation plan

1. Add failing tests for exact scope/current-trigger binding, observable availability, canonical receipt ordering, event-ID lineage, and absent/invalid evidence.
2. Implement the source by composing the applied market prefix, canonical Paper adapter, replay clock, and canonical order-book DTO.
3. Run focused and adjacent Paper/microstructure/order-plan tests, targeted PHPStan, and container lint.
4. Rebase onto merged `main`, publish a ready PR, request Codex review, address actionable feedback, require green CI, merge, and record the #196 milestone.
