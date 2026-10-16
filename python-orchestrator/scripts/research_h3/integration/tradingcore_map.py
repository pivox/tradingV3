"""Data map from the H3 contracts to TradingCore's canonical PHP classes, with the gaps that block a migration.

Pure data, no PHP is executed and no PHP file is changed. Status of each row:
- ``mapped``: same meaning, same unit; a value can be passed through;
- ``derived``: computable from the PHP side (or vice versa) with a stated rule;
- ``divergent``: both sides have the concept with different semantics (documented, never claimed equivalent);
- ``gap``: PHP has no equivalent; listed again in ``BLOCKING_GAPS`` when it blocks a migration step;
- ``research_only``: H3 research metadata with no runtime counterpart by design.

A test checks every H3 field against the contract dataclasses and every PHP field against the PHP source text, so
the map fails loudly when either side drifts. Paths are repository-relative. Static reading on 2026-10-15 at
31bda7e8; no PHP test was run for this map.
"""
from __future__ import annotations

import enum
from dataclasses import dataclass


class MapStatus(str, enum.Enum):
    MAPPED = 'mapped'
    DERIVED = 'derived'
    DIVERGENT = 'divergent'
    GAP = 'gap'
    RESEARCH_ONLY = 'research_only'


TC = 'trading-app/src/TradingCore/'
PHP_CLASSES: dict[str, str] = {
    'CanonicalRiskCalculationRequest': TC + 'Risk/Canonical/CanonicalRiskCalculationRequest.php',
    'CanonicalRiskPolicy': TC + 'Risk/Canonical/CanonicalRiskPolicy.php',
    'CanonicalCostSnapshot': TC + 'Risk/Canonical/CanonicalCostSnapshot.php',
    'CanonicalRiskDecision': TC + 'Risk/Canonical/CanonicalRiskDecision.php',
    'CanonicalOrderPlan': TC + 'OrderPlan/Canonical/CanonicalOrderPlan.php',
    'CanonicalOrderPlanTarget': TC + 'OrderPlan/Canonical/CanonicalOrderPlanTarget.php',
    'CanonicalStopPolicy': TC + 'OrderPlan/Canonical/CanonicalStopPolicy.php',
    'CanonicalPortfolioAdmissionRequest': TC + 'Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionRequest.php',
    'CanonicalPortfolioSnapshot': TC + 'Risk/Canonical/Portfolio/CanonicalPortfolioSnapshot.php',
    'CanonicalPortfolioPolicy': TC + 'Risk/Canonical/Portfolio/CanonicalPortfolioPolicy.php',
    'CanonicalPortfolioReservation': TC + 'Risk/Canonical/Portfolio/CanonicalPortfolioReservation.php',
    'FakeExecutionScenario': TC + 'Execution/Fake/FakeExecutionScenario.php',
    'DemoTradingKillSwitchDecision': TC + 'Execution/Safety/DemoTradingKillSwitchDecision.php',
}


@dataclass(frozen=True)
class FieldMap:
    h3_contract: str
    h3_field: str
    php_class: str | None
    php_field: str | None
    status: MapStatus
    note: str


def _m(contract: str, h3_field: str, php_class: str | None, php_field: str | None, status: MapStatus,
       note: str) -> FieldMap:
    return FieldMap(contract, h3_field, php_class, php_field, status, note)


M, DV, DG, G, R = MapStatus.MAPPED, MapStatus.DERIVED, MapStatus.DIVERGENT, MapStatus.GAP, MapStatus.RESEARCH_ONLY

