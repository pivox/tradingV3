# #191 Staged visible-fill runtime design

## Scope

Execute a canonical v2 maker plan against every positive fill in its verified
visible-queue trace. Preserve the exact public-trade availability chronology,
the quantity exposed after each fill, the residual quantity and the first
observable terminal branch. Settle the cumulative closed quantity once through
the PHP partial-fill cost authority.

This lot does not add taker fallback, private venue access, live execution,
portfolio-wide reservations, tranche-aware historical funding or certification.

## Chosen execution model

The runtime consumes positive trace fills in `available_at` order. Each fill
produces an immutable event with exact base quantity and derived contract
quantity. A fill completing the original order is `entry_filled`; every other
positive increment is `entry_partially_filled`.

Backtrader continues to provide deterministic bar delivery only. For each bar,
the pure execution state machine first applies all newly available public fills.
If that bar reaches the stop, it closes the full cumulative exposed quantity as
a conservative upper loss bound. A target reached on a bar containing a new
fill is not credited because intrabar ordering is unknown. On later bars without
a new fill, the existing stop-first policy applies normally.

The first stop or target terminates the position and cancels the still-open
entry residual. Later public trades from the precomputed queue trace are not
turned into fills after that terminal event. A final `partially_filled` queue
result therefore remains executable: its residual is cancelled at the evidence
deadline while the already-filled position continues until stop or target.

## Cost and outcome authority

The runtime builds `canonical-partial-fill-cost-request.v1` from the plan,
dataset, full queue result/trace hashes, consumed fill prefix, exact cumulative
base quantity and terminal branch. `PartialFillCostBridge` invokes the fixed PHP
command. The returned settlement must match the request and the execution
prefix before it can produce a
`canonical-backtest-partial-fill-net-outcome.v1` document.

One aggregate settlement is exact for this policy because all maker increments
fill at the same canonical limit price and every planned fee, spread, slippage,
funding and stop-risk component is linear in filled base quantity. Historical
funding is different: holding duration varies by tranche. Combining staged
fills with a historical funding schedule therefore fails closed until a
tranche-aware authority exists.

## Lineage and output

The v3 runtime result binds the existing dataset and plan identities plus the
queue result/trace hashes, the PHP settlement request/result hashes, consumed
fill count, exact filled and cancelled residual base quantities, every fill
source record and the terminal candle. `fills_are_certified=false`,
`costs_are_certified=false` and `result_is_live_proof=false` remain explicit.

Forged model instances, non-prefix fills, duplicate or non-monotonic chronology,
quantity mismatch, settlement substitution, a missing/wrong bridge, funding
evidence on staged fills, an open position at dataset end or an ambiguous
holding boundary fail closed with stable reason codes.

## Tests

Pure state-machine tests cover partial-at-deadline, multi-fill completion,
stop between fills, conservative stop on a fill bar, ignored target on a fill
bar and exact residual conservation. Runtime tests cover the PHP bridge request,
v3 hashes/outcome, forbidden historical funding, forged settlement and byte
determinism. Existing v1 and atomic v2 goldens remain unchanged.
