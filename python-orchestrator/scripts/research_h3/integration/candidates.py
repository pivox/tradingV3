"""Synthetic candidate provider for integration tests. It makes NO economic claim.

``SyntheticCandidateProvider`` implements ``contracts.CandidateProvider`` so that the risk gate, the simulator and the
audit can be exercised end to end before a real candidate source exists. Its hypothesis id is
``synthetic-no-economic-claim``: it is a deterministic fixture, not a generator of opportunities, and it must never
feed an experiment on real data. The real common generator must rest on an explicit economic hypothesis (one of the
candidate-source dossiers CS-FUND, CS-UNLOCK, CS-XMOM, or another), independent of any score.

Rules: one candidate per snapshot whose two synthetic features (reference price, stop distance) are OK and whose
decision instant is not after the call's; the side is either fixed or drawn from a hash of (seed, snapshot id): a
coin flip, deliberately uninformative. Prices are put on the tick grid, the stop rounded away from the price (never
tighter). Candidate ids are derived from the inputs. No score is read.
"""
from __future__ import annotations

import dataclasses
from collections.abc import Sequence
from dataclasses import dataclass
from decimal import Decimal
from typing import Any

from ..contracts import (
    ONE, Clock, DataStatus, FeatureSnapshot, FeatureStatus, FeatureValue, Header, InstrumentRef,
    OpportunityCandidate, Provenance, ProtocolRef, Side, canonical_json, dec, sha256_text,
)
from .costs import derived_id, round_stop_away

GENERATOR_ID = 'h3-synthetic-candidates'
GENERATOR_VERSION = '0.1.0'
HYPOTHESIS_ID = 'synthetic-no-economic-claim'
PRICE_FEATURE = 'synthetic_reference_price'
STOP_DISTANCE_FEATURE = 'synthetic_stop_distance'
DAY_MS = 86_400_000


@dataclass(frozen=True)
class SyntheticCandidateProvider:
    """DRAFT defaults: ``max_holding_ms`` one day and no target (``target_r_multiple`` None) are fixture choices,
    not tuned values."""

    seed: int = 0
    side: Side | None = None
    max_holding_ms: int = DAY_MS
    target_r_multiple: Decimal | None = None
    generator_id: str = GENERATOR_ID
    generator_version: str = GENERATOR_VERSION
    hypothesis_id: str = HYPOTHESIS_ID

    def _side(self, snapshot: FeatureSnapshot) -> Side:
        if self.side is not None:
            return self.side
        digest = sha256_text(canonical_json([self.seed, snapshot.snapshot_id, snapshot.header.instrument.inst_id]))
        return Side.LONG if int(digest[-1], 16) % 2 == 0 else Side.SHORT

    def candidates(self, snapshots: Sequence[FeatureSnapshot], decision_ms: int) -> tuple[OpportunityCandidate, ...]:
        out: list[OpportunityCandidate] = []
        for snapshot in sorted(snapshots, key=lambda s: (s.header.clock.decision_ms, s.snapshot_id)):
            clock = snapshot.header.clock
            price, distance = snapshot.value(PRICE_FEATURE), snapshot.value(STOP_DISTANCE_FEATURE)
            if clock.decision_ms > decision_ms or price is None or distance is None:
                continue
            if price.status is not FeatureStatus.OK or distance.status is not FeatureStatus.OK:
                continue
            assert price.value is not None and distance.value is not None
            inst = snapshot.header.instrument
            side = self._side(snapshot)
            entry = (price.value / inst.tick_size).to_integral_value() * inst.tick_size
            if entry <= 0 or distance.value <= 0:
                continue
            sign = ONE if side is Side.LONG else -ONE
            stop = round_stop_away(side, entry - sign * distance.value, inst.tick_size)
            if stop <= 0:
                continue
            target = None
            if self.target_r_multiple is not None:
                target = round_stop_away(Side.SHORT if side is Side.LONG else Side.LONG,
                                         entry + sign * abs(entry - stop) * self.target_r_multiple, inst.tick_size)
            header = dataclasses.replace(snapshot.header, side=side, portfolio_id=None,
                                         clock=Clock(observed_ms=clock.observed_ms, available_ms=clock.available_ms,
                                                     decision_ms=decision_ms))
            candidate_id = derived_id('cand', self.generator_id, self.generator_version, snapshot.fingerprint(),
                                      side.value, decision_ms)
            out.append(OpportunityCandidate(header=header, candidate_id=candidate_id, generator_id=self.generator_id,
                                            generator_version=self.generator_version,
                                            hypothesis_id=self.hypothesis_id, snapshot=snapshot.ref(),
                                            entry_reference_price=entry, initial_stop_price=stop,
                                            initial_target_price=target, max_holding_ms=self.max_holding_ms))
        return tuple(out)


def synthetic_snapshot(*, inst: InstrumentRef, decision_ms: int, reference_price: Any, stop_distance: Any,
                       provenance: Provenance, protocol: ProtocolRef, snapshot_id: str | None = None,
                       observed_ms: int | None = None) -> FeatureSnapshot:
    """A side-agnostic snapshot with the two synthetic features the provider reads (observed = available =
    ``observed_ms`` or the decision instant)."""
    observed = decision_ms if observed_ms is None else observed_ms
    features = tuple(sorted((
        FeatureValue(name=PRICE_FEATURE, value=dec(reference_price), status=FeatureStatus.OK, observed_ms=observed,
                     available_ms=observed, unit='quote_price'),
        FeatureValue(name=STOP_DISTANCE_FEATURE, value=dec(stop_distance), status=FeatureStatus.OK,
                     observed_ms=observed, available_ms=observed, unit='quote_price'),
    ), key=lambda f: f.name))
    header = Header(instrument=inst, side=None,
                    clock=Clock(observed_ms=observed, available_ms=observed, decision_ms=decision_ms),
                    provenance=provenance, protocol=protocol, portfolio_id=None)
    return FeatureSnapshot(header=header, snapshot_id=snapshot_id or f'snap-{inst.inst_id}-{decision_ms}',
                           feature_spec_id='h3-synthetic-integration', feature_spec_version='0.1.0',
                           features=features, data_status=DataStatus.COMPLETE)
