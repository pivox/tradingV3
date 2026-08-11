"""Backtesting contracts for TradingV3.

This package intentionally starts with stable data contracts only. Backtrader
adapters and execution simulation live in later #191 slices.
"""

from app.backtesting.dataset import (
    CandleRecord,
    DatasetBuilder,
    DatasetBuildRejected,
    DatasetBuildResult,
    DatasetQualityReport,
    DatasetSourceIdentity,
    DatasetStreamQuality,
    MissingRange,
    Timeframe,
)

__all__ = (
    "CandleRecord",
    "DatasetBuilder",
    "DatasetBuildRejected",
    "DatasetBuildResult",
    "DatasetQualityReport",
    "DatasetSourceIdentity",
    "DatasetStreamQuality",
    "MissingRange",
    "Timeframe",
)
