"""H3 shared contracts, schema version 1.

These contracts are the only interface between the four H3 components: the opportunity scoring engine, the adaptive
position manager, the validation framework and the TradingCore/risk integration. Each component may define private
types inside its own package, but anything that crosses a component boundary is one of the contracts below.

Research prototype. Nothing here reads market data, calls an exchange or a database, or depends on the Symfony or
Temporal runtime.

Wire form. Every contract is a frozen dataclass whose wire form is canonical JSON: keys sorted, no whitespace,
Decimals as plain strings (no exponent, no trailing zeros, no negative zero), times as UTC epoch milliseconds, enums
as their values, tuples as lists, and a ``schema`` key on every object. ``fingerprint()`` is "sha256:" followed by
the sha256 of that JSON. The form is language-neutral so that a PHP implementation can reproduce it.

Time rule (anti-lookahead), shared by every decision contract: observed_ms <= available_ms <= decision_ms.
- ``observed_ms``: the end of the last observation used, i.e. the exclusive close time of the last closed bar.
- ``available_ms``: when that data was really available to the decision process, publication or receipt latency
  included.
- ``decision_ms``: the instant of the decision.
A feature whose ``available_ms`` is after the decision is refused at construction.

H3 risk invariants. ``H3_LIMITS`` holds the experimental policy: 0.5 % nominal risk per trade, 1 % total open risk,
leverage at most 1x, a mandatory stop. An ADMIT ``RiskDecision`` that breaks one of them cannot be constructed. These
limits are the H3 experimental policy only; they do not change any production configuration.
"""
from __future__ import annotations

import dataclasses
import enum
import hashlib
import json
import math
import re
import typing
from collections.abc import Sequence
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from typing import Any, ClassVar, Protocol

SCHEMA_VERSION = 1
D = Decimal
ZERO = D('0')
ONE = D('1')

_ID = re.compile(r'\A[A-Za-z0-9][A-Za-z0-9._:@/+-]{0,159}\Z')
_SHA = re.compile(r'\Asha256:[0-9a-f]{64}\Z')
_REASON = re.compile(r'\A[a-z0-9][a-z0-9_.:-]{0,95}\Z')


class ContractError(ValueError):
    """A value violates an H3 contract. The message is a stable reason code."""


# --------------------------------------------------------------------------------------------------------- numbers

def dec(value: Any) -> Decimal:
    """Convert to a finite Decimal; refuse bool, None, NaN and infinities. Floats go through repr()."""
    if isinstance(value, bool) or value is None:
        raise ContractError('h3_number_invalid')
    if isinstance(value, Decimal):
        result = value
    elif isinstance(value, int):
        result = D(value)
    elif isinstance(value, float):
        if not math.isfinite(value):
            raise ContractError('h3_number_non_finite')
        result = D(repr(value))
    elif isinstance(value, str):
        try:
            result = D(value)
        except InvalidOperation as error:
            raise ContractError('h3_number_invalid') from error
    elif hasattr(value, 'item') and callable(value.item):  # numpy scalar
        return dec(value.item())
    else:
        raise ContractError('h3_number_invalid')
    if not result.is_finite():
        raise ContractError('h3_number_non_finite')
    return result


def wire_decimal(value: Decimal) -> str:
    """Plain, normalized decimal string: '1.5', '100', '0', never '1.50', '1E+2' or '-0'."""
    if value == 0:
        return '0'
    text = format(value.normalize(), 'f')
    if '.' in text:
        text = text.rstrip('0').rstrip('.')
    return text


def canonical_json(document: Any) -> str:
    return json.dumps(document, sort_keys=True, separators=(',', ':'), ensure_ascii=False, allow_nan=False)


def sha256_text(text: str) -> str:
    return 'sha256:' + hashlib.sha256(text.encode('utf-8')).hexdigest()


def check_id(value: Any, code: str = 'h3_identifier_invalid') -> str:
    if not isinstance(value, str) or not _ID.match(value):
        raise ContractError(code)
    return value


def check_sha(value: Any, code: str = 'h3_fingerprint_invalid') -> str:
    if not isinstance(value, str) or not _SHA.match(value):
        raise ContractError(code)
    return value


def check_reasons(values: tuple[str, ...]) -> None:
    for reason in values:
        if not isinstance(reason, str) or not _REASON.match(reason):
            raise ContractError('h3_reason_code_invalid')
    if len(set(values)) != len(values):
        raise ContractError('h3_reason_code_duplicate')


def _ms(value: Any, code: str) -> None:
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        raise ContractError(code)


# ----------------------------------------------------------------------------------------------------------- enums

class Side(str, enum.Enum):
    LONG = 'long'
    SHORT = 'short'


class Product(str, enum.Enum):
    XPERP = 'xperp'            # OKX Europe X-Perps: USD prices, USDC margin
    USDT_SWAP = 'usdt_swap'    # classic USDT-margined swaps (statistics only for a French resident)
    SPOT = 'spot'
    SYNTHETIC = 'synthetic'


class DataLabel(str, enum.Enum):
    SYNTHETIC = 'synthetic'
    DEVELOPMENT = 'development'    # already read, or allowed for development only
    VALIDATION = 'validation'
    HOLDOUT = 'holdout'
    PROSPECTIVE = 'prospective'


class DataStatus(str, enum.Enum):
    COMPLETE = 'complete'
    PARTIAL = 'partial'
    MISSING = 'missing'
    STALE = 'stale'


class FeatureStatus(str, enum.Enum):
    OK = 'ok'
    MISSING = 'missing'
    STALE = 'stale'
    NON_FINITE = 'non_finite'


class ProtocolStatus(str, enum.Enum):
    DRAFT = 'DRAFT'
    FROZEN = 'FROZEN'


