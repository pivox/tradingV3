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
from app.backtesting.dataset_store import (
    DatasetPublicationConflict,
    DatasetPublicationResult,
    DatasetPublicationStatus,
    DatasetPublisher,
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
    "DatasetSerializer",
    "MissingRange",
    "Timeframe",
)