FIELD_MAP: tuple[FieldMap, ...] = (
    # instrument
    _m('InstrumentRef', 'inst_id', 'CanonicalRiskCalculationRequest', 'symbol', G,
       'X-Perp ids (BTC-USD_UM_XPERP-...) are rejected by the OKX resolver; see GAP-XPERP'),
    _m('InstrumentRef', 'product', 'CanonicalRiskCalculationRequest', 'marketType', G,
       'PHP marketType is perpetual (instType SWAP hardcoded); X-Perps are FUTURES/xperp'),
    _m('InstrumentRef', 'settle_ccy', 'CanonicalRiskCalculationRequest', 'quoteCurrency', DG,
       'PHP assumes one quote currency (USDT in budgets and PnL); X-Perps price in USD and margin in USDC'),
    _m('InstrumentRef', 'contract_value', 'CanonicalRiskCalculationRequest', 'contractSize', M, 'ctVal'),
    _m('InstrumentRef', 'lot_size', 'CanonicalRiskCalculationRequest', 'quantityStep', M, ''),
    _m('InstrumentRef', 'min_size', 'CanonicalRiskCalculationRequest', 'minQuantity', M, ''),
    _m('InstrumentRef', 'min_notional', 'CanonicalRiskPolicy', 'exchangeMinNotional', DV,
       'PHP reads it from the exchange config layer, not from instrument metadata'),
    _m('InstrumentRef', 'tick_size', 'CanonicalOrderPlan', 'tickSize', M, ''),
    # candidate
    _m('OpportunityCandidate', 'entry_reference_price', 'CanonicalRiskCalculationRequest', 'entryPrice', DV,
       'PHP requires a limit entry inside an entry zone; H3 enters at market (zone of width 0 at best)'),
    _m('OpportunityCandidate', 'initial_stop_price', 'CanonicalRiskCalculationRequest', 'stopPrice', M,
       'sizing input; but the protection engine only derives stops from ATR or pivot, see GAP-STOP-KIND'),
    _m('OpportunityCandidate', 'initial_stop_price', 'CanonicalStopPolicy', 'kind', G,
       'no stop kind carries an explicit stop price given by a candidate'),
    _m('OpportunityCandidate', 'initial_target_price', 'CanonicalOrderPlanTarget', 'price', DV,
       'PHP requires >= 1 target with netR >= minimumNetR; an H3 candidate may have no target'),
    _m('OpportunityCandidate', 'max_holding_ms', 'CanonicalOrderPlan', 'holdingExpiresAt', DV,
       'created_at + max_holding; PHP holding boundaries are per mode'),
    _m('OpportunityCandidate', 'candidate_id', 'CanonicalPortfolioAdmissionRequest', 'decisionKey', DV,
       'one decision key per (portfolio, candidate); PHP refuses a key already active'),
    _m('OpportunityCandidate', 'hypothesis_id', None, None, R, 'research provenance, no runtime counterpart'),
    # H3 limits
    _m('H3Limits', 'per_trade_risk_max', 'CanonicalRiskPolicy', 'riskRate', M,
       'mode risk.trade_budget percent_equity_per_trade = 0.5; both cost-inclusive (totalStopLoss)'),
    _m('H3Limits', 'total_risk_max', 'CanonicalPortfolioPolicy', 'dailyLossRate', DG,
       'PHP caps realised + unrealised loss + reserved risk per policy day; H3 caps open risk at any instant'),
    _m('H3Limits', 'leverage_max', 'CanonicalRiskPolicy', 'modeLeverageCap', DG,
       'PHP leverage = ceil(notional / available balance) per position, an integer; H3 = portfolio notional / '
       'equity <= 1. modeExposureRate = 100 % gives the portfolio cap'),
    _m('H3Limits', 'leverage_max', 'CanonicalPortfolioPolicy', 'modeExposureRate', DV,
       'percent_equity_notional = 100 reproduces portfolio notional <= equity (pending entries included)'),
    _m('H3Limits', 'stop_required', None, None, M,
       'runtime safety.require_stop_loss and environment.require_stop_loss must be true (CanonicalRiskPolicy)'),
    _m('H3Limits', 'adaptation_may_increase_risk', None, None, G,
       'no canonical transition validates an adaptive action; see GAP-ADAPTIVE-TRANSITIONS'),
    # portfolio
    _m('PortfolioView', 'equity', 'CanonicalPortfolioSnapshot', 'equityQuote', M, ''),
    _m('PortfolioView', 'cash', 'CanonicalRiskCalculationRequest', 'availableBalanceQuote', DV,
       'available = cash - margin in use (H3 at 1x: margin = entry notional of open and pending)'),
    _m('PortfolioView', 'open_risk', 'CanonicalPortfolioSnapshot', 'reservedRiskQuote', DG,
       'PHP keeps the plan totalStopLoss until close; H3 recomputes with the current stop and quantity'),
    _m('PortfolioView', 'open_notional', 'CanonicalPortfolioSnapshot', 'openNotionalQuote', DV,
       'H3 open_notional = openNotionalQuote + pendingNotionalQuote'),
    _m('PortfolioView', 'open_position_ids', 'CanonicalPortfolioSnapshot', 'activeDecisionKeys', DV,
       'PHP tracks active decision keys, not instruments; per-symbol occupancy is unavailable (GAP-OCCUPANCY)'),
    _m('PortfolioView', 'kill_switch', 'DemoTradingKillSwitchDecision', 'allowed', DV,
       'PHP kill switch is evaluated per mutation attempt at the adapter, not carried in the snapshot'),
    # risk decision
    _m('RiskDecision', 'quantity', 'CanonicalRiskDecision', 'quantity', M, 'contracts, floored to quantityStep'),
    _m('RiskDecision', 'risk_amount', 'CanonicalRiskDecision', 'totalStopLoss', M,
       'same components: gross + entry/stop fee + spreads + slippage + adverse funding provision'),
    _m('RiskDecision', 'notional_after', 'CanonicalRiskDecision', 'positionNotional', DV,
       'PHP gives the position notional; H3 the portfolio total after the decision'),
    _m('RiskDecision', 'leverage_after', 'CanonicalRiskDecision', 'finalLeverage', DG, 'see H3Limits.leverage_max'),
    _m('RiskDecision', 'decision_id', 'CanonicalPortfolioAdmissionRequest', 'decisionKey', DV, ''),
    _m('RiskDecision', 'subject', None, None, G,
       'PHP decides entries only; an ACTION subject has no PHP decision (GAP-ADAPTIVE-TRANSITIONS)'),
    # positions and actions
    _m('PositionState', 'current_stop', 'CanonicalPortfolioReservation', 'stopPrice', DG,
       'fixed at admission in PHP; no transition updates it after a stop is tightened'),
    _m('PositionState', 'initial_risk_amount', 'CanonicalPortfolioReservation', 'reservedRiskQuote', DV,
       'R unit = reserved risk at fill'),
    _m('AdaptiveAction', 'new_stop', None, None, G,
       'no amend operation exists; the live path replaces a stop by cancel then place (GAP-STOP-REPLACE)'),
    _m('AdaptiveAction', 'close_quantity', None, None, G,
       'reservation store has close() for the whole position only (GAP-ADAPTIVE-TRANSITIONS)'),
    _m('AdaptiveAction', 'new_target', None, None, G, 'no canonical target-change transition'),
    # costs
    _m('CostModel', 'maker_fee_rate', 'CanonicalRiskPolicy', 'makerFeeRate', M, 'exchange.fees.maker_rate'),
    _m('CostModel', 'taker_fee_rate', 'CanonicalRiskPolicy', 'takerFeeRate', M, 'exchange.fees.taker_rate'),
    _m('CostModel', 'slippage_rate', 'CanonicalCostSnapshot', 'entrySlippageRate', DV,
       'H3 one rate for every market leg; PHP entry and stop rates separately'),
    _m('CostModel', 'spread_model', 'CanonicalCostSnapshot', 'entrySpreadRate', G,
       'H3 carries a model id, PHP a numeric rate (contract change request CR-1)'),
    _m('CostModel', 'funding_model', 'CanonicalCostSnapshot', 'fundingRate', DV,
       'PHP provision = adverse rate x fundingIntervals x entry notional; H3 provision is a DRAFT assumption'),
    # audit
    _m('DecisionAudit', 'previous_audit_fingerprint', 'CanonicalPortfolioReservation', 'previousStateHash', DV,
       'both are hash chains; PHP chains the reservation states, H3 the decision journal'),
    _m('ExperimentManifest', 'variants', None, None, R, 'B0-B3 factorial design, research only'),
    _m('ModelManifest', 'parameters', None, None, R, 'no scoring model exists in PHP'),
)