class ScoreStatus(str, enum.Enum):
    ESTIMATED = 'estimated'          # numbers come from a calibrated model
    UNESTIMATED = 'unestimated'      # no calibrated model: no numeric estimate is produced
    INVALID_INPUT = 'invalid_input'
    MODEL_INVALID = 'model_invalid'


class ScoreVerdict(str, enum.Enum):
    TRADE_CANDIDATE = 'TRADE_CANDIDATE'
    NO_TRADE = 'NO_TRADE'


class ActionKind(str, enum.Enum):
    HOLD = 'HOLD'
    TIGHTEN_STOP = 'TIGHTEN_STOP'
    ADJUST_TARGET = 'ADJUST_TARGET'
    PARTIAL_CLOSE = 'PARTIAL_CLOSE'
    FULL_CLOSE = 'FULL_CLOSE'


class PositionStatus(str, enum.Enum):
    OPEN = 'open'
    CLOSED = 'closed'


class LiquidityStatus(str, enum.Enum):
    NORMAL = 'normal'
    THIN = 'thin'
    UNKNOWN = 'unknown'


class RiskSubject(str, enum.Enum):
    ENTRY = 'entry'
    ACTION = 'action'


class RiskVerdict(str, enum.Enum):
    ADMIT = 'ADMIT'
    REJECT = 'REJECT'


class Variant(str, enum.Enum):
    B0 = 'B0'   # reference entries, static management
    B1 = 'B1'   # scored entries, static management
    B2 = 'B2'   # reference entries, adaptive management
    B3 = 'B3'   # scored entries, adaptive management


class AuditEvent(str, enum.Enum):
    CANDIDATE = 'candidate'
    SCORE = 'score'
    RISK = 'risk'
    ORDER = 'order'
    FILL = 'fill'
    ACTION = 'action'
    EXIT = 'exit'
    REJECT = 'reject'
    KILL_SWITCH = 'kill_switch'


class ModelKind(str, enum.Enum):
    REFERENCE_NULL = 'reference_null'      # no model: scores are UNESTIMATED
    LINEAR = 'linear'
    RIDGE = 'ridge'
    SIMPLE_NONLINEAR = 'simple_nonlinear'


class CalibrationStatus(str, enum.Enum):
    UNCALIBRATED = 'uncalibrated'
    CALIBRATED = 'calibrated'


# ------------------------------------------------------------------------------------------------------- base class

def _wire(value: Any) -> Any:
    if isinstance(value, Contract):
        return value.to_wire()
    if isinstance(value, enum.Enum):
        return value.value
    if isinstance(value, Decimal):
        return wire_decimal(value)
    if isinstance(value, tuple):
        return [_wire(item) for item in value]
    if value is None or isinstance(value, (str, int, bool)):
        return value
    raise ContractError('h3_wire_type_invalid')


def _strip_optional(tp: Any) -> tuple[Any, bool]:
    args = typing.get_args(tp)
    if args and type(None) in args:
        rest = [a for a in args if a is not type(None)]
        if len(rest) == 1:
            return rest[0], True
    return tp, False


def _coerce(tp: Any, value: Any, *, wire: bool) -> Any:
    tp, optional = _strip_optional(tp)
    if value is None:
        if optional:
            return None
        raise ContractError('h3_field_required')
    origin = typing.get_origin(tp)
    if origin is tuple:
        item_type = typing.get_args(tp)[0]
        if isinstance(value, (str, bytes)) or not isinstance(value, (list, tuple)):
            raise ContractError('h3_sequence_invalid')
        return tuple(_coerce(item_type, item, wire=wire) for item in value)
    if isinstance(tp, type) and issubclass(tp, Contract):
        if isinstance(value, tp):
            return value
        if wire and isinstance(value, dict):
            return tp.from_wire(value)
        raise ContractError('h3_nested_contract_invalid')
    if tp is Decimal:
        if wire and not isinstance(value, str):
            raise ContractError('h3_wire_decimal_must_be_string')
        return dec(value)
    if isinstance(tp, type) and issubclass(tp, enum.Enum):
        try:
            return tp(value)
        except ValueError as error:
            raise ContractError('h3_enum_invalid') from error
    if tp is bool:
        if not isinstance(value, bool):
            raise ContractError('h3_bool_invalid')
        return value
    if tp is int:
        if isinstance(value, bool) or not isinstance(value, int):
            raise ContractError('h3_int_invalid')
        return value
    if tp is str:
        if not isinstance(value, str):
            raise ContractError('h3_str_invalid')
        return value
    raise ContractError('h3_field_type_unsupported')


class Contract:
    """Frozen, canonical, fingerprintable value. Subclasses are frozen dataclasses with a ``SCHEMA`` id."""

    SCHEMA: ClassVar[str] = ''

    def __post_init__(self) -> None:
        hints = typing.get_type_hints(type(self))
        for f in dataclasses.fields(self):  # type: ignore[arg-type]
            object.__setattr__(self, f.name, _coerce(hints[f.name], getattr(self, f.name), wire=False))
        self._validate()

    def _validate(self) -> None:  # overridden
        pass

    def to_wire(self) -> dict[str, Any]:
        document = {f.name: _wire(getattr(self, f.name)) for f in dataclasses.fields(self)}  # type: ignore[arg-type]
        document['schema'] = self.SCHEMA
        return document

    def canonical_json(self) -> str:
        return canonical_json(self.to_wire())

    def fingerprint(self) -> str:
        return sha256_text(self.canonical_json())

    @classmethod
    def from_wire(cls, document: Any) -> Any:
        if not isinstance(document, dict):
            raise ContractError('h3_wire_document_invalid')
        if document.get('schema') != cls.SCHEMA:
            raise ContractError('h3_schema_mismatch')
        hints = typing.get_type_hints(cls)
        names = [f.name for f in dataclasses.fields(cls)]  # type: ignore[arg-type]
        if set(document) - {'schema'} != set(names):
            raise ContractError('h3_wire_fields_mismatch')
        return cls(**{name: _coerce(hints[name], document[name], wire=True) for name in names})

    @classmethod
    def from_json(cls, text: str) -> Any:
        return cls.from_wire(json.loads(text, parse_float=_refuse_float))


