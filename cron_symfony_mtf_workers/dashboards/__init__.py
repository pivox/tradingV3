"""Dashboard model + orchestration helpers for the Temporal-native MTF dashboard runner.

PR13 (voie 1): orchestration lives in the Temporal Workflow
(:class:`workflows.mtf_dashboard.MtfDashboardOrchestratorWorkflow`), not in a Flask bridge.

- ``model``      : Dashboard / DashboardTarget parsing + dry-run-only gate + snapshot/fingerprint.
- ``runtime``    : pure per-target helpers used by activities (success contract, runtime-check
                   decision, Symfony body) — no Temporal, no Docker, no HTTP.
- ``aggregate``  : pure batching + all-or-nothing aggregation.
- ``orchestrate``: pure async orchestration (bounded concurrency, fail_policy) with an injected
                   per-target runner, so the Workflow logic is unit-testable without a Temporal server.
"""
