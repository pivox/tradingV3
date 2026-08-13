"""Backtesting contracts for TradingV3.

This package intentionally starts with stable data contracts only. Backtrader
adapters and execution simulation live in later #191 slices.
"""

from app.backtesting.dataset import (
    CandleRecord,
    DatasetArtifacts,
    DatasetArtifactVerificationError,
    DatasetBuilder,
    DatasetBuildRejected,
    DatasetBuildResult,
    DatasetQualityReport,
    DatasetSourceIdentity,
    DatasetStreamQuality,
    DatasetSerializer,
    MissingRange,
    Timeframe,
)
from app.backtesting.contracts import DatasetStreamCoverage
from app.backtesting.dataset_store import (
    DatasetPublicationConflict,
    DatasetPublicationResult,
    DatasetPublicationStatus,
    DatasetPublisher,
)
from app.backtesting.tradingcore_bridge import (
    BacktestTradingCoreBridge,
    CanonicalBacktestRuleRequest,
    CanonicalBacktestRuleResult,
    CanonicalIndicatorSnapshot,
    TradingCoreBridgeError,
)
from app.backtesting.indicator_bridge import (
    BacktestIndicatorBridge,
    CanonicalIndicatorDatasetBinding,
    CanonicalIndicatorProjectionRequest,
    CanonicalIndicatorProjectionResult,
    CanonicalProjectedIndicatorSnapshot,
    IndicatorBridgeError,
    VerifiedIndicatorWindowBuilder,
)

__all__ = (
    "CandleRecord",
    "DatasetArtifacts",
    "DatasetArtifactVerificationError",
    "DatasetBuilder",
    "DatasetBuildRejected",
    "DatasetBuildResult",
    "DatasetQualityReport",
    "DatasetPublicationConflict",
    "DatasetPublicationResult",
    "DatasetPublicationStatus",
    "DatasetPublisher",
    "DatasetSourceIdentity",
    "DatasetStreamQuality",
    "DatasetStreamCoverage",
    "DatasetSerializer",
    "MissingRange",
    "Timeframe",
    "BacktestTradingCoreBridge",
    "CanonicalBacktestRuleRequest",
    "CanonicalBacktestRuleResult",
    "CanonicalIndicatorSnapshot",
    "TradingCoreBridgeError",
    "BacktestIndicatorBridge",
    "CanonicalIndicatorDatasetBinding",
    "CanonicalIndicatorProjectionRequest",
    "CanonicalIndicatorProjectionResult",
    "CanonicalProjectedIndicatorSnapshot",
    "IndicatorBridgeError",
    "VerifiedIndicatorWindowBuilder",
)