def _refuse_float(_: str) -> Any:
    raise ContractError('h3_wire_float_forbidden')


# ------------------------------------------------------------------------------------------------ shared limits

@dataclass(frozen=True)
class H3Limits(Contract):
    """H3 experimental risk policy. Fixed by the mission; not a tunable."""

    SCHEMA: ClassVar[str] = 'h3.limits/1'
    per_trade_risk_max: Decimal
    total_risk_max: Decimal
    leverage_max: Decimal
    reference_equity: Decimal
    stop_required: bool
    adaptation_may_increase_risk: bool

    def _validate(self) -> None:
        if not (ZERO < self.per_trade_risk_max <= self.total_risk_max < ONE) or self.leverage_max <= 0:
            raise ContractError('h3_limits_invalid')
        if not self.stop_required or self.adaptation_may_increase_risk:
            raise ContractError('h3_limits_invalid')


H3_LIMITS = H3Limits(per_trade_risk_max=D('0.005'), total_risk_max=D('0.01'), leverage_max=D('1'),
                     reference_equity=D('500'), stop_required=True, adaptation_may_increase_risk=False)


# --------------------------------------------------------------------------------------------- identity blocks

@dataclass(frozen=True)
class Clock(Contract):
    SCHEMA: ClassVar[str] = 'h3.clock/1'
    observed_ms: int
    available_ms: int
    decision_ms: int

    def _validate(self) -> None:
        for value in (self.observed_ms, self.available_ms, self.decision_ms):
            _ms(value, 'h3_clock_invalid')
        if not self.observed_ms <= self.available_ms <= self.decision_ms:
            raise ContractError('h3_clock_lookahead')


@dataclass(frozen=True)
class InstrumentRef(Contract):
    """Instrument and product identity. Quantities are in contracts; notional = qty x contract_value x price."""

    SCHEMA: ClassVar[str] = 'h3.instrument/1'
    venue: str
    product: Product
    inst_id: str
    base: str
    quote: str
    settle_ccy: str
    contract_value: Decimal
    tick_size: Decimal
    lot_size: Decimal
    min_size: Decimal
    min_notional: Decimal | None

    def _validate(self) -> None:
        for value in (self.venue, self.inst_id, self.base, self.quote, self.settle_ccy):
            check_id(value, 'h3_instrument_identity_invalid')
        if min(self.contract_value, self.tick_size, self.lot_size, self.min_size) <= 0:
            raise ContractError('h3_instrument_spec_invalid')
        if self.min_notional is not None and self.min_notional < 0:
            raise ContractError('h3_instrument_spec_invalid')


@dataclass(frozen=True)
class Provenance(Contract):
    SCHEMA: ClassVar[str] = 'h3.provenance/1'
    source: str
    dataset_id: str
    dataset_sha256: str | None
    label: DataLabel
    license: str

    def _validate(self) -> None:
        check_id(self.source)
        check_id(self.dataset_id)
        check_id(self.license)
        if self.dataset_sha256 is not None:
            check_sha(self.dataset_sha256)
        if (self.label is DataLabel.SYNTHETIC) != (self.source == 'synthetic'):
            raise ContractError('h3_provenance_synthetic_mismatch')
        if self.label is not DataLabel.SYNTHETIC and self.dataset_sha256 is None:
            raise ContractError('h3_provenance_real_data_needs_sha256')


@dataclass(frozen=True)
class ProtocolRef(Contract):
    SCHEMA: ClassVar[str] = 'h3.protocol_ref/1'
    protocol_id: str
    protocol_version: str
    status: ProtocolStatus

    def _validate(self) -> None:
        check_id(self.protocol_id)
        check_id(self.protocol_version)


@dataclass(frozen=True)
class ModelRef(Contract):
    """Model identity. ``model_fingerprint`` is the ModelManifest fingerprint; None means no model at all."""

    SCHEMA: ClassVar[str] = 'h3.model_ref/1'
    model_id: str
    model_version: str
    model_fingerprint: str | None

    def _validate(self) -> None:
        check_id(self.model_id)
        check_id(self.model_version)
        if self.model_fingerprint is not None:
            check_sha(self.model_fingerprint)


@dataclass(frozen=True)
class PolicyRef(Contract):
    SCHEMA: ClassVar[str] = 'h3.policy_ref/1'
    policy_id: str
    policy_version: str
    policy_fingerprint: str

    def _validate(self) -> None:
        check_id(self.policy_id)
        check_id(self.policy_version)
        check_sha(self.policy_fingerprint)


@dataclass(frozen=True)
class SnapshotRef(Contract):
    SCHEMA: ClassVar[str] = 'h3.snapshot_ref/1'
    snapshot_id: str
    fingerprint: str

    def _validate(self) -> None:
        check_id(self.snapshot_id)
        check_sha(self.fingerprint)


@dataclass(frozen=True)
class Header(Contract):
    """Fields every decision contract carries.

    ``side`` is None only on a side-agnostic FeatureSnapshot. ``portfolio_id`` is None before a portfolio is involved
    (features, candidates, scores): the same candidates feed the four independent variant portfolios.
    """

    SCHEMA: ClassVar[str] = 'h3.header/1'
    instrument: InstrumentRef
    side: Side | None
    clock: Clock
    provenance: Provenance
    protocol: ProtocolRef
    portfolio_id: str | None

    def _validate(self) -> None:
        if self.portfolio_id is not None:
            check_id(self.portfolio_id)


@dataclass(frozen=True)
class NamedValue(Contract):
    SCHEMA: ClassVar[str] = 'h3.named_value/1'
    name: str
    value: Decimal

    def _validate(self) -> None:
        check_id(self.name)


