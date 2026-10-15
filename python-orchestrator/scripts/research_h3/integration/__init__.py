"""H3 integration (agent D): risk admission, simulated execution, audit and the map to TradingCore.

Target flow (H3-RISK-AND-EXECUTION.md): Candidate Provider -> Opportunity Scoring -> Risk Admission -> Order Plan ->
Fake/Paper Execution -> Adaptive Management -> Risk Validation -> Audit. In this package:

- ``costs``: cost, risk and position arithmetic shared by the gate and the simulator (TradingCore conventions);
- ``risk_gate``: ``H3RiskGate`` (``contracts.RiskGate``): sizing at 0.5 % / 1 % / 1x, action validation;
- ``simulator``: ``run_variant`` replays one variant portfolio bar by bar with fills, funding, gaps and failures;
- ``audit``: ``AuditJournal`` (``contracts.AuditSink``), append-only hash chain per experiment and variant;
- ``candidates``: ``SyntheticCandidateProvider`` (``contracts.CandidateProvider``), no economic claim;
- ``tradingcore_map``: H3 contract fields -> PHP canonical DTO fields, and the blocking gaps.
- ``label_replay``: ``capacity_free_labels``, every candidate replayed alone for capacity-free labels (added at
  review R1-F3);
- ``validation_adapter``: ``RunVariantSimulator``, ``run_variant`` behind the validation harness's ``VariantSimulator``
  protocol (added at integration; imported explicitly, not re-exported, so this package does not import ``validation``).

Scores never reach an order without ``H3RiskGate.admit_entry``; adaptive actions never change a position without
``H3RiskGate.validate_action``. Research prototype: synthetic data only, no order, no exchange, no secret, no
Temporal or Symfony dependency, no production configuration.
"""
from .audit import AuditJournal
from .candidates import SyntheticCandidateProvider, synthetic_snapshot
from .costs import ExecutionAssumptions
from .risk_gate import H3RiskConfig, H3RiskGate
from .simulator import FailureKind, FailurePlan, InjectedFailure, Refusal, Trade, VariantRunResult, run_variant

__all__ = [
    'AuditJournal', 'ExecutionAssumptions', 'FailureKind', 'FailurePlan', 'H3RiskConfig', 'H3RiskGate',
    'InjectedFailure', 'Refusal', 'SyntheticCandidateProvider', 'Trade', 'VariantRunResult', 'run_variant',
    'synthetic_snapshot',
]