@dataclass(frozen=True)
class BlockingGap:
    gap_id: str
    title: str
    evidence: tuple[str, ...]
    consequence: str
    required_change: str
    blocks: str


BLOCKING_GAPS: tuple[BlockingGap, ...] = (
    BlockingGap('GAP-XPERP', 'OKX code is USDT-SWAP only; the only French execution target is OKX Europe X-Perps',
                ('docs/handbook/research/H1-XPERP-TECHNICAL-GAP.md', 'docs/handbook/research/FRANCE-EXCHANGE-ELIGIBILITY.md'),
                'no H3 order can reach an X-Perp: resolver throws, instType SWAP hardcoded, normalisers drop non-SWAP '
                'stop and fill events, USDT assumed in account, PnL, fees and mode budgets',
                'X-Perp resolver, configurable instType/settle currency, USD/USDC accepted (owner decision)',
                'any paper, demo or live execution of H3 on the target venue'),
    BlockingGap('GAP-STOP-REPLACE', 'Protective-order replacement is cancel then place, not atomic',
                ('trading-app/src/TradeEntry/Service/TpSlTwoTargetsService.php',
                 'trading-app/src/Exchange/Dto/ExchangeCapabilities.php'),
                'TIGHTEN_STOP on a live position opens a window without a stop (place fails after cancel) or leaves '
                'a duplicate stop (cancel failed and is only logged); supportsModifyOrder is false and never read; '
                'no test covers the sequence',
                'an amend (or place-then-cancel with reconciliation) path with tests of every failure ordering',
                'any adaptive stop management outside simulation (H3-B in demo or live)'),
    BlockingGap('GAP-ADAPTIVE-TRANSITIONS', 'No canonical transition for tighten-stop, partial close or target change',
                ('trading-app/src/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioReservationStoreInterface.php',
                 'trading-app/src/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioReservation.php'),
                'reserved risk stays at the admission stop and full quantity until close; adaptive actions cannot be '
                'validated or recorded by TradingCore',
                'new reservation transitions (stop update, partial reduction) that never increase reserved risk',
                'porting H3RiskGate.validate_action to PHP; paper replay of B2/B3'),
    BlockingGap('GAP-TOTAL-RISK', 'H3 total open risk 1 % has no PHP equivalent',
                ('trading-app/src/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngine.php',),
                'the daily loss cap mixes realised, unrealised and reserved amounts per day; it is not an open-risk cap',
                'an open-risk cap in the portfolio policy, or an owner-approved mapping',
                'H3 portfolio admission in PHP'),
    BlockingGap('GAP-OCCUPANCY', 'Per-instrument occupancy is not available to the admission engine',
                ('trading-app/src/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngine.php',),
                'perSymbolLimit makes the engine throw canonical_portfolio_symbol_occupancy_unavailable; H3 refuses a '
                'second position on an open instrument',
                'occupied symbols in CanonicalPortfolioSnapshot', 'H3 portfolio admission in PHP'),
    BlockingGap('GAP-MARKET-ENTRY', 'Canonical order plans are limit-only inside an entry zone',
                ('trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanValidator.php',),
                'H3 simulates a market entry at the next open with a risk re-check at the fill price; the canonical '
                'validator refuses orderType != limit and any market fallback',
                'either H3 adopts a marketable limit (IOC at the collar price) expressible as a zone, or a new plan '
                'version allows it', 'any canonical order plan for an H3 entry'),
    BlockingGap('GAP-STOP-KIND', 'The protection engine derives stops from ATR or pivot only',
                ('trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalProtectionEngine.php',),
                'an H3 candidate carries its own stop price; no stop kind accepts it',
                'a stop kind for an explicit, provenance-checked stop price', 'canonical order plan for H3'),
    BlockingGap('GAP-MODE-CONTRACT', 'No H3 mode or setup contract exists, and mode budgets must be USDT',
                ('trading-app/config/trading/runtime', 'trading-app/src/TradingCore/Mode/ModeContractValidator.php'),
                'CanonicalRiskPolicy and CanonicalPortfolioPolicy compile only from an executable effective config',
                'publish an H3 mode/setup contract version (owner go, hash-checked, immutable)',
                'any use of the canonical engines for H3'),
    BlockingGap('GAP-CI-COVERAGE', 'TradingCore risk, order plan and execution tests are not run by CI',
                ('.github/workflows/obs003-trading.yml', '.github/workflows/trading-core-config.yml'),
                'only Paper execution tests exercise the canonical engines in CI; a regression there would pass CI',
                'add tests/TradingCore/{Risk,OrderPlan,Execution} to a path-filtered workflow',
                'trusting any PHP-side H3 change'),
)


def rows_for(contract: str) -> tuple[FieldMap, ...]:
    return tuple(row for row in FIELD_MAP if row.h3_contract == contract)


def gap_ids() -> tuple[str, ...]:
    return tuple(gap.gap_id for gap in BLOCKING_GAPS)