def _sorted_unique(names: Sequence[str], code: str) -> None:
    if list(names) != sorted(set(names)):
        raise ContractError(code)


def _require_side(header: Header) -> Side:
    if header.side is None:
        raise ContractError('h3_side_required')
    return header.side


def stop_is_protective(side: Side, reference_price: Decimal, stop_price: Decimal) -> bool:
    """A stop protects a long below the reference price and a short above it."""
    return stop_price < reference_price if side is Side.LONG else stop_price > reference_price


def stop_tightens(side: Side, current_stop: Decimal, new_stop: Decimal) -> bool:
    """True when ``new_stop`` moves the stop towards the price (never away). Equal is not a tightening."""
    return new_stop > current_stop if side is Side.LONG else new_stop < current_stop


def stop_not_wider(side: Side, reference_stop: Decimal, stop: Decimal) -> bool:
    return stop >= reference_stop if side is Side.LONG else stop <= reference_stop


# ---------------------------------------------------------------------------------------------------- market data

@dataclass(frozen=True)
class Bar(Contract):
    """One OHLCV bar. ``close_ms`` is exclusive (= open_ms + interval_ms). A bar may be used by a decision only when
    ``is_closed`` and ``available_ms <= decision_ms``; ``available_ms`` includes the publication/receipt latency."""

    SCHEMA: ClassVar[str] = 'h3.bar/1'
    inst_id: str
    interval_ms: int
    open_ms: int
    close_ms: int
    available_ms: int
    is_closed: bool
    open: Decimal
    high: Decimal
    low: Decimal
    close: Decimal
    volume_quote: Decimal

    def _validate(self) -> None:
        check_id(self.inst_id)
        for value in (self.open_ms, self.close_ms, self.available_ms):
            _ms(value, 'h3_bar_time_invalid')
        if self.interval_ms <= 0 or self.close_ms != self.open_ms + self.interval_ms:
            raise ContractError('h3_bar_time_invalid')
        if self.is_closed and self.available_ms < self.close_ms:
            raise ContractError('h3_bar_available_before_close')
        if min(self.open, self.high, self.low, self.close) <= 0 or self.volume_quote < 0:
            raise ContractError('h3_bar_price_invalid')
        if not (self.low <= min(self.open, self.close) and max(self.open, self.close) <= self.high):
            raise ContractError('h3_bar_ohlc_inconsistent')

    def usable_at(self, decision_ms: int) -> bool:
        return self.is_closed and self.available_ms <= decision_ms


@dataclass(frozen=True)
class Quote(Contract):
    """Top of book at ``observed_ms``, received at ``available_ms``."""

    SCHEMA: ClassVar[str] = 'h3.quote/1'
    inst_id: str
    observed_ms: int
    available_ms: int
    bid: Decimal
    ask: Decimal
    bid_size: Decimal
    ask_size: Decimal

    def _validate(self) -> None:
        check_id(self.inst_id)
        _ms(self.observed_ms, 'h3_quote_time_invalid')
        _ms(self.available_ms, 'h3_quote_time_invalid')
        if self.observed_ms > self.available_ms:
            raise ContractError('h3_quote_time_invalid')
        if self.bid <= 0 or self.ask < self.bid or min(self.bid_size, self.ask_size) < 0:
            raise ContractError('h3_quote_invalid')


@dataclass(frozen=True)
class FundingObservation(Contract):
    """A funding settlement: ``rate`` is the fraction paid by longs to shorts at ``funding_ms`` (negative: shorts
    pay). ``available_ms`` is when the settled rate was known."""

    SCHEMA: ClassVar[str] = 'h3.funding_observation/1'
    inst_id: str
    funding_ms: int
    available_ms: int
    rate: Decimal

    def _validate(self) -> None:
        check_id(self.inst_id)
        _ms(self.funding_ms, 'h3_funding_time_invalid')
        _ms(self.available_ms, 'h3_funding_time_invalid')
        if self.available_ms < self.funding_ms:
            raise ContractError('h3_funding_time_invalid')


# ------------------------------------------------------------------------------------------------------- features

@dataclass(frozen=True)
class FeatureValue(Contract):
    SCHEMA: ClassVar[str] = 'h3.feature_value/1'
    name: str
    value: Decimal | None
    status: FeatureStatus
    observed_ms: int
    available_ms: int
    unit: str

    def _validate(self) -> None:
        check_id(self.name)
        check_id(self.unit)
        _ms(self.observed_ms, 'h3_feature_time_invalid')
        _ms(self.available_ms, 'h3_feature_time_invalid')
        if self.observed_ms > self.available_ms:
            raise ContractError('h3_feature_time_invalid')
        if (self.status is FeatureStatus.OK) != (self.value is not None):
            raise ContractError('h3_feature_status_value_mismatch')


def derive_data_status(features: Sequence[FeatureValue]) -> DataStatus:
    if not features:
        return DataStatus.MISSING
    statuses = [f.status for f in features]
    if FeatureStatus.STALE in statuses:
        return DataStatus.STALE
    ok = statuses.count(FeatureStatus.OK)
    if ok == len(statuses):
        return DataStatus.COMPLETE
    return DataStatus.MISSING if ok == 0 else DataStatus.PARTIAL


