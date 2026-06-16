"""Dashboard bridge between Temporal and Symfony.

PR13 introduces an execution *dashboard* (a matrix of targets) served by a thin Flask
bridge. A Temporal schedule sends a small payload (``dashboard_id``, ``run_id``,
``schedule_id``, ``dry_run``) to the bridge; the bridge expands the dashboard into
sequential Symfony ``POST /api/mtf/run`` calls, aggregates the responses and reports OK
only when every target succeeds.

The core (``dashboard`` + ``runner``) is pure and HTTP-free (a ``caller`` is injected),
so it is fully unit-testable. ``app`` is the Flask adapter that wires the real httpx
caller. The dry-run-only policy for OKX/Hyperliquid (PR11/PR12) is reused, never
duplicated.
"""
