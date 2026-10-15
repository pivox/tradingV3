"""Comparators outside the factorial: keeping the capital (no trading) and a passive exposure.

- Cash: no trade, no cost, the capital is kept; its per-block net PnL is 0. ``compare`` tests B3 against it (P2).
  Interest on idle USDC is not modelled (conservative for the comparator; stated in the pre-registration).
- Passive exposure: holding the instruments of the candidate universe in the generator's direction, at 1x, for the
  whole period, with the same fees and funding. It is meaningful only when every candidate has the same side (a
  directional generator) and the product can be held continuously (a perpetual-style contract). For a two-sided or
  market-neutral generator it is NOT_APPLICABLE: there is no passive position with the same exposure.
The passive curve is a descriptive comparator: it is never in the Holm family.
"""
from __future__ import annotations

from collections.abc import Mapping, Sequence
from decimal import Decimal

from ..contracts import ZERO, Bar, FundingObservation, OpportunityCandidate, Product, Side
from .metrics import block_edges

HOLDABLE_PRODUCTS = (Product.XPERP, Product.USDT_SWAP, Product.SYNTHETIC)


class ComparatorError(ValueError):
    """Invalid comparator input. The message is a stable reason code."""


def cash_block_returns(n_blocks: int) -> list[float]:
    return [0.0] * n_blocks


def passive_exposure_applicability(candidates: Sequence[OpportunityCandidate]) -> tuple[bool, str]:
    if not candidates:
        return False, 'comparator_passive_no_candidates'
    sides = {c.header.side for c in candidates}
    if len(sides) != 1:
        return False, 'comparator_passive_not_applicable_two_sided_generator'
    if any(c.header.instrument.product not in HOLDABLE_PRODUCTS for c in candidates):
        return False, 'comparator_passive_not_applicable_product'
    return True, 'comparator_passive_applicable'


def passive_pnl_path(bars: Sequence[Bar], funding: Sequence[FundingObservation], *, side: Side,
                     notional: Decimal, fee_rate: Decimal, start_ms: int, end_ms: int) -> list[tuple[int, Decimal]]:
    """(ts_ms, cumulative net PnL) of a position opened at the first bar open at or after ``start_ms`` and closed
    at the last bar close at or before ``end_ms``; fees on both sides; funding settled while held (positive rate:
    longs pay)."""
    usable = sorted((b for b in bars if b.open_ms >= start_ms and b.close_ms <= end_ms), key=lambda b: b.open_ms)
    if not usable or notional <= 0 or fee_rate < 0:
        raise ComparatorError('comparator_passive_input_invalid')
    sign = Decimal(1) if side is Side.LONG else Decimal(-1)
    entry = usable[0].open
    units = notional / entry
    entry_fee = notional * fee_rate
    settlements = sorted((f for f in funding if f.inst_id == usable[0].inst_id
                          and usable[0].open_ms < f.funding_ms <= usable[-1].close_ms), key=lambda f: f.funding_ms)
    path = [(usable[0].open_ms, -entry_fee)]
    funding_paid = ZERO
    k = 0
    for bar in usable:
        while k < len(settlements) and settlements[k].funding_ms <= bar.close_ms:
            funding_paid += sign * settlements[k].rate * units * bar.close
            k += 1
        path.append((bar.close_ms, sign * units * (bar.close - entry) - entry_fee - funding_paid))
    exit_fee = units * usable[-1].close * fee_rate
    last_ms, last_pnl = path[-1]
    path[-1] = (last_ms, last_pnl - exit_fee)
    return path


def basket_pnl_path(paths: Mapping[str, Sequence[tuple[int, Decimal]]]) -> list[tuple[int, Decimal]]:
    """Sum of per-instrument paths (each carried forward between its own points)."""
    times = sorted({ts for path in paths.values() for ts, _ in path})
    out = []
    for ts in times:
        total = ZERO
        for path in paths.values():
            last = ZERO
            for t, v in path:
                if t > ts:
                    break
                last = v
            total += last
        out.append((ts, total))
    return out


def path_block_returns(path: Sequence[tuple[int, Decimal]], *, start_ms: int, end_ms: int, block_ms: int,
                       initial_equity: Decimal) -> list[float]:
    """Per-block change of the path, as a fraction of ``initial_equity``, on the same edges as ``metrics``."""
    edges = block_edges(start_ms, end_ms, block_ms)

    def value_at(ts: int) -> Decimal:
        last = ZERO
        for t, v in path:
            if t > ts:
                break
            last = v
        return last

    values = [ZERO] + [value_at(edge) for edge in edges[1:]]  # nothing is held before the start
    return [float((b - a) / initial_equity) for a, b in zip(values, values[1:])]
