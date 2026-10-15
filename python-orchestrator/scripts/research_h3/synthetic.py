"""Builders of fully synthetic H3 contract values, shared by the tests of every H3 component.

Everything built here is labelled ``synthetic`` (provenance source ``synthetic``, label SYNTHETIC) and attached to the
DRAFT protocol ``h3-draft``. It never reads market data. Instrument specifications are invented round numbers shaped
like an OKX Europe X-Perp (USD prices, USDC settlement); they are not the venue's real specifications.
"""
from __future__ import annotations

from decimal import Decimal
from typing import Any

from .contracts import (
    Clock, DataLabel, Header, InstrumentRef, PolicyRef, Product, ProtocolRef, ProtocolStatus, Provenance, Side,
    sha256_text,
)

T0_MS = 1_767_225_600_000  # 2026-01-01T00:00:00Z, an arbitrary synthetic origin
HOUR_MS = 3_600_000

SYNTHETIC_PROVENANCE = Provenance(source='synthetic', dataset_id='synthetic-fixture', dataset_sha256=None,
                                  label=DataLabel.SYNTHETIC, license='synthetic')
DRAFT_PROTOCOL = ProtocolRef(protocol_id='h3-draft', protocol_version='0.1.0', status=ProtocolStatus.DRAFT)


def instrument(base: str = 'SYN', *, contract_value: Any = '0.01', tick_size: Any = '0.01', lot_size: Any = '1',
               min_size: Any = '1', min_notional: Any = None) -> InstrumentRef:
    return InstrumentRef(venue='synthetic', product=Product.SYNTHETIC, inst_id=f'{base}-USD-SYNTH', base=base,
                         quote='USD', settle_ccy='USDC', contract_value=Decimal(str(contract_value)),
                         tick_size=Decimal(str(tick_size)), lot_size=Decimal(str(lot_size)),
                         min_size=Decimal(str(min_size)),
                         min_notional=None if min_notional is None else Decimal(str(min_notional)))


def clock(decision_ms: int, *, observed_ms: int | None = None, available_ms: int | None = None) -> Clock:
    """Defaults: observed = available = decision (no latency)."""
    available = decision_ms if available_ms is None else available_ms
    return Clock(observed_ms=available if observed_ms is None else observed_ms, available_ms=available,
                 decision_ms=decision_ms)


def header(*, decision_ms: int = T0_MS, side: Side | None = Side.LONG, portfolio_id: str | None = None,
           inst: InstrumentRef | None = None, observed_ms: int | None = None,
           available_ms: int | None = None) -> Header:
    return Header(instrument=inst or instrument(), side=side,
                  clock=clock(decision_ms, observed_ms=observed_ms, available_ms=available_ms),
                  provenance=SYNTHETIC_PROVENANCE, protocol=DRAFT_PROTOCOL, portfolio_id=portfolio_id)


def policy_ref(policy_id: str, version: str = '0.1.0', body: str | None = None) -> PolicyRef:
    """A PolicyRef whose fingerprint hashes ``body`` (default: the id and version)."""
    return PolicyRef(policy_id=policy_id, policy_version=version,
                     policy_fingerprint=sha256_text(body if body is not None else f'{policy_id}@{version}'))
