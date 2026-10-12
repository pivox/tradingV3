"""Execution study on paper public captures: passive fills and mark-outs.

Read-only consumer of the paper capture datasets (`events.ndjson` + `manifest.json`): it
verifies the file hash, extracts top-of-book quotes and public trades, simulates resting limit
orders under pre-registered fill rules, and measures their mark-outs. See
`docs/handbook/runbooks/research-execution-study.md`.
"""
