"""Incremental, research-only linear-USDT portfolio simulation.

No exchange, dataset, strategy or environment access occurs here. OHLC fills and
isolated liquidation are declared proxies, never evidence of live execution.
Decimal is used internally; PHP-compatible JSON numbers exist only at the wire
edge. Sinks own history storage. There is deliberately no resume format.
Funding provisions reserve risk only. Observed charges/credits are wallet
cashflows. Liquidation uses isolated entry/leverage/MMR geometry; funding and
fees do not reconstruct a historical venue collateral account or bracket.
Forced exits settle at the supplied exclusive deadline using a strictly prior
known close, even when the deadline is midnight; this is a research valuation,
not a claim that an intrabar exchange order executed at that price or instant.
A ``KernelProfile`` carries the mode's lifecycle and portfolio rules. The default
is day_trading@1.1.0 (long only, 8 h or midnight, four plans); other profiles may
hold shorts, multi-day plans and one active plan per symbol, and only they add
``side`` to plans and trades and ``active_symbols`` to the portfolio view.
"""
from __future__ import annotations

from collections import Counter
from collections.abc import Callable, Iterator, Mapping, Sequence
from dataclasses import dataclass, field, fields
from datetime import datetime
from decimal import Decimal, ROUND_DOWN
import hashlib
import math
import re
from typing import Any, TYPE_CHECKING

if TYPE_CHECKING:
    from .holdout_authority import HoldoutAuthorization

from app.modern_trading_contracts import FrozenJsonDict, _canonical_json

D = Decimal
ZERO = D('0')
MINUTE = 60000
DAY = 86400000
SYMBOLS = ('BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT',
           'DOGEUSDT', 'SOLUSDT', 'LTCUSDT', 'LINKUSDT', 'AVAXUSDT')
IDENTITY_FIELDS = ('base_setup_hash', 'base_config_hash', 'base_catalog_hash',
    'base_snapshot_hash', 'variant_id', 'variant_hash', 'instrument_assumptions_hash',
    'cost_assumptions_hash', 'research_code_hash')
SOURCE_FIELDS = ('dataset_id', 'dataset_sha256', 'signal_run_id', 'signal_output_sha256')
# Per-side cell hashes of a two-sided run; the run identity holds their composites.
CELL_FIELDS = ('base_setup_hash', 'base_config_hash', 'base_catalog_hash', 'base_snapshot_hash', 'variant_hash')
_B1_BASELINE_BINDINGS = (
    ('setup_hash', 'base_setup_hash'),
    ('config_hash', 'base_config_hash'),
    ('condition_catalog_hash', 'base_catalog_hash'),
    ('snapshot_hash', 'base_snapshot_hash'),
)


def number(value: Any) -> Decimal:
    """Reject booleans/non-finite values, including at the JSON boundary."""
    if isinstance(value, bool) or not isinstance(value, (int, float, str, Decimal)):
        raise ValueError('research_number_invalid')
    try:
        result = D(str(value))
    except Exception as exc:
        raise ValueError('research_number_invalid') from exc
    if not result.is_finite():
        raise ValueError('research_number_invalid')
    return result


def wire_number(value: Decimal) -> float:
    result = float(number(value))
    if not math.isfinite(result):
        raise ValueError('research_wire_number_nonfinite')
    return result


def canonical_hash(value: Mapping[str, Any]) -> str:
    return 'sha256:' + hashlib.sha256(_canonical_json(value).encode()).hexdigest()


def digest(value: Any) -> bool:
    return isinstance(value, str) and re.fullmatch(r'(sha256:)?[a-f0-9]{64}', value) is not None


def composite_hash(by_side: Mapping[str, str]) -> str:
    """Run-level hash of per-side cell hashes; a single side keeps its own hash (day_trading)."""
    if len(by_side) == 1:
        return next(iter(by_side.values()))
    return canonical_hash({side: by_side[side] for side in sorted(by_side)})


def stream_key(symbol: str, side: str, sides: Sequence[str]) -> str:
    """One signal stream per symbol for a single-side cell, one per (symbol, side) otherwise."""
    return symbol if len(sides) == 1 else f'{symbol}:{side}'


def identifier(value: Any) -> bool:
    return isinstance(value, str) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._:-]{0,95}', value) is not None


def utc_metadata_instant(value: Any) -> bool:
    if not isinstance(value, str) or re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z', value) is None:
        return False
    try:
        datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ')
    except ValueError:
        return False
    return True


def daily_capacity(equity: Decimal, realized: Decimal, unrealized: Decimal,
                   reserved: Decimal, rate: Decimal = D('.06'), absolute: Decimal = D('30')) -> Decimal:
    """CanonicalPortfolioAdmissionEngine: aggregate unrealized, then loss floor."""
    return min(equity * rate, absolute) - max(-realized, ZERO) - max(-unrealized, ZERO) - reserved


