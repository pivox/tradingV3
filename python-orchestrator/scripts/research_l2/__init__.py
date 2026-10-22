"""L2 capture pilot on OKX Europe X-Perps (order-book depth + public trades), prepared 2026-10-22.

Research tooling only, separate from the LP programme collector (`scripts.research_lp`): its own process, output
directory, lock file and WebSocket connection. Public data only: no credential, no private endpoint, no order.
Nothing here is scheduled; the capture command is run by hand, once, no earlier than 2026-11-02T00:00:00Z.

The package lives outside `scripts.research_lp` on purpose: `research_lp.cli.code_digest()` hashes every `*.py`
of that package and the G5 freeze records it (`freeze_fill.code_digest_sha256`); a module added there would move
the G5 digest. This package only *imports* constants and helpers from `research_lp` (quarantine floor, EEA hosts,
X-Perp id pattern, health journal); it changes none of them.

Modules:

- `book`    frame headers (identifiers, sequence numbers and counts — never a price), the seqId / prevSeqId tracker
            shared by the collector and the readers, the order book of the `books` channel, the CRC32 checksum;
- `pilot`   frozen pilot spec (sha256-checked; `l2-pilot-spec/2`, v1 kept and refused for a run), guards (reserved window
            2026-11-02T00:00Z -> hard end 2026-11-03T00:00Z, P-L2-1 separation recorded in the G5 Gate 2 spec, quarantine floor,
            duration, bytes, free space, protected paths, non-empty directory, lock), instrument selection by metadata, gzip JSONL segment
            writer with per-segment sha256, versioned manifest, the WebSocket loop with resubscription on a gap;
- `replay`  offline reader in availability order (book INVALID across any break), the header-only quality summary
            (counts, gaps, resyncs, coverage — never a price) and its frozen pass/fail criteria, `verify`;
- `cli`     `spec-hash`, `plan`, `capture`, `verify`, `quality`, `replay-check`.

Documentation: `docs/handbook/research/L2-CAPTURE-PILOT.md`.
"""
