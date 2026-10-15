"""Exact decimal arithmetic of the H3 scoring engine ("scoring arithmetic v1"), reproducible in PHP with bcmath.

Every number the scoring engine computes (features, standardisation, heads, costs, uncertainty, utility) goes through
these functions, so that a PHP port can reproduce the scores byte for byte:

- every input is first truncated toward zero to ``SCALE`` = 18 decimal places (PHP: ``bcadd($x, '0', 18)``);
- ``add`` and ``sub`` are exact on such numbers (PHP: ``bcadd`` / ``bcsub`` with scale 18);
- ``mul`` and ``div`` compute the exact result and truncate it toward zero to 18 places (PHP: ``bcmul`` / ``bcdiv``
  with scale 18 truncate the same way, toward zero for negative results too);
- ``sqrt(x)`` for x >= 0 is the floor of the square root at 18 places: ``isqrt(x * 10**36) / 10**18`` with an exact
  integer square root (PHP: ``gmp_sqrt`` or an integer Newton iteration on bcmath integers; not ``bcsqrt``, whose last
  digit is not specified);
- every magnitude must stay below 10**30, otherwise ``ArithmeticDomainError``; with that bound a Decimal context of
  100 digits holds every product exactly and every quotient far below the 18th decimal, so truncating twice (context,
  then quantize) equals truncating once;
- comparisons are exact; the wire form is ``contracts.wire_decimal`` (no exponent, no trailing zeros, no "-0").

The order of operations of every formula is part of the specification (truncation is not associative); the formulas
are written once, in this package, and the PHP port must follow them literally. The golden vectors of
``scoring.parity`` check it.
"""
from __future__ import annotations

import math
from collections.abc import Iterable, Sequence
from decimal import ROUND_DOWN, Context, Decimal, DivisionByZero, InvalidOperation, Overflow
from typing import Any

from ..contracts import ContractError, dec

SCALE = 18
QUANTUM = Decimal(1).scaleb(-SCALE)
LIMIT = Decimal(10) ** 30
ZERO = Decimal(0)
ONE = Decimal(1)
TWO = Decimal(2)
_CTX = Context(prec=100, rounding=ROUND_DOWN, traps=[InvalidOperation, DivisionByZero, Overflow])
_SHIFT = 10 ** (2 * SCALE)


class ArithmeticDomainError(ValueError):
    """An operation is outside the scoring arithmetic domain. The message is a stable reason code."""


def q(value: Any) -> Decimal:
    """Finite Decimal truncated toward zero to 18 places. Refuses bool, None, NaN, infinities and |x| >= 10**30."""
    try:
        number = dec(value)
    except ContractError as error:
        raise ArithmeticDomainError('arith_number_invalid') from error
    if abs(number) >= LIMIT:
        raise ArithmeticDomainError('arith_magnitude_exceeded')
    result = number.quantize(QUANTUM, rounding=ROUND_DOWN, context=_CTX)
    return ZERO.quantize(QUANTUM) if result == 0 else result  # never a negative zero


def add(a: Any, b: Any) -> Decimal:
    return q(_CTX.add(q(a), q(b)))


def sub(a: Any, b: Any) -> Decimal:
    return q(_CTX.subtract(q(a), q(b)))


def mul(a: Any, b: Any) -> Decimal:
    return q(_CTX.multiply(q(a), q(b)))


def div(a: Any, b: Any) -> Decimal:
    divisor = q(b)
    if divisor == 0:
        raise ArithmeticDomainError('arith_division_by_zero')
    return q(_CTX.divide(q(a), divisor))


def neg(a: Any) -> Decimal:
    return q(_CTX.minus(q(a)))


def absolute(a: Any) -> Decimal:
    return q(_CTX.abs(q(a)))


def sqrt(a: Any) -> Decimal:
    value = q(a)
    if value < 0:
        raise ArithmeticDomainError('arith_sqrt_negative')
    scaled = int(value.scaleb(SCALE, context=_CTX))          # exact: value has at most 18 decimals
    return q(Decimal(math.isqrt(scaled * 10 ** SCALE)).scaleb(-SCALE, context=_CTX))


def total(values: Iterable[Any]) -> Decimal:
    """Exact sum, left to right (exact anyway: every term already has at most 18 decimals)."""
    result = ZERO
    for value in values:
        result = add(result, value)
    return result


def mean(values: Sequence[Any]) -> Decimal:
    if not values:
        raise ArithmeticDomainError('arith_empty_sequence')
    return div(total(values), len(values))


def median(values: Sequence[Any]) -> Decimal:
    """Middle value of the sorted values; the mean of the two middle values, ``div(add(a, b), 2)``, when even."""
    if not values:
        raise ArithmeticDomainError('arith_empty_sequence')
    ordered = sorted(q(v) for v in values)
    middle = len(ordered) // 2
    if len(ordered) % 2:
        return ordered[middle]
    return div(add(ordered[middle - 1], ordered[middle]), TWO)


def clamp(value: Any, low: Any, high: Any) -> Decimal:
    number, lo, hi = q(value), q(low), q(high)
    return lo if number < lo else hi if number > hi else number


def is_integral(value: Decimal) -> bool:
    return value == value.to_integral_value()


__all__ = ['SCALE', 'QUANTUM', 'LIMIT', 'ZERO', 'ONE', 'TWO', 'ArithmeticDomainError', 'q', 'add', 'sub', 'mul', 'div',
           'neg', 'absolute', 'sqrt', 'total', 'mean', 'median', 'clamp', 'is_integral']