@dataclass(frozen=True)
class FeatureSnapshot(Contract):
    """Features observable for one instrument at one decision instant. Features are sorted by name."""

    SCHEMA: ClassVar[str] = 'h3.feature_snapshot/1'
    header: Header
    snapshot_id: str
    feature_spec_id: str
    feature_spec_version: str
    features: tuple[FeatureValue, ...]
    data_status: DataStatus

    def _validate(self) -> None:
        check_id(self.snapshot_id)
        check_id(self.feature_spec_id)
        check_id(self.feature_spec_version)
        _sorted_unique([f.name for f in self.features], 'h3_feature_names_not_sorted_unique')
        decision = self.header.clock.decision_ms
        for feature in self.features:
            if feature.available_ms > decision or feature.observed_ms > self.header.clock.observed_ms:
                raise ContractError('h3_feature_after_decision')
        if self.data_status is not derive_data_status(self.features):
            raise ContractError('h3_feature_data_status_mismatch')

    def ref(self) -> SnapshotRef:
        return SnapshotRef(snapshot_id=self.snapshot_id, fingerprint=self.fingerprint())

    def value(self, name: str) -> FeatureValue | None:
        return next((f for f in self.features if f.name == name), None)


# ----------------------------------------------------------------------------------------------- candidates/scores

@dataclass(frozen=True)
class OpportunityCandidate(Contract):
    """A trade candidate from the common generator, independent of any score.

    ``hypothesis_id`` names the explicit economic hypothesis of the generator. The stop is mandatory and must be
    protective; the optional target must be on the profit side.
    """

    SCHEMA: ClassVar[str] = 'h3.opportunity_candidate/1'
    header: Header
    candidate_id: str
    generator_id: str
    generator_version: str
    hypothesis_id: str
    snapshot: SnapshotRef
    entry_reference_price: Decimal
    initial_stop_price: Decimal
    initial_target_price: Decimal | None
    max_holding_ms: int

    def _validate(self) -> None:
        side = _require_side(self.header)
        for value in (self.candidate_id, self.generator_id, self.generator_version, self.hypothesis_id):
            check_id(value)
        if self.entry_reference_price <= 0 or self.initial_stop_price <= 0:
            raise ContractError('h3_candidate_price_invalid')
        if not stop_is_protective(side, self.entry_reference_price, self.initial_stop_price):
            raise ContractError('h3_candidate_stop_not_protective')
        if self.initial_target_price is not None and not stop_is_protective(
                Side.SHORT if side is Side.LONG else Side.LONG, self.entry_reference_price,
                self.initial_target_price):
            raise ContractError('h3_candidate_target_wrong_side')
        if isinstance(self.max_holding_ms, bool) or self.max_holding_ms <= 0:
            raise ContractError('h3_candidate_max_holding_invalid')

    @property
    def stop_distance(self) -> Decimal:
        return abs(self.entry_reference_price - self.initial_stop_price)


@dataclass(frozen=True)
class OpportunityScore(Contract):
    """Scoring output. Without a calibrated model, no numeric estimate may be produced (status UNESTIMATED).

    ``expected_net_r``, ``p_stop``, ``tail_loss_r`` and ``utility`` exist only when ``status`` is ESTIMATED.
    ``uncertainty`` and ``data_quality`` are in [0, 1] when present. ``rank`` (1 = best) is set by batch ranking only.
    """

    SCHEMA: ClassVar[str] = 'h3.opportunity_score/1'
    header: Header
    candidate_id: str
    snapshot: SnapshotRef
    model: ModelRef
    status: ScoreStatus
    verdict: ScoreVerdict
    expected_net_r: Decimal | None
    p_stop: Decimal | None
    tail_loss_r: Decimal | None
    uncertainty: Decimal | None
    data_quality: Decimal
    utility: Decimal | None
    rank: int | None
    reasons: tuple[str, ...]

    def _validate(self) -> None:
        _require_side(self.header)
        check_id(self.candidate_id)
        check_reasons(self.reasons)
        estimates = (self.expected_net_r, self.p_stop, self.tail_loss_r, self.utility)
        if self.status is ScoreStatus.ESTIMATED:
            if self.model.model_fingerprint is None or any(v is None for v in estimates[:3]):
                raise ContractError('h3_score_estimate_incomplete')
        elif any(v is not None for v in estimates):
            raise ContractError('h3_score_estimate_without_model')
        if self.status in (ScoreStatus.INVALID_INPUT, ScoreStatus.MODEL_INVALID) \
                and self.verdict is not ScoreVerdict.NO_TRADE:
            raise ContractError('h3_score_invalid_must_abstain')
        if self.verdict is ScoreVerdict.NO_TRADE and not self.reasons:
            raise ContractError('h3_score_no_trade_needs_reason')
        if self.p_stop is not None and not ZERO <= self.p_stop <= ONE:
            raise ContractError('h3_score_probability_invalid')
        if self.tail_loss_r is not None and self.tail_loss_r < 0:
            raise ContractError('h3_score_tail_loss_invalid')
        for value in (self.uncertainty, self.data_quality):
            if value is not None and not ZERO <= value <= ONE:
                raise ContractError('h3_score_unit_interval_invalid')
        if self.rank is not None and (isinstance(self.rank, bool) or self.rank < 1):
            raise ContractError('h3_score_rank_invalid')


# ---------------------------------------------------------------------------------------------------- positions

@dataclass(frozen=True)
class PortfolioView(Contract):
    """What a decision may know about its variant portfolio. Amounts are in ``quote_ccy``."""

    SCHEMA: ClassVar[str] = 'h3.portfolio_view/1'
    portfolio_id: str
    variant: Variant
    quote_ccy: str
    clock: Clock
    equity: Decimal
    cash: Decimal
    open_risk: Decimal
    open_notional: Decimal
    open_position_ids: tuple[str, ...]
    kill_switch: bool

    def _validate(self) -> None:
        check_id(self.portfolio_id)
        check_id(self.quote_ccy)
        _sorted_unique(self.open_position_ids, 'h3_portfolio_positions_not_sorted_unique')
        if self.open_risk < 0 or self.open_notional < 0:
            raise ContractError('h3_portfolio_amount_invalid')