@dataclass(frozen=True)
class KernelProfile:
    """Lifecycle and portfolio rules of one mode, enforced on every plan.

    ``cancel_after_seconds`` is the first bar boundary after the entry TTL, as
    90 s -> 120 s for day_trading. With ``daily_boundary`` a plan ends before the
    next UTC midnight; ``one_active_per_symbol`` rejects a plan while the symbol
    holds a pending entry or a position, whatever its side.
    """
    profile_id: str = 'day_trading.v1'
    sides: tuple[str, ...] = ('long',)
    entry_ttl_seconds: int = 90
    cancel_after_seconds: int = 120
    holding_window_seconds: int = 28800
    daily_boundary: bool = True
    max_active: int = 4
    one_active_per_symbol: bool = False
    daily_loss_rate: Decimal = D('.06')
    daily_loss_absolute_quote: Decimal = D('30')

    def __post_init__(self) -> None:
        integers = (self.entry_ttl_seconds, self.cancel_after_seconds, self.holding_window_seconds, self.max_active)
        if (not identifier(self.profile_id) or type(self.sides) is not tuple or not self.sides
                or len(set(self.sides)) != len(self.sides) or set(self.sides) - {'long', 'short'}
                or any(type(v) is not int for v in integers) or self.entry_ttl_seconds <= 0
                or self.cancel_after_seconds != (self.entry_ttl_seconds // 60 + 1) * 60
                or self.holding_window_seconds <= 60 or not 1 <= self.max_active <= len(SYMBOLS)
                or type(self.daily_boundary) is not bool or type(self.one_active_per_symbol) is not bool
                or not isinstance(self.daily_loss_rate, D) or not ZERO < self.daily_loss_rate <= 1
                or not isinstance(self.daily_loss_absolute_quote, D)
                or not self.daily_loss_absolute_quote.is_finite() or self.daily_loss_absolute_quote <= 0):
            raise ValueError('research_kernel_profile_invalid')

    @property
    def extended(self) -> bool:
        """Whether plans, trades and views carry the side and active-symbol fields."""
        return self != DAY_TRADING_PROFILE

    def wire(self) -> dict[str, Any]:
        return {f.name: (wire_number(v) if isinstance(v := getattr(self, f.name), D)
                         else list(v) if isinstance(v, tuple) else v) for f in fields(self)}

    @classmethod
    def from_wire(cls, document: Mapping[str, Any] | None) -> KernelProfile:
        """The profile a ``wire()`` document describes; ``None`` means day_trading."""
        if document is None:
            return DAY_TRADING_PROFILE
        try:
            profile = cls(document['profile_id'], tuple(document['sides']), document['entry_ttl_seconds'],
                          document['cancel_after_seconds'], document['holding_window_seconds'],
                          document['daily_boundary'], document['max_active'], document['one_active_per_symbol'],
                          number(document['daily_loss_rate']), number(document['daily_loss_absolute_quote']))
        except (KeyError, TypeError) as exc:
            raise ValueError('research_kernel_profile_invalid') from exc
        if profile.wire() != dict(document):
            raise ValueError('research_kernel_profile_invalid')
        return profile


DAY_TRADING_PROFILE = KernelProfile()
# The swing breakout candidate of 2026-10-11: both sides, 60 min entries, 14 days,
# one plan per pair; its daily-loss cap (100 % of equity, 100,000 quote) cannot bind.
SWING_PROFILE = KernelProfile('swing.v1', ('long', 'short'), 3600, 3660, 1209600, False, 10, True,
                              D('1'), D('100000'))
HOLDING_WINDOWS = tuple(sorted({DAY_TRADING_PROFILE.holding_window_seconds, SWING_PROFILE.holding_window_seconds}))


@dataclass(frozen=True)
class Candle:
    symbol: str
    open_ms: int
    open: Decimal
    high: Decimal
    low: Decimal
    close: Decimal

    def validate(self) -> None:
        values = (self.open, self.high, self.low, self.close)
        if (type(self.open_ms) is not int or self.open_ms < 0 or self.open_ms % MINUTE
            or any(not isinstance(v, Decimal) or not v.is_finite() or v <= 0 for v in values)
            or not self.low <= self.open <= self.high or not self.low <= self.close <= self.high):
            raise ValueError('research_candle_invalid')


@dataclass(frozen=True)
class InstrumentAssumptions:
    tick_size: Decimal
    quantity_step: Decimal
    min_quantity: Decimal
    max_quantity: Decimal
    min_notional: Decimal
    contract_size: Decimal
    leverage_cap_assumed: Decimal
    mmr_proxy_rate: Decimal
    liquidation_fee_rate: Decimal
    metadata_retrieved_at: str
    metadata_raw_sha256: str

    def __post_init__(self) -> None:
        for field in fields(self)[:9]:
            value = getattr(self, field.name)
            if not isinstance(value, Decimal) or not value.is_finite() or value < 0:
                raise ValueError('research_instrument_invalid')
        if (min(self.tick_size, self.quantity_step, self.min_quantity, self.contract_size) <= 0
            or self.max_quantity < self.min_quantity or self.min_notional < 5
            or not 1 <= self.leverage_cap_assumed <= 2 or self.mmr_proxy_rate >= 1
            or self.liquidation_fee_rate >= 1 or not utc_metadata_instant(self.metadata_retrieved_at)
            or not digest(self.metadata_raw_sha256)):
            raise ValueError('research_instrument_invalid')

    def wire(self) -> dict[str, Any]:
        return {f.name: wire_number(v) if isinstance(v := getattr(self, f.name), D) else v
                for f in fields(self)}


@dataclass(frozen=True)
class CostAssumptions:
    entry_fee_rate: Decimal
    stop_fee_rate: Decimal
    target_fee_rate: Decimal
    entry_spread_rate: Decimal
    stop_spread_rate: Decimal
    target_spread_rate: Decimal
    entry_slippage_rate: Decimal
    stop_slippage_rate: Decimal
    target_slippage_rate: Decimal
    funding_provision_rate: Decimal
    funding_interval_seconds: int
    funding_intervals_provisioned: int

    def __post_init__(self) -> None:
        for f in fields(self)[:10]:
            v = getattr(self, f.name)
            if not isinstance(v, D) or not v.is_finite() or not 0 <= v < 1:
                raise ValueError('research_cost_invalid')
        # The provision covers every funding interval of a profile's holding window;
        # RunAssumptions then requires the window of its own profile.
        if (self.entry_fee_rate != D('.0002') or self.stop_fee_rate != D('.0005')
            or self.target_fee_rate not in (D('.0002'), D('.0005'))
            or type(self.funding_interval_seconds) is not int or self.funding_interval_seconds <= 0
            or self.funding_intervals_provisioned not in
                {(window-1)//self.funding_interval_seconds+1 for window in HOLDING_WINDOWS}):
            raise ValueError('research_baseline_cost_mismatch')

    def wire(self) -> dict[str, Any]:
        return {'entry_fee_role': 'maker', 'stop_fee_role': 'taker',
            'target_fee_role': 'maker' if self.target_fee_rate == D('.0002') else 'taker',
            **{f.name: wire_number(v) if isinstance(v := getattr(self, f.name), D) else v
               for f in fields(self)}}


@dataclass(frozen=True)
class FundingCoverage:
    status: str
    evidence_hash: str
    expected_counts: tuple[tuple[str, int], ...]


@dataclass(frozen=True)
class FundingEvent:
    symbol: str
    timestamp_ms: int
    rate: Decimal
    mark_price: Decimal | None = None
    mark_observed_ms: int | None = None


@dataclass(frozen=True)
class Signal:
    index: int
    payload: Mapping[str, Any]

    def __post_init__(self) -> None:
        object.__setattr__(self, 'payload', FrozenJsonDict(self.payload))


@dataclass(frozen=True)
class RunAssumptions:
    start_ms: int
    end_ms: int
    phase: str
    symbols: tuple[str, ...]
    instruments: tuple[tuple[str, InstrumentAssumptions], ...]
    costs: CostAssumptions
    identity: tuple[tuple[str, str], ...]
    sources: tuple[tuple[str, tuple[tuple[str, str], ...]], ...]
    funding: FundingCoverage
    funding_path_policy: str
    funding_mark_policy: str
    authorization: HoldoutAuthorization | None = None
    kernel_profile: KernelProfile = DAY_TRADING_PROFILE
    # Two-sided profiles only: ((side, ((CELL_FIELD, hash), ...)), ...) in profile side order.
    cells: tuple[tuple[str, tuple[tuple[str, str], ...]], ...] = ()

    def __post_init__(self) -> None:
        binding = None
        if self.phase == 'holdout':
            from .holdout_authority import require_claim
            # Issuance is checked before trusting even the slot tuple.
            require_claim(self.authorization,operation='retained')
            if len(self.authorization.slots) != 1:
                raise ValueError('single_slot_authorization_required')
            variant,profile = self.authorization.slots[0]
            contract = require_claim(self.authorization,operation='simulate',variant_id=variant,
                cost_profile=profile,symbols=self.symbols)
            from .campaign import validate_holdout_unit_inputs
            binding = validate_holdout_unit_inputs(authorization=self.authorization,
                variant_id=variant,cost_profile=profile,operation='simulate')
            if (self.start_ms != int(datetime.fromisoformat(contract['window']['score_start'].replace('Z','+00:00')).timestamp()*1000)
                or self.end_ms != contract['window']['end_ms']):
                raise ValueError('research_source_window_invalid')
        elif self.authorization is not None:
            raise ValueError('research_prehholdout_authorization_invalid')
        # Tuples keep supplied assumptions immutable; reject mutable impostors.
        if any(type(getattr(self, k)) is not tuple for k in ('symbols','instruments','identity','sources')):
            raise ValueError('research_assumptions_mutable')
        if any(type(pair) is not tuple or len(pair) != 2 for pairs in
               (self.instruments,self.identity,self.sources) for pair in pairs):
            raise ValueError('research_assumptions_mutable')
        if (type(self.start_ms) is not int or type(self.end_ms) is not int
            or self.start_ms % MINUTE or self.end_ms % MINUTE or self.start_ms >= self.end_ms
            or (binding is None and (self.start_ms < 1672531200000 or self.phase not in ('training','validation')
                or self.end_ms > (1735689600000 if self.phase == 'training' else 1767225600000)
                or (self.phase == 'validation' and self.start_ms < 1735689600000)))):
            raise ValueError('research_source_window_invalid')
        if not self.symbols or self.symbols != tuple(s for s in SYMBOLS if s in self.symbols):
            raise ValueError('research_universe_invalid')
        if (tuple(s for s,_ in self.instruments) != self.symbols
            or any(not isinstance(i, InstrumentAssumptions) for _,i in self.instruments)
            or not isinstance(self.costs, CostAssumptions)
            or tuple(s for s,_ in self.sources) != self.stream_keys()
            or set(dict(self.identity)) != set(IDENTITY_FIELDS) or len(self.identity) != len(IDENTITY_FIELDS)
            or not identifier(dict(self.identity).get('variant_id'))
            or any(not digest(v) for k,v in self.identity if k != 'variant_id')):
            raise ValueError('research_bindings_invalid')
        for _, source in self.sources:
            if (type(source) is not tuple or any(type(p) is not tuple or len(p) != 2 for p in source)
                or set(dict(source)) != set(SOURCE_FIELDS) or len(source) != len(SOURCE_FIELDS)
                or not identifier(dict(source).get('dataset_id'))
                or not identifier(dict(source).get('signal_run_id'))
                or not digest(dict(source)['dataset_sha256']) or not digest(dict(source)['signal_output_sha256'])):
                raise ValueError('research_source_identity_invalid')
        if (not isinstance(self.funding, FundingCoverage)
            or self.funding.status not in ('verified_complete', 'observed_only')
            or not digest(self.funding.evidence_hash)
            or type(self.funding.expected_counts) is not tuple
            or any(type(p) is not tuple or len(p) != 2 for p in self.funding.expected_counts)
            or tuple(s for s,_ in self.funding.expected_counts) != self.symbols
            or any(type(n) is not int or n < 0 for _,n in self.funding.expected_counts)
            or self.funding_path_policy != 'adverse_possible_credit_certain.v1'
            or self.funding_mark_policy != 'last_known_close.v1'):
            raise ValueError('research_funding_coverage_invalid')
        if (not isinstance(self.kernel_profile, KernelProfile)
            or self.costs.funding_intervals_provisioned
                != (self.kernel_profile.holding_window_seconds-1)//self.costs.funding_interval_seconds+1):
            raise ValueError('research_baseline_cost_mismatch')
        self._validate_cells()
        if binding is not None:
            from dataclasses import asdict
            from .campaign_evidence import encoded
            import json
            # A two-sided unit binds each side's full identity (its cell inside the run identity).
            sides = self.kernel_profile.sides
            identity = {side:self.cell_identity(side) for side in sides} if len(sides) > 1 else dict(self.identity)
            if (identity != binding['identity']
                or {s:dict(rows) for s,rows in self.sources} != binding['sources']
                or self.costs.wire() != binding['kernel_costs']
                or json.loads(encoded({s:asdict(i) for s,i in self.instruments})) != binding['kernel_instruments']
                or json.loads(encoded(asdict(self.funding))) != binding['funding_coverage']
                or self.funding_path_policy != binding['funding_path_policy']
                or self.funding_mark_policy != binding['funding_mark_policy']
                or binding.get('kernel_profile', DAY_TRADING_PROFILE.wire()) != self.kernel_profile.wire()):
                raise ValueError('research_holdout_input_binding_conflict')

    def _validate_cells(self) -> None:
        sides = self.kernel_profile.sides
        if type(self.cells) is not tuple:
            raise ValueError('research_cells_invalid')
        if len(sides) == 1:
            if self.cells:
                raise ValueError('research_cells_invalid')
            return
        if (tuple(side for side,_ in self.cells) != sides
            or any(type(cell) is not tuple or any(type(p) is not tuple or len(p) != 2 for p in cell)
                   or tuple(k for k,_ in cell) != CELL_FIELDS or any(not digest(v) for _,v in cell)
                   for _,cell in self.cells)):
            raise ValueError('research_cells_invalid')
        identity = dict(self.identity)
        for name in CELL_FIELDS:
            if identity[name] != composite_hash({side: dict(cell)[name] for side, cell in self.cells}):
                raise ValueError('research_cells_composite_conflict')

    def stream_keys(self) -> tuple[str, ...]:
        """Source keys in symbol priority, then profile side order."""
        # A non-profile value is rejected by the cost check that follows.
        sides = self.kernel_profile.sides if isinstance(self.kernel_profile, KernelProfile) else ('long',)
        return tuple(stream_key(s, side, sides) for s in self.symbols for side in sides)

    def cell_identity(self, side: str) -> dict[str, str]:
        """The identity a plan of ``side`` carries: the run identity with its side's cell hashes."""
        identity = dict(self.identity)
        if self.cells:
            identity.update(dict(dict(self.cells)[side]))
        return identity

    def source(self, symbol: str, side: str) -> dict[str, str]:
        return dict(dict(dict(self.sources))[stream_key(symbol, side, self.kernel_profile.sides)])


def merge_candles(streams: Mapping[str, Iterator[Candle]], symbols: tuple[str, ...]) -> Iterator[tuple[Candle, ...]]:
    """Bounded one-item-per-symbol merge; absent/unequal coverage is an error."""
    if set(streams) != set(symbols):
        raise ValueError('research_coverage_gap')
    iterators = [iter(streams[s]) for s in symbols]
    previous = None
    while True:
        row = tuple(next(i, None) for i in iterators)
        if all(c is None for c in row):
            return
        if any(c is None for c in row):
            raise ValueError('research_coverage_gap')
        batch = tuple(c for c in row if c is not None)
        for s,c in zip(symbols,batch):
            c.validate()
            if c.symbol != s or c.open_ms != batch[0].open_ms:
                raise ValueError('research_coverage_gap')
        if previous is not None and batch[0].open_ms != previous + MINUTE:
            raise ValueError('research_coverage_gap')
        previous = batch[0].open_ms
        yield batch


@dataclass
class _Position:
    plan: Mapping[str, Any]
    fill_start_ms: int
    fill_boundary_ms: int
    cashflow: Decimal = ZERO
    fees: Decimal = ZERO
    spread: Decimal = ZERO
    slippage: Decimal = ZERO
    funding: Decimal = ZERO
    ambiguity: set[str] = field(default_factory=set)

    @property
    def units(self) -> Decimal:
        return number(self.plan['quantity']) * number(self.plan['instrument_math']['contract_size'])

    @property
    def long(self) -> bool:
        return self.plan.get('side', 'long') == 'long'

    def gain(self, price: Decimal) -> Decimal:
        """Gross PnL of closing the position at ``price``."""
        entry = number(self.plan['entry_price'])
        return self.units * ((price - entry) if self.long else (entry - price))


class PortfolioSimulator:
    def __init__(self, assumptions: RunAssumptions,
                 plan_builder: Callable[[Signal, Mapping[str, Any]], Mapping[str, Any]], *,
                 event_sink: Callable[[Mapping[str, Any]], None],
                 trade_sink: Callable[[Mapping[str, Any]], None],
                 cashflow_sink: Callable[[Mapping[str, Any]], None],
                 rejection_sink: Callable[[Mapping[str, Any]], None],
                 marked_sink: Callable[[Mapping[str, Any]], None] | None = None):
        self._assumptions = assumptions
        self.builder = plan_builder
        self.event_sink, self.trade_sink = event_sink, trade_sink
        self.cashflow_sink, self.rejection_sink = cashflow_sink, rejection_sink
        self.marked_sink = marked_sink
        self._marked_wallet = None
        self._marked_positions = False
        self._marked_cash_dirty = False
        self.wallet = D('100000')
        self.daily_realized = ZERO
        self.total_cashflow = ZERO
        self.pending: dict[str, Mapping[str, Any]] = {}
        self.positions: dict[str, _Position] = {}
        self.marks: dict[str, Decimal] = {}
        self.now = assumptions.start_ms
        self.processed_batches = 0
        self.finished = False
        self.failed = False
        self.primed = False
        self.last_signals: dict[str, tuple[int, int]] = {}
        self.funding_counts: Counter[str] = Counter()
        self.rejection_counts: Counter[str] = Counter()
        self.ambiguity_counts: Counter[str] = Counter()
        self.peak_equity = self.wallet
        self.maximum_drawdown = ZERO
        self.maximum_risk = ZERO
        self.maximum_exposure = ZERO
        self.trades = 0
        self.attempted_signals = 0
        self.admitted_plans = 0

    @property
    def assumptions(self) -> RunAssumptions:
        return self._assumptions

    def _balances(self) -> dict[str, Decimal]:
        unrealized = sum((p.gain(self.marks[p.plan['symbol']]) for p in self.positions.values()), ZERO)
        committed = list(self.pending.values()) + [p.plan for p in self.positions.values()]
        margin = sum((number(p['position_notional_quote'])/number(p['final_leverage']) for p in committed), ZERO)
        return {'equity_quote':self.wallet+unrealized,
            'available_balance_quote':max(self.wallet-margin,ZERO),
            'realized_net_pnl_quote':self.daily_realized,'unrealized_net_pnl_quote':unrealized,
            'open_notional_quote':sum((number(p.plan['position_notional_quote']) for p in self.positions.values()),ZERO),
            'pending_notional_quote':sum((number(p['position_notional_quote']) for p in self.pending.values()),ZERO),
            'reserved_risk_quote':sum((number(p['risk_components_quote']['total_stop_loss']) for p in committed),ZERO)}

    def view(self) -> dict[str, Any]:
        committed = list(self.pending.values()) + [p.plan for p in self.positions.values()]
        value = {'schema_version': 'research-portfolio-view.v1', 'as_of_ms': self.now,
            **{k:wire_number(v) for k,v in self._balances().items()},
            'open_positions': len(self.positions), 'pending_entries': len(self.pending),
            'active_signal_hashes': [p['signal_result_hash'] for p in committed]}
        if self.assumptions.kernel_profile.extended:
            value['active_symbols'] = sorted(self._active_symbols())
        value['portfolio_hash'] = canonical_hash(value)
        return value

    def _active_symbols(self) -> set[str]:
        return {p['symbol'] for p in self.pending.values()} | {p.plan['symbol'] for p in self.positions.values()}

    def _event(self, reason: str, at: int, plan: Mapping[str, Any], *, ambiguous: bool = False) -> None:
        if ambiguous:
            self.ambiguity_counts[reason] += 1
            if plan['plan_hash'] in self.positions:
                self.positions[plan['plan_hash']].ambiguity.add(reason)
        self.event_sink({'schema_version': 'research-execution-event.v1', 'reason': reason,
            'timestamp_ms': at, 'plan_hash': plan['plan_hash'], 'symbol': plan['symbol'],
            'ambiguous': ambiguous, 'execution_authority': 'none'})

    def _cash(self, position: _Position, kind: str, amount: Decimal, at: int,
              **details: Any) -> None:
        self.wallet += amount
        self.daily_realized += amount
        self.total_cashflow += amount
        self._marked_cash_dirty = True
        position.cashflow += amount
        self.cashflow_sink({'schema_version': 'research-cashflow.v1', 'plan_hash': position.plan['plan_hash'],
            'symbol': position.plan['symbol'], 'kind': kind, 'timestamp_ms': at,
            'amount_quote': amount, **details})

    def _costs(self, p: _Position, leg: str, price: Decimal, at: int) -> None:
        c = self.assumptions.costs
        fee = getattr(c, f'{leg}_fee_rate')
        for name, rate in (('fee',fee), ('spread',getattr(c,f'{leg}_spread_rate')),
                           ('slippage',getattr(c,f'{leg}_slippage_rate'))):
            amount = p.units * price * rate
            setattr(p, 'fees' if name == 'fee' else name, getattr(p, 'fees' if name == 'fee' else name)+amount)
            self._cash(p, f'{leg}_{name}', -amount, at)

    def _close(self, key: str, p: _Position, price: Decimal, reason: str, at: int,
               price_known_ms: int, ambiguous: bool = False) -> None:
        gross = p.gain(price)
        self._cash(p, 'gross_pnl', gross, at)
        self._costs(p, 'target' if reason == 'target' else 'stop', price, at)
        if reason == 'liquidation_proxy':
            fee = p.units*price*number(p.plan['instrument_math']['liquidation_fee_rate'])
            p.fees += fee
            self._cash(p, 'liquidation_proxy_fee', -fee, at)
        self.positions.pop(key, None)
        self.trades += 1
        self.trade_sink({'schema_version': 'research-trade.v1',
            **{k:p.plan[k] for k in (*IDENTITY_FIELDS,*SOURCE_FIELDS)},
            'plan_hash': key, 'signal_result_hash': p.plan['signal_result_hash'],
            'symbol': p.plan['symbol'], 'decision_ms': p.plan['evaluated_ms'],
            'fill_interval_start_ms': p.fill_start_ms, 'fill_boundary_ms': p.fill_boundary_ms,
            'exit_boundary_ms': at, 'settlement_price_known_ms': price_known_ms,
            'entry_price': number(p.plan['entry_price']), 'exit_price': price,
            'quantity': number(p.plan['quantity']), 'gross_pnl_quote': gross,
            'net_pnl_quote': p.cashflow, 'fees_quote': p.fees, 'spread_quote': p.spread,
            'slippage_quote': p.slippage, 'funding_quote': p.funding,
            'funding_provision_quote': number(p.plan['risk_components_quote']['funding_provision']),
            'exit_reason': reason, 'ambiguous': ambiguous or bool(p.ambiguity),
            'ambiguity_reasons': tuple(sorted(p.ambiguity)),
            **({'side': 'long' if p.long else 'short'} if self.assumptions.kernel_profile.extended else {}),
            'execution_authority': 'none', 'liquidation_is_exchange_certified': False})

    def _exit(self, p: _Position, candle: Candle, new: bool) -> tuple[Decimal,str,bool] | None:
        plan = p.plan
        stop = number(plan['stop_price'])
        leverage = number(plan['final_leverage'])
        mmr = number(plan['instrument_math']['mmr_proxy_rate'])
        entry = number(plan['entry_price'])
        target = number(plan['targets'][0]['price'])
        if p.long:
            liquidation = entry * (1-1/leverage)/(1-mmr)
            stop_hit, liq_hit, target_hit = candle.low <= stop, liquidation > 0 and candle.low <= liquidation, candle.high >= target
        else:  # mirrored isolated proxy; inactive at leverage 1 below a ~+99 % move
            liquidation = entry * (1+1/leverage)/(1+mmr)
            stop_hit, liq_hit, target_hit = candle.high >= stop, candle.high >= liquidation, candle.low <= target
        worst = min if p.long else max
        if stop_hit or liq_hit:
            ambiguous = target_hit or (stop_hit and liq_hit) or new
            if ambiguous:
                self._event('protection_path', candle.open_ms+MINUTE, plan, ambiguous=True)
            if liq_hit:
                liq_price = worst(candle.open, liquidation)
                stop_price = worst(candle.open, stop)
                cost_rate = self.assumptions.costs.stop_fee_rate + self.assumptions.costs.stop_spread_rate + self.assumptions.costs.stop_slippage_rate
                liq_rate = cost_rate + number(plan['instrument_math']['liquidation_fee_rate'])
                # Compare the cash of each exit after costs, including the extra proxy fee:
                # a long receives price*(1-rate), a short pays price*(1+rate).
                if not stop_hit or (liq_price*(1-liq_rate) <= stop_price*(1-cost_rate) if p.long
                                    else liq_price*(1+liq_rate) >= stop_price*(1+cost_rate)):
                    return liq_price, 'liquidation_proxy', ambiguous
            return worst(candle.open, stop), 'stop', ambiguous
        if target_hit:
            if new:
                self._event('fill_bar_target', candle.open_ms+MINUTE, plan, ambiguous=True)
            else:
                return target, 'target', False
        return None

    def _signal_side(self, payload: Mapping[str, Any]) -> str:
        sides = self.assumptions.kernel_profile.sides
        if len(sides) == 1:
            return sides[0]
        execution = payload.get('execution')
        side = execution.get('side') if isinstance(execution, Mapping) else None
        if side not in sides:
            raise ValueError('research_signal_invalid')
        return side

    def _stream(self, payload: Mapping[str, Any]) -> str:
        return stream_key(payload['symbol'], self._signal_side(payload), self.assumptions.kernel_profile.sides)

    def _validate_signal(self, s: Signal, boundary: int) -> None:
        p = s.payload
        symbol = p.get('symbol')
        side = self._signal_side(p)
        source = (self.assumptions.source(symbol, side) if symbol in self.assumptions.symbols else {})
        if (type(s.index) is not int or s.index < 0 or symbol not in self.assumptions.symbols
            or p.get('schema_version') != 'research-signal-result.v1' or p.get('passed') is not True
            or p.get('evaluated_ms') != boundary or not digest(p.get('result_hash'))
            or not isinstance(p.get('source'), Mapping)
            or any(p['source'].get(k) != source.get(k) for k in ('dataset_id','dataset_sha256'))
            or any(p['source'].get(k) != v for k,v in (('source_venue','binance_usdm'),('source_network','mainnet'),('market_type','perpetual')))
            or canonical_hash({k:v for k,v in p.items() if k != 'result_hash'}) != p['result_hash']):
            raise ValueError('research_signal_invalid')
        baseline = p.get('baseline')
        identity = self.assumptions.cell_identity(side)
        if (not isinstance(baseline, Mapping)
            or any(baseline.get(signal_key) != identity[run_key]
                   for signal_key,run_key in _B1_BASELINE_BINDINGS)):
            raise ValueError('research_signal_baseline_invalid')
        old = self.last_signals.get(self._stream(p))
        if old and (s.index <= old[0] or boundary <= old[1]):
            raise ValueError('research_signal_duplicate_or_out_of_order')

    def _validate_plan(self, p: Mapping[str, Any], s: Signal, view: Mapping[str, Any]) -> None:
        side = self._signal_side(s.payload)
        identity, source = self.assumptions.cell_identity(side), self.assumptions.source(s.payload['symbol'], side)
        if (p.get('schema_version') != 'research-plan.v1' or p.get('research_only') is not True
            or p.get('execution_authority') != 'none' or p.get('symbol') != s.payload['symbol']
            or p.get('signal_result_hash') != s.payload['result_hash'] or p.get('signal_index') != s.index
            or p.get('evaluated_ms') != self.now or p.get('portfolio_hash') != view['portfolio_hash']
            or any(p.get(k) != v for k,v in {**identity,**source}.items())
            or any(p.get(k) != v for k,v in (('source_venue','binance_usdm'),('source_network','mainnet'),('market_type','perpetual')))
            or canonical_hash({k:v for k,v in p.items() if k != 'plan_hash'}) != p.get('plan_hash')):
            raise ValueError('research_plan_binding_invalid')
        i = dict(self.assumptions.instruments)[p['symbol']]
        if (canonical_hash(p['instrument_math']) != canonical_hash(i.wire())
            or canonical_hash(p['cost_model']) != canonical_hash(self.assumptions.costs.wire())):
            raise ValueError('research_plan_assumption_mismatch')
        profile = self.assumptions.kernel_profile
        if profile.extended:
            if p.get('side') not in profile.sides or p.get('side') != side:
                raise ValueError('research_plan_side_invalid')
        elif 'side' in p:
            raise ValueError('research_plan_side_invalid')
        long = p.get('side', 'long') == 'long'
        entry, stop, quantity, leverage, notional = (number(p[k]) for k in
            ('entry_price','stop_price','quantity','final_leverage','position_notional_quote'))
        targets = p['targets']
        if (not (0 < stop < entry if long else 0 < entry < stop) or entry % i.tick_size or stop % i.tick_size
            or quantity % i.quantity_step or not i.min_quantity <= quantity <= i.max_quantity
            or not 1 <= leverage <= i.leverage_cap_assumed
            or number(p['effective_leverage_cap']) != i.leverage_cap_assumed
            or notional != entry*quantity*i.contract_size or not i.min_notional <= notional <= 250
            or len(targets) != 1
            or (number(targets[0]['price']) <= entry if long else not 0 < number(targets[0]['price']) < entry)
            or number(targets[0]['price']) % i.tick_size):
            raise ValueError('research_plan_geometry_invalid')
        deadline = p['holding_deadline_ms']
        latest = self.now + profile.holding_window_seconds*1000
        if profile.daily_boundary:
            latest = min(latest, (self.now//DAY+1)*DAY)
        if (p['entry_ttl_seconds'] != profile.entry_ttl_seconds
            or p['cancel_after_seconds'] != profile.cancel_after_seconds
            or p['holding_deadline_exclusive'] is not True or type(deadline) is not int
            or not self.now+MINUTE < deadline <= latest):
            raise ValueError('research_plan_lifetime_invalid')
        units = quantity*i.contract_size
        c = self.assumptions.costs
        parts = {'gross_stop_loss': abs(entry-stop)*units, 'entry_fee': entry*units*c.entry_fee_rate,
            'stop_exit_fee': stop*units*c.stop_fee_rate, 'entry_spread': entry*units*c.entry_spread_rate,
            'stop_spread': stop*units*c.stop_spread_rate, 'entry_slippage': entry*units*c.entry_slippage_rate,
            'stop_slippage': stop*units*c.stop_slippage_rate,
            'funding_provision': entry*units*c.funding_provision_rate*c.funding_intervals_provisioned}
        risk = sum(parts.values(), ZERO)
        parts['total_stop_loss'] = risk
        # B2b emits binary floats; compare at its explicit finite float edge.
        if (set(p['risk_components_quote']) != set(parts)
            or any(wire_number(v) != p['risk_components_quote'][k] for k,v in parts.items())
            or p['risk_budget_quote'] != wire_number(number(view['equity_quote'])*D('.05'))):
            raise ValueError('research_plan_risk_invalid')
        target = number(targets[0]['price'])
        reward = abs(target-entry)*units - parts['entry_fee'] - parts['entry_spread'] - parts['entry_slippage'] - parts['funding_provision'] - target*units*(c.target_fee_rate+c.target_spread_rate+c.target_slippage_rate)
        balances = self._balances()
        if (risk <= 0 or risk > balances['equity_quote']*D('.05')
            or reward/risk < D('1.3') or number(targets[0]['net_r']) < D('1.3')
            or wire_number((reward/risk).quantize(D('1e-18'),rounding=ROUND_DOWN)) != targets[0]['net_r']
            or wire_number(reward) != targets[0]['net_reward_quote']
            or wire_number(risk) != targets[0]['net_risk_quote']):
            raise ValueError('research_plan_net_r_invalid')
        if risk > daily_capacity(balances['equity_quote'], balances['realized_net_pnl_quote'],
                                 balances['unrealized_net_pnl_quote'], balances['reserved_risk_quote'],
                                 profile.daily_loss_rate, profile.daily_loss_absolute_quote):
            raise ValueError('research_portfolio_daily_loss_exceeded')
        if profile.one_active_per_symbol and p['symbol'] in self._active_symbols():
            raise ValueError('research_portfolio_symbol_active')
        if view['open_positions'] + view['pending_entries'] >= profile.max_active:
            raise ValueError('research_portfolio_concurrency_exceeded')
        if notional+balances['open_notional_quote']+balances['pending_notional_quote'] > balances['equity_quote']:
            raise ValueError('research_portfolio_exposure_exceeded')
        if notional/leverage > balances['available_balance_quote']:
            raise ValueError('research_portfolio_margin_exceeded')

    def _statistics(self) -> None:
        balances = self._balances()
        equity = balances['equity_quote']
        self.peak_equity = max(self.peak_equity,equity)
        self.maximum_drawdown = max(self.maximum_drawdown, self.peak_equity-equity)
        self.maximum_risk = max(self.maximum_risk, balances['reserved_risk_quote'])
        self.maximum_exposure = max(self.maximum_exposure,balances['open_notional_quote']+balances['pending_notional_quote'])
        self._marked_sample()

    def _marked_sample(self, *, kind: str = 'boundary') -> None:
        """Sparse evidence for the existing statistics sampling, not a new mark model.

        Flat unchanged wallets cannot change equity. Active positions must emit
        every completed minute; initial/terminal samples bind the whole clock.
        """
        if self.marked_sink is not None and (kind != 'boundary' or self.positions
                or self._marked_positions or self._marked_cash_dirty or self.wallet != self._marked_wallet):
            self.marked_sink({'schema_version':'research-marked-equity.v1',
                'timestamp_ms':self.now,'processed_batches':self.processed_batches,
                'kind':kind,'mark_source':'last_known_close.v1',
                'marks':{key:self.marks[p.plan['symbol']] for key,p in self.positions.items()}})
            self._marked_wallet = self.wallet
            self._marked_positions = bool(self.positions)
            self._marked_cash_dirty = False

    def _validate_candles(self, candles: Sequence[Candle], open_ms: int) -> dict[str, Candle]:
        if len(candles) != len(self.assumptions.symbols):
            raise ValueError('research_coverage_gap')
        batch = {c.symbol:c for c in candles}
        if set(batch) != set(self.assumptions.symbols) or len(batch) != len(candles):
            raise ValueError('research_coverage_gap')
        for c in candles:
            c.validate()
            if c.open_ms != open_ms:
                raise ValueError('research_coverage_gap')
        return batch

    def _validate_funding(self, funding: Sequence[FundingEvent], *, startup: bool = False) -> None:
        seen = set()
        for event in funding:
            if (event.symbol not in self.assumptions.symbols or type(event.timestamp_ms) is not int
                or not self.assumptions.start_ms <= event.timestamp_ms < self.assumptions.end_ms
                or (event.timestamp_ms != self.assumptions.start_ms if startup
                    else not self.now < event.timestamp_ms <= self.now+MINUTE)
                or not isinstance(event.rate,D) or not event.rate.is_finite() or abs(event.rate) >= 1
                or (event.symbol,event.timestamp_ms) in seen
                or ((event.mark_price is None) != (event.mark_observed_ms is None))
                or (event.mark_price is not None and (not isinstance(event.mark_price,D)
                    or not event.mark_price.is_finite() or event.mark_price <= 0
                    or event.mark_observed_ms != event.timestamp_ms))):
                raise ValueError('research_funding_event_invalid')
            seen.add((event.symbol,event.timestamp_ms))

    def _validate_signals(self, signals: Sequence[Signal], boundary: int) -> None:
        # One signal per stream and boundary: a pair's long and short streams may both decide.
        seen_streams = set()
        for s in signals:
            self._validate_signal(s,boundary)
            if self._stream(s.payload) in seen_streams or boundary >= self.assumptions.end_ms:
                raise ValueError('research_signal_duplicate_or_outside_window')
            seen_streams.add(self._stream(s.payload))

    def prime(self, prephase_candles: Sequence[Candle], *,
              funding: Sequence[FundingEvent] = (), signals: Sequence[Signal] = ()) -> dict[str, Any]:
        """Initialize exactly at phase start with the complete preceding minute.

        These closes become known at start_ms and never count as phase bars.
        Exact-start funding observations count before any signal is admitted;
        there is no prephase position and therefore no startup funding PnL.
        Input validation is atomic; planner/sink failures invalidate the run.
        """
        if self.finished or self.failed:
            raise ValueError('research_simulator_finished_or_failed')
        if self.primed:
            raise ValueError('research_simulator_already_primed')
        batch = self._validate_candles(prephase_candles,self.assumptions.start_ms-MINUTE)
        self._validate_funding(funding,startup=True)
        self._validate_signals(signals,self.assumptions.start_ms)
        try:
            self.marks = {s:c.close for s,c in batch.items()}
            for event in funding:
                self.funding_counts[event.symbol] += 1
            self.primed = True
            self._marked_sample(kind='initial')
            self._admit_signals(tuple(signals))
        except Exception:
            self.failed = True
            raise
        return self.view()

    def advance(self, candles: Sequence[Candle], *, funding: Sequence[FundingEvent] = (),
                signals: Sequence[Signal] = ()) -> dict[str, Any]:
        if self.finished or self.failed:
            raise ValueError('research_simulator_finished_or_failed')
        if not self.primed:
            raise ValueError('research_simulator_not_primed')
        if self.now >= self.assumptions.end_ms:
            raise ValueError('research_coverage_gap')
        batch = self._validate_candles(candles,self.now)
        boundary = self.now+MINUTE
        self._validate_funding(funding)
        self._validate_signals(signals,boundary)
        try:
            self._advance(batch,tuple(funding),tuple(signals),boundary)
        except Exception:
            # A failed sink/planner aborts the segment; no retry/resume claims.
            self.failed = True
            raise
        return self.view()

    def _advance(self, batch: Mapping[str,Candle], funding: tuple[FundingEvent,...],
                 signals: tuple[Signal,...], boundary: int) -> None:
        old_keys = set(self.positions)
        outcomes: dict[str, tuple[Decimal,str,int,int,bool]] = {}
        profile = self.assumptions.kernel_profile
        for key,plan in tuple(self.pending.items()):
            expiry = plan['evaluated_ms']+profile.entry_ttl_seconds*1000
            c = batch[plan['symbol']]
            # A resting buy can gap below its limit, a resting sell above it.
            touched = (c.low <= number(plan['entry_price']) if plan.get('side', 'long') == 'long'
                       else c.high >= number(plan['entry_price']))
            if boundary > expiry:
                if touched:
                    self._event('ttl_touch',expiry,plan,ambiguous=True)
                self.pending.pop(key)
                self._event('entry_expired',expiry,plan)
            elif c.open_ms >= plan['evaluated_ms'] and touched and boundary < plan['holding_deadline_ms']:
                p = _Position(plan,c.open_ms,boundary)
                self.positions[key] = p
                self.pending.pop(key)
                self._costs(p,'entry',number(plan['entry_price']),boundary)
                self._event('limit_fill_proxy',boundary,plan)
        for key,p in self.positions.items():
            deadline = p.plan['holding_deadline_ms']
            if deadline <= boundary:
                reason = ('midnight' if profile.daily_boundary and deadline//DAY > p.plan['evaluated_ms']//DAY
                          else 'holding_deadline')
                outcomes[key] = (self.marks[p.plan['symbol']],reason,deadline,self.now,False)
            else:
                outcome = self._exit(p,batch[p.plan['symbol']],key not in old_keys)
                if outcome:
                    price,reason,ambiguous = outcome
                    outcomes[key] = (price,reason,boundary,boundary,ambiguous)
        for event in sorted(funding,key=lambda e:(e.timestamp_ms,self.assumptions.symbols.index(e.symbol))):
            self.funding_counts[event.symbol] += 1
            for key,p in self.positions.items():
                if p.plan['symbol'] != event.symbol or event.timestamp_ms >= p.plan['holding_deadline_ms']:
                    continue
                exiting = key in outcomes
                if event.timestamp_ms == boundary and exiting:
                    continue
                uncertain_exit = exiting and outcomes[key][1] not in ('holding_deadline','midnight')
                # fill_boundary_ms records when the completed OHLC becomes known,
                # not an entry execution at that instant. A surviving prior-bar
                # fill occurred in [open_ms,boundary), so its exposure immediately
                # before exact-boundary funding is certain for either rate sign.
                # Plans admitted at this boundary arrive only after settlement.
                ambiguous = event.timestamp_ms < boundary and (key not in old_keys or uncertain_exit)
                if ambiguous:
                    self._event('funding_path',event.timestamp_ms,p.plan,ambiguous=True)
                # A long pays a positive rate and a short receives it; an uncertain credit is dropped.
                charged = not ambiguous or (event.rate > 0 if p.long else event.rate < 0)
                proxy = event.mark_price if event.mark_price is not None else self.marks[p.plan['symbol']]
                amount = ((-p.units if p.long else p.units)*proxy*event.rate) if charged else ZERO
                p.funding += amount
                self._cash(p,'funding',amount,event.timestamp_ms,rate=event.rate,mark_price=proxy,
                    mark_source='verified_observation' if event.mark_price is not None else 'last_known_close.v1',
                    mark_known_ms=event.mark_observed_ms if event.mark_price is not None else self.now,
                    ambiguous=ambiguous,exposure_policy=self.assumptions.funding_path_policy)
        for key,(price,reason,at,known,ambiguous) in outcomes.items():
            self._close(key,self.positions[key],price,reason,at,known,ambiguous)
        self.marks = {s:c.close for s,c in batch.items()}
        if boundary//DAY != self.now//DAY:
            self.daily_realized = ZERO
        self.now = boundary
        self.processed_batches += 1
        self._statistics()
        self._admit_signals(signals)

    def _admit_signals(self, signals: tuple[Signal,...]) -> None:
        sides = self.assumptions.kernel_profile.sides
        for s in sorted(signals,key=lambda s:(self.assumptions.symbols.index(s.payload['symbol']),
                                              sides.index(self._signal_side(s.payload)))):
            self.attempted_signals += 1
            self.last_signals[self._stream(s.payload)] = (s.index,self.now)
            view = self.view()
            result = self.builder(s,FrozenJsonDict(view))
            # A callback contract/protocol failure invalidates the experiment;
            # it must not become a normal rejected candidate or zero-trade run.
            if not isinstance(result, Mapping):
                raise ValueError('research_plan_response_invalid')
            try:
                if result.get('schema_version') == 'research-plan-rejection.v1':
                    raise ValueError(str(result['reason_code']))
                self._validate_plan(result,s,view)
                frozen = FrozenJsonDict(result)
            except (ValueError,KeyError,TypeError,ArithmeticError) as exc:
                reason = str(exc) if isinstance(exc,ValueError) else 'research_plan_shape_invalid'
                self.rejection_counts[reason] += 1
                self.rejection_sink({'schema_version':'research-simulation-rejection.v1',
                    **self.assumptions.cell_identity(self._signal_side(s.payload)),
                    **self.assumptions.source(s.payload['symbol'], self._signal_side(s.payload)),
                    'symbol':s.payload['symbol'],'signal_result_hash':s.payload['result_hash'],
                    'signal_index':s.index,'evaluated_ms':self.now,'reason_code':reason})
                continue
            self.pending[frozen['plan_hash']] = frozen
            self.admitted_plans += 1
            self._event('admitted',self.now,frozen)
            self._statistics()

    def finish(self) -> dict[str, Any]:
        if self.finished or self.failed:
            raise ValueError('research_simulator_finished_or_failed')
        if not self.primed:
            raise ValueError('research_simulator_not_primed')
        if self.now != self.assumptions.end_ms or self.processed_batches != (self.assumptions.end_ms-self.assumptions.start_ms)//MINUTE:
            raise ValueError('research_source_incomplete')
        if any(self.funding_counts[s] != n for s,n in self.assumptions.funding.expected_counts):
            raise ValueError('research_funding_coverage_incomplete')
        try:
            return self._finish()
        except Exception:
            self.failed = True
            raise

    def _finish(self) -> dict[str, Any]:
        for key,p in tuple(self.positions.items()):
            self._close(key,p,self.marks[p.plan['symbol']],'research_end',self.now,self.now)
        for key,p in tuple(self.pending.items()):
            self._event('research_end_pending',self.now,p)
            self.pending.pop(key)
        if self.wallet != D('100000')+self.total_cashflow:
            raise ValueError('research_wallet_unreconciled')
        self.finished = True
        self._statistics()
        self._marked_sample(kind='terminal')
        return {'schema_version':'research-portfolio-summary.v1',**dict(self.assumptions.identity),
            'start_ms':self.assumptions.start_ms,'end_ms':self.assumptions.end_ms,
            'phase':self.assumptions.phase,'symbol_priority':self.assumptions.symbols,
            'fill_policy':'conservative_closed_ohlc.v1',
            'holding_settlement_policy':'prior_close_exclusive_deadline.v1',
            'funding_path_policy':self.assumptions.funding_path_policy,
            'funding_mark_policy':self.assumptions.funding_mark_policy,
            'completion':'complete' if self.assumptions.funding.status == 'verified_complete' else 'inconclusive',
            'funding_coverage':self.assumptions.funding.status,'wallet_quote':self.wallet,
            'net_pnl_quote':self.total_cashflow,'processed_batches':self.processed_batches,
            'trades':self.trades,'attempted_signals':self.attempted_signals,
            'admitted_plans':self.admitted_plans,'maximum_drawdown_quote':self.maximum_drawdown,
            'peak_equity_quote':self.peak_equity,'maximum_reserved_risk_quote':self.maximum_risk,
            'maximum_exposure_quote':self.maximum_exposure,'rejection_counts':dict(self.rejection_counts),
            'ambiguity_counts':dict(self.ambiguity_counts),
            **({'kernel_profile':self.assumptions.kernel_profile.wire()}
               if self.assumptions.kernel_profile.extended else {}),
            'execution_authority':'none'}
