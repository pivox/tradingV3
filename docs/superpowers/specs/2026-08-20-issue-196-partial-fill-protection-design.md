# #196 Immediate partial-fill protection design

## Status and scope

- Status: approved from the standing user instruction to take the recommended
  option and continue.
- Scope: the Fake/Paper matching engine and golden scenario 3.
- Out of scope: strategy tuning, exchange-private APIs, demo/testnet writes and
  every mainnet write.

## Problem

An ordinary non-reduce-only limit order may create a position on its first
partial fill while its attached stop is created only after the entry becomes
terminal. The golden runner currently hides that gap by submitting a separate
stop after cancelling the residual. A process failure, timeout or stop race can
therefore leave real simulated exposure unprotected.

## Decision

`FakeExchangeMatchingEngine` remains the single authority because it owns the
fill, position, protection and event state inside one state-store transaction.
After every exposure-increasing fill carrying an attached stop it must:

1. create one reduce-only stop immediately when none exists;
2. resize that same stop, preserving its identities, when the entry fills again;
3. persist the exact protected quantity on both parent and protection;
4. reuse the stop when the entry becomes terminal and only then add TP/trailing
   children;
5. compensate the newly exposed quantity if creation or resize is rejected;
6. cancel an active parent residual when its protection fills.

The protection quantity is the entry's cumulative filled quantity and the
remaining protection quantity subtracts any fill already applied to that stop.
All decimal arithmetic uses the existing Brick Math helpers. The transition is
atomic: no persisted state may contain the new position without either the
resized stop or the completed compensation.

## Rejected alternatives

- One stop child per fill increment: a triggered child currently cancels all
  sibling protections and could expose the remaining position.
- Protect only on cancel/expiry/full fill: this preserves the current unsafe
  window and does not satisfy #196.
- Delegate protection to the golden runner: this certifies a test harness rather
  than the runtime behavior.

## Failure and replay contract

The first rejected attachment compensates the cumulative entry exposure. A
rejected resize leaves the previously accepted stop unchanged and compensates
only the latest fill increment. Compensation identity includes the protected
fill boundary so retries reuse the same order. File-backed restart reconstructs
the parent/protection link, and replay of a terminal operation creates neither a
second protection nor a second compensation.

## Evidence

Tests must cover first partial fill, successive resize, partial-fill cancel,
rejected first attachment, rejected resize with prior exposure retained,
file-backed restart/replay and the stop-versus-parent-residual race. Golden
scenario 3 must stop manually creating its protection and prove the runtime
invariant directly.