@dataclass(frozen=True)
class PositionState(Contract):
    """Observable state of one open (or just closed) simulated position at ``header.clock.decision_ms``.

    Prices are per unit of the base, quantities in contracts. ``mfe_price`` / ``mae_price`` are the most favourable
    / adverse prices seen since entry up to ``observed_ms`` (entry included). ``initial_risk_amount`` is the R unit
    in quote currency. ``funding_paid`` is positive when paid. ``open_risk_total`` is the portfolio's open risk
    including this position; ``risk_budget_remaining`` = H3 total limit x equity - open_risk_total.
    """

    SCHEMA: ClassVar[str] = 'h3.position_state/1'
    header: Header
    position_id: str
    candidate_id: str
    status: PositionStatus
    entry_price: Decimal
    entry_ms: int
    initial_quantity: Decimal
    open_quantity: Decimal
    initial_stop: Decimal
    current_stop: Decimal
    current_target: Decimal | None
    last_price: Decimal
    mfe_price: Decimal
    mae_price: Decimal
    holding_ms: int
    max_holding_ms: int
    volatility: Decimal | None
    liquidity: LiquidityStatus
    funding_paid: Decimal
    realized_pnl: Decimal
    initial_risk_amount: Decimal
    equity: Decimal
    open_risk_total: Decimal
    risk_budget_remaining: Decimal
    actions_applied: tuple[str, ...]
    policy: PolicyRef
    data_status: DataStatus
    kill_switch: bool

    def _validate(self) -> None:
        side = _require_side(self.header)
        if self.header.portfolio_id is None:
            raise ContractError('h3_position_portfolio_required')
        check_id(self.position_id)
        check_id(self.candidate_id)
        _ms(self.entry_ms, 'h3_position_time_invalid')
        clock = self.header.clock
        if self.entry_ms > clock.observed_ms or self.holding_ms != clock.decision_ms - self.entry_ms:
            raise ContractError('h3_position_time_invalid')
        if self.max_holding_ms <= 0:
            raise ContractError('h3_position_time_invalid')
        if min(self.entry_price, self.initial_stop, self.current_stop, self.last_price, self.mfe_price,
               self.mae_price) <= 0 or self.initial_risk_amount <= 0:
            raise ContractError('h3_position_amount_invalid')
        if not ZERO <= self.open_quantity <= self.initial_quantity or self.initial_quantity <= 0:
            raise ContractError('h3_position_quantity_invalid')
        if (self.status is PositionStatus.CLOSED) != (self.open_quantity == 0):
            raise ContractError('h3_position_status_quantity_mismatch')
        if not stop_is_protective(side, self.entry_price, self.initial_stop):
            raise ContractError('h3_position_stop_not_protective')
        if not stop_not_wider(side, self.initial_stop, self.current_stop):
            raise ContractError('h3_position_stop_widened')
        low, high = (self.mae_price, self.mfe_price) if side is Side.LONG else (self.mfe_price, self.mae_price)
        if not low <= self.entry_price <= high:
            raise ContractError('h3_position_excursion_invalid')
        if self.open_risk_total < 0:
            raise ContractError('h3_position_amount_invalid')
        if len(set(self.actions_applied)) != len(self.actions_applied):
            raise ContractError('h3_position_actions_duplicate')

    def ref(self) -> SnapshotRef:
        return SnapshotRef(snapshot_id=self.position_id + '@' + str(self.header.clock.decision_ms),
                           fingerprint=self.fingerprint())


@dataclass(frozen=True)
class AdaptiveAction(Contract):
    """One management decision on a position. Built with ``AdaptiveAction.create`` so ids are deterministic.

    Shapes: HOLD and FULL_CLOSE carry no parameter; TIGHTEN_STOP carries ``new_stop``; ADJUST_TARGET carries
    ``new_target``; PARTIAL_CLOSE carries ``close_quantity`` > 0. Whether the stop really tightens, the quantity is
    executable, and so on is checked against the PositionState by the adaptive engine and again by the risk gate.
    """

    SCHEMA: ClassVar[str] = 'h3.adaptive_action/1'
    header: Header
    action_id: str
    idempotency_key: str
    position_id: str
    kind: ActionKind
    new_stop: Decimal | None
    new_target: Decimal | None
    close_quantity: Decimal | None
    state: SnapshotRef
    policy: PolicyRef
    reasons: tuple[str, ...]

    @staticmethod
    def derive_key(position_id: str, state: SnapshotRef, kind: ActionKind, new_stop: Decimal | None,
                   new_target: Decimal | None, close_quantity: Decimal | None, policy: PolicyRef) -> str:
        return sha256_text(canonical_json([position_id, state.fingerprint, kind.value,
                                           None if new_stop is None else wire_decimal(new_stop),
                                           None if new_target is None else wire_decimal(new_target),
                                           None if close_quantity is None else wire_decimal(close_quantity),
                                           policy.policy_fingerprint]))

    @classmethod
    def create(cls, *, header: Header, position_id: str, kind: ActionKind, state: SnapshotRef, policy: PolicyRef,
               reasons: tuple[str, ...], new_stop: Any = None, new_target: Any = None,
               close_quantity: Any = None) -> AdaptiveAction:
        values = [None if v is None else dec(v) for v in (new_stop, new_target, close_quantity)]
        key = cls.derive_key(position_id, state, kind, *values, policy)
        return cls(header=header, action_id='act-' + key[7:39], idempotency_key=key, position_id=position_id,
                   kind=kind, new_stop=values[0], new_target=values[1], close_quantity=values[2], state=state,
                   policy=policy, reasons=reasons)

    def _validate(self) -> None:
        _require_side(self.header)
        if self.header.portfolio_id is None:
            raise ContractError('h3_action_portfolio_required')
        check_id(self.position_id)
        check_reasons(self.reasons)
        shape = {
            ActionKind.HOLD: (False, False, False),
            ActionKind.FULL_CLOSE: (False, False, False),
            ActionKind.TIGHTEN_STOP: (True, False, False),
            ActionKind.ADJUST_TARGET: (False, True, False),
            ActionKind.PARTIAL_CLOSE: (False, False, True),
        }[self.kind]
        if (self.new_stop is not None, self.new_target is not None, self.close_quantity is not None) != shape:
            raise ContractError('h3_action_shape_invalid')
        for value in (self.new_stop, self.new_target, self.close_quantity):
            if value is not None and value <= 0:
                raise ContractError('h3_action_value_invalid')
        key = self.derive_key(self.position_id, self.state, self.kind, self.new_stop, self.new_target,
                              self.close_quantity, self.policy)
        if self.idempotency_key != key or self.action_id != 'act-' + key[7:39]:
            raise ContractError('h3_action_id_not_derived')


