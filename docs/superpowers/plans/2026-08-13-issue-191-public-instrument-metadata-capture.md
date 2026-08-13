# #191 Authenticated Public Instrument Metadata Capture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans
> inline. The user requested no subagents after the preceding merge.

**Goal:** Persist venue-authenticated, epoch-dated instrument metadata as
immutable Paper events before any quantity-conversion or fill model consumes it.

**Architecture:** Dedicated public metadata interfaces extend the bounded Paper
HTTP clients without widening historical-client test doubles. Venue normalizers
produce one strict `instrument_metadata` event per supported symbol and source
epoch. Live sources persist those events through their existing acknowledged
checkpoint paths before epoch snapshot boundaries, and the dataset verifier
recomputes their identities and temporal ordering.

**Tech Stack:** PHP 8.3, PHPUnit, Brick Math, Symfony HTTP/Clock, canonical JSON
and SHA-256.

---

### Task 1: Versioned event and normalizer contracts

**Files:**
- Modify: `trading-app/src/Trading/Paper/MarketData/PaperMarketDataChannel.php`
- Modify: `trading-app/src/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizer.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperMarketEventNormalizer.php`
- Modify: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperStreamFrontier.php`
- Test: venue normalizer and stream-frontier PHPUnit files

- [ ] Write failing tests for exact OKX and Hyperliquid metadata payloads,
  canonical decimals, explicit units, epoch identity and malformed rows.
- [ ] Run focused tests and confirm RED for the missing channel/methods.
- [ ] Implement the smallest strict normalizers and OKX frontier projection.
- [ ] Re-run focused tests and confirm GREEN.

### Task 2: Bounded public metadata clients

**Files:**
- Create: venue-specific `*InstrumentMetadataClientInterface.php` files
- Modify: both Paper public REST clients and HTTP endpoint/transport contracts
- Modify: `trading-app/config/services.yaml`
- Test: both Paper public REST client and service-wiring PHPUnit files

- [ ] Write failing tests for exact public requests, bounded bodies, retries,
  missing/duplicate symbols and strict supported contract fields.
- [ ] Run focused client tests and confirm RED.
- [ ] Add `GET /api/v5/public/instruments` for OKX and `type=meta` for
  Hyperliquid, returning only the requested supported row.
- [ ] Add explicit service aliases and re-run focused tests to GREEN.

### Task 3: Epoch capture and crash-safe continuation

**Files:**
- Modify: both public live source classes and factories
- Modify: live checkpoint/source tests for both venues

- [ ] Write failing initial/reconnect tests proving metadata is emitted once per
  symbol and epoch before its snapshot boundary.
- [ ] Add acknowledgement/crash-resume tests proving no silent metadata skip or
  conflicting replay.
- [ ] Run focused live-source tests and confirm RED.
- [ ] Wire the public clients into live sources and use the existing pending
  event/frontier checkpoint paths.
- [ ] Re-run focused tests and confirm GREEN.

### Task 4: Verified immutable dataset semantics

**Files:**
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`
- Modify: dataset and capture/replay equality tests

- [ ] Write failing tests for exact venue schema, tampering, wrong unit, stale or
  missing epoch order, duplicate identity and unsupported historical metadata.
- [ ] Extend live dataset verification with venue-specific metadata identity and
  per-symbol epoch-before-boundary state.
- [ ] Run focused verifier/equality tests to GREEN.

### Task 5: Documentation and delivery

**Files:**
- Modify: `docs/backtesting.md`

- [ ] Document capture authority, units, dynamic Hyperliquid price precision,
  fail-closed historical behavior and explicit non-goals.
- [ ] Run changed-file style checks, focused PHPUnit and PHPStan.
- [ ] Run the broad Paper suite and strict MkDocs verification.
- [ ] Inspect `git diff --check`, security-sensitive calls and complete diff.
- [ ] Commit, push and open a ready PR linked to #191 with exact evidence.
- [ ] Request Codex review. Treat a thumbs-up as approval, address only real
  actionable feedback, and merge with green checks and no blocking threads.
