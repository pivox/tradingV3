"""H3-A opportunity scoring engine (research prototype, synthetic data only).

Exchange- and runtime-independent, interpretable, deterministic. Modules:
- ``arith``: exact decimal arithmetic (18 decimals, truncation toward zero) shared with the future PHP port;
- ``features``: the feature catalogue ``h3-scoring-features`` 0.1.0 and pure, lookahead-free feature functions;
- ``model``: ModelManifest helpers (reference null, linear/ridge layout, validation, B1/B3 readiness rule);
- ``engine``: ``ScoringEngine``, the ``contracts.OpportunityScorer`` implementation (score, rank);
- ``fixtures``: synthetic data and a labelled SYNTHETIC TEST FIXTURE model, for tests and golden vectors only;
- ``parity``: versioned golden vectors for a PHP port.

The engine never generates candidates and never turns a score into an order. Without a calibrated model it returns
UNESTIMATED scores carrying no number. Spec: docs/handbook/research/H3-OPPORTUNITY-SCORING.md.
"""
from .engine import ScoreTrace, ScoringEngine, ScoringError
from .features import CATALOGUE, FeatureSpec, FeatureSpecSet, Role, Source, build_snapshot, portfolio_correlation
from .model import (
    ScoringPolicy, build_linear_manifest, decode_manifest, reference_null_manifest, scored_variant_readiness,
    validate_manifest,
)

__all__ = ['CATALOGUE', 'FeatureSpec', 'FeatureSpecSet', 'Role', 'ScoreTrace', 'ScoringEngine', 'ScoringError',
           'ScoringPolicy', 'Source', 'build_linear_manifest', 'build_snapshot', 'decode_manifest',
           'portfolio_correlation', 'reference_null_manifest', 'scored_variant_readiness', 'validate_manifest']