# ---------------------------------------------------------------------------------------------------------- risk

@dataclass(frozen=True)
class RiskDecision(Contract):
    """Risk gate verdict on an entry or an adaptive action. Amounts in the portfolio quote currency.

    ``risk_amount`` is the nominal loss at the stop of the subject position after the decision (0 when it is closed);
    ``open_risk_*`` and ``notional_*`` are portfolio totals. An ADMIT that breaks the rules cannot be constructed:
    - an ENTRY must respect ``H3_LIMITS`` in absolute terms (per-trade risk, total open risk, leverage, stop);
    - an ACTION may never increase the portfolio's open risk or notional. It is not held to the absolute caps,
      because equity may have fallen since entry and a risk-reducing action must stay admissible.
    The realised loss can still exceed ``risk_amount`` through a gap: the simulator measures it, it is never hidden.
    """

    SCHEMA: ClassVar[str] = 'h3.risk_decision/1'
    header: Header
    decision_id: str
    subject: RiskSubject
    subject_id: str
    verdict: RiskVerdict
    reasons: tuple[str, ...]
    policy: PolicyRef
    limits: H3Limits
    equity: Decimal
    quantity: Decimal | None
    price_reference: Decimal
    stop_price: Decimal | None
    risk_amount: Decimal
    open_risk_before: Decimal
    open_risk_after: Decimal
    notional_before: Decimal
    notional_after: Decimal
    leverage_after: Decimal

    def _validate(self) -> None:
        _require_side(self.header)
        if self.header.portfolio_id is None:
            raise ContractError('h3_risk_portfolio_required')
        check_id(self.decision_id)
        check_id(self.subject_id)
        check_reasons(self.reasons)
        if min(self.risk_amount, self.open_risk_before, self.open_risk_after, self.notional_before,
               self.notional_after, self.leverage_after) < 0 or self.price_reference <= 0:
            raise ContractError('h3_risk_amount_invalid')
        if self.verdict is RiskVerdict.REJECT:
            if not self.reasons:
                raise ContractError('h3_risk_reject_needs_reason')
            return
        if self.limits != H3_LIMITS:
            raise ContractError('h3_risk_limits_not_h3')
        if self.equity <= 0:
            raise ContractError('h3_risk_admit_equity_invalid')
        if self.stop_price is None and self.risk_amount > 0:
            raise ContractError('h3_risk_admit_without_stop')
        if self.subject is RiskSubject.ACTION:
            if self.open_risk_after > self.open_risk_before or self.notional_after > self.notional_before:
                raise ContractError('h3_risk_admit_action_increases_risk')
            return
        if self.quantity is None or self.quantity <= 0 or self.stop_price is None:
            raise ContractError('h3_risk_admit_entry_incomplete')
        if self.risk_amount > self.limits.per_trade_risk_max * self.equity:
            raise ContractError('h3_risk_admit_per_trade_exceeded')
        if self.open_risk_after > self.limits.total_risk_max * self.equity:
            raise ContractError('h3_risk_admit_total_exceeded')
        if self.leverage_after > self.limits.leverage_max:
            raise ContractError('h3_risk_admit_leverage_exceeded')


# --------------------------------------------------------------------------------------------------------- audit

@dataclass(frozen=True)
class DecisionAudit(Contract):
    """One append-only journal record. Records form a hash chain per (experiment, variant)."""

    SCHEMA: ClassVar[str] = 'h3.decision_audit/1'
    header: Header
    audit_id: str
    sequence: int
    experiment_id: str
    variant: Variant | None
    event: AuditEvent
    subject_schema: str
    subject_id: str
    subject_fingerprint: str
    previous_audit_fingerprint: str | None
    model: ModelRef | None
    policy: PolicyRef | None
    reasons: tuple[str, ...]

    def _validate(self) -> None:
        check_id(self.audit_id)
        check_id(self.experiment_id)
        check_id(self.subject_schema)
        check_id(self.subject_id)
        check_sha(self.subject_fingerprint)
        check_reasons(self.reasons)
        if isinstance(self.sequence, bool) or self.sequence < 0:
            raise ContractError('h3_audit_sequence_invalid')
        if (self.sequence == 0) != (self.previous_audit_fingerprint is None):
            raise ContractError('h3_audit_chain_invalid')
        if self.previous_audit_fingerprint is not None:
            check_sha(self.previous_audit_fingerprint)


# ------------------------------------------------------------------------------------------------------ manifests

