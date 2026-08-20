# Issue #308 executable micro-scalping contracts

## Scope

Publish `micro_scalping@1.1.0` and the long/short `momentum_ofi@1.1.0` setup contracts as executable Shadow contracts. The blocked `1.0.0` documents remain immutable. Runtime overlays are limited to OKX and Hyperliquid public-data Shadow/Paper/backtest use; private mainnet execution remains prohibited.

## Frozen decisions

The exact authority is the approved #308 decision dated 2026-08-20. The mode uses 5m regime/context and 1m trigger/execution/confirmation, evaluates every minute, and expires after five seconds. It risks 0.4 percentage points of equity per trade, caps daily loss at the lower of 2.5% and 50 USDT, includes pending entries in a concurrency cap of three, caps mode notional at 20% of equity, and caps leverage at 2x.

Each setup uses one symmetric VWAP/ATR 1m EntryZone, an ATR 1m stop, one 1.8R target, a 1.3R net floor, explicit cost sources, and limit-only execution. Long MACD is strictly above zero and short MACD strictly below zero. Authenticated order-book and public-trade proofs must be no older than five seconds and are evaluated through condition catalog 1.2.0.

## Failure policy

Missing, stale, cross-market, unverified, or mismatched microstructure evidence rejects. No legacy scalar input, profile alias, market fallback, fake-provider inference, or private mainnet write path is introduced.

## Certification boundary

Shadow status does not imply promotion. At least 50 certified trades are required for each executable network × venue × mode × setup × side cell, and undersampled cells cannot be aggregated.