@dataclass(frozen=True)
class ModelManifest(Contract):
    """Versioned, portable model parameters (Python and PHP must reproduce the same scores from it).

    A CALIBRATED model needs training provenance; when any training data is real (not synthetic), the protocol that
    produced it must be FROZEN. Parameters are sorted by name.
    """

    SCHEMA: ClassVar[str] = 'h3.model_manifest/1'
    model_id: str
    model_version: str
    kind: ModelKind
    feature_spec_id: str
    feature_spec_version: str
    feature_names: tuple[str, ...]
    parameters: tuple[NamedValue, ...]
    calibration: CalibrationStatus
    training_provenance: tuple[Provenance, ...]
    protocol: ProtocolRef
    created_ms: int
    notes: str

    def _validate(self) -> None:
        for value in (self.model_id, self.model_version, self.feature_spec_id, self.feature_spec_version):
            check_id(value)
        _ms(self.created_ms, 'h3_model_time_invalid')
        if len(set(self.feature_names)) != len(self.feature_names):
            raise ContractError('h3_model_feature_duplicate')
        for name in self.feature_names:
            check_id(name)
        _sorted_unique([p.name for p in self.parameters], 'h3_model_parameters_not_sorted_unique')
        if self.calibration is CalibrationStatus.CALIBRATED:
            if self.kind is ModelKind.REFERENCE_NULL or not self.training_provenance:
                raise ContractError('h3_model_calibration_without_training')
            real = any(p.label is not DataLabel.SYNTHETIC for p in self.training_provenance)
            if real and self.protocol.status is not ProtocolStatus.FROZEN:
                raise ContractError('h3_model_real_training_needs_frozen_protocol')
        elif self.training_provenance:
            raise ContractError('h3_model_uncalibrated_with_training')

    def ref(self) -> ModelRef:
        return ModelRef(model_id=self.model_id, model_version=self.model_version,
                        model_fingerprint=self.fingerprint())


@dataclass(frozen=True)
class CostModel(Contract):
    """Execution costs applied identically to the four variants. Rates are fractions (0.0005 = 5 bp)."""

    SCHEMA: ClassVar[str] = 'h3.cost_model/1'
    maker_fee_rate: Decimal
    taker_fee_rate: Decimal
    slippage_rate: Decimal
    spread_model: str
    funding_model: str
    pessimistic_multiplier: Decimal

    def _validate(self) -> None:
        if min(self.maker_fee_rate, self.taker_fee_rate, self.slippage_rate) < 0 or self.pessimistic_multiplier < 1:
            raise ContractError('h3_cost_model_invalid')
        check_id(self.spread_model)
        check_id(self.funding_model)


@dataclass(frozen=True)
class ExperimentManifest(Contract):
    """One B0/B1/B2/B3 experiment. The four variants share candidates, market data, costs and risk policy.

    Any non-synthetic dataset requires a FROZEN protocol: a DRAFT protocol can only run on synthetic data.
    """

    SCHEMA: ClassVar[str] = 'h3.experiment_manifest/1'
    experiment_id: str
    protocol: ProtocolRef
    variants: tuple[Variant, ...]
    candidate_generator_id: str
    candidate_generator_version: str
    hypothesis_id: str
    scoring_model: ModelRef
    static_policy: PolicyRef
    adaptive_policy: PolicyRef
    risk_policy: PolicyRef
    limits: H3Limits
    cost_model: CostModel
    initial_equity: Decimal
    quote_ccy: str
    datasets: tuple[Provenance, ...]
    seed: int
    code_digest: str
    created_ms: int

    def _validate(self) -> None:
        for value in (self.experiment_id, self.candidate_generator_id, self.candidate_generator_version,
                      self.hypothesis_id, self.quote_ccy):
            check_id(value)
        check_sha(self.code_digest)
        _ms(self.created_ms, 'h3_experiment_time_invalid')
        if self.variants != (Variant.B0, Variant.B1, Variant.B2, Variant.B3):
            raise ContractError('h3_experiment_variants_not_factorial')
        if self.limits != H3_LIMITS:
            raise ContractError('h3_experiment_limits_not_h3')
        if self.initial_equity <= 0 or isinstance(self.seed, bool) or self.seed < 0:
            raise ContractError('h3_experiment_value_invalid')
        if not self.datasets:
            raise ContractError('h3_experiment_dataset_required')
        real = any(p.label is not DataLabel.SYNTHETIC for p in self.datasets)
        if real and self.protocol.status is not ProtocolStatus.FROZEN:
            raise ContractError('h3_experiment_real_data_needs_frozen_protocol')
        if self.static_policy == self.adaptive_policy:
            raise ContractError('h3_experiment_policies_identical')


# ----------------------------------------------------------------------------------------------------- interfaces

class CandidateProvider(Protocol):
    """Common generator: same candidates for B0-B3, built without any score."""

    generator_id: str
    generator_version: str
    hypothesis_id: str

    def candidates(self, snapshots: Sequence[FeatureSnapshot], decision_ms: int) -> tuple[OpportunityCandidate, ...]:
        ...


class OpportunityScorer(Protocol):
    def score(self, candidate: OpportunityCandidate, snapshot: FeatureSnapshot,
              model: ModelManifest | None) -> OpportunityScore:
        ...

    def rank(self, scores: Sequence[OpportunityScore]) -> tuple[OpportunityScore, ...]:
        ...


class ManagementPolicy(Protocol):
    """Static or adaptive management. A static policy only ever returns HOLD (exits come from stop/target/expiry)."""

    policy: PolicyRef

    def decide(self, state: PositionState) -> AdaptiveAction:
        ...


class RiskGate(Protocol):
    def admit_entry(self, candidate: OpportunityCandidate, score: OpportunityScore | None,
                    portfolio: PortfolioView) -> RiskDecision:
        ...

    def validate_action(self, action: AdaptiveAction, state: PositionState, portfolio: PortfolioView) -> RiskDecision:
        ...


class AuditSink(Protocol):
    def append(self, record: DecisionAudit) -> None:
        ...


CONTRACTS: tuple[type[Contract], ...] = (
    H3Limits, Clock, InstrumentRef, Provenance, ProtocolRef, ModelRef, PolicyRef, SnapshotRef, Header, NamedValue,
    Bar, Quote, FundingObservation, FeatureValue, FeatureSnapshot, OpportunityCandidate, OpportunityScore, PortfolioView, PositionState,
    AdaptiveAction, RiskDecision, DecisionAudit, ModelManifest, CostModel, ExperimentManifest,
)
