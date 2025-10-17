<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Market;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Indicator\AtrCalculator;
use App\Service\Trading\Opening\Config\TradingConfig;
use App\Service\Trading\Opening\DTO\OpenMarketRequest;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class MarketSnapshotFactory
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
        private readonly AtrCalculator $atrCalculator,
        private readonly BitmartHttpClientPublic $bitmartPublic,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(OpenMarketRequest $request, TradingConfig $config): MarketSnapshot
    {
        $symbol = strtoupper($request->symbol);

        $contract = $this->contractRepository->find($symbol);
        if ($contract === null) {
            throw new RuntimeException(sprintf('Contract %s not found in database', $symbol));
        }

        $contractRaw = [
            'symbol' => $contract->getSymbol(),
            'status' => $contract->getStatus(),
            'price_precision' => $contract->getPricePrecision(),
            'vol_precision' => $contract->getVolPrecision(),
            'contract_size' => $contract->getContractSize(),
            'min_volume' => $contract->getMinVolume(),
            'max_volume' => $contract->getMaxVolume(),
            'market_max_volume' => $contract->getMarketMaxVolume(),
            'max_leverage' => $contract->getMaxLeverage(),
            'min_leverage' => $contract->getMinLeverage(),
            'last_price' => $contract->getLastPrice(),
            'index_price' => $contract->getIndexPrice(),
        ];

        $status = (string)($contractRaw['status'] ?? 'Trading');
        if ($status !== 'Trading') {
            throw new RuntimeException(sprintf("Symbol status is '%s' (not Trading)", $status));
        }

        $tickSize = (float)($contractRaw['price_precision'] ?? 0.0);
        $qtyStep = (float)($contractRaw['vol_precision'] ?? 0.0);
        $contractSize = (float)($contractRaw['contract_size'] ?? 0.0);
        $minVolume = (int)($contractRaw['min_volume'] ?? 0);
        $maxVolume = (int)($contractRaw['max_volume'] ?? PHP_INT_MAX);
        $marketCap = $contractRaw['market_max_volume'] ?? null;
        $maxLeverage = (int)($contractRaw['max_leverage'] ?? 50);

        if ($tickSize <= 0.0 || $qtyStep <= 0.0 || $contractSize <= 0.0 || $minVolume <= 0) {
            throw new RuntimeException(sprintf(
                'Invalid contract details: tick=%f qtyStep=%f contractSize=%f minVolume=%d',
                $tickSize,
                $qtyStep,
                $contractSize,
                $minVolume
            ));
        }

        $ohlc = $request->ohlc;
        if ($ohlc === []) {
            $needed = max($config->atrLookback + 1, 120);
            $ohlc = $this->klineRepository->findLastKlines(
                symbol: $symbol,
                timeframe: $config->atrTimeframe,
                limit: $needed
            );
            $this->logger->info('[Opening] ATR OHLC auto-loaded', [
                'symbol' => $symbol,
                'timeframe' => $config->atrTimeframe,
                'requested' => $needed,
                'actual' => count($ohlc),
            ]);
        }

        if (count($ohlc) <= $config->atrLookback) {
            throw new InvalidArgumentException(sprintf(
                'OHLC insuffisant pour ATR (tf=%s, lookback=%d)',
                $config->atrTimeframe,
                $config->atrLookback
            ));
        }

        $markPrice = $this->fetchMarkPrice($symbol, $contractRaw);
        $atr = $this->atrCalculator->compute($ohlc, $config->atrLookback, $config->atrMethod);
        $stopDistance = $config->atrKStop * $atr;
        $stopPct = $stopDistance / max(1e-9, $markPrice);

        return new MarketSnapshot(
            symbol: $symbol,
            markPrice: $markPrice,
            atr: $atr,
            stopDistance: $stopDistance,
            stopPct: $stopPct,
            tickSize: $tickSize,
            qtyStep: $qtyStep,
            contractSize: $contractSize,
            minVolume: $minVolume,
            maxVolume: $maxVolume,
            marketMaxVolume: is_numeric($marketCap) ? (int)$marketCap : null,
            maxLeverage: $maxLeverage,
            ohlc: $ohlc,
            contractRaw: $contractRaw,
        );
    }

    private function fetchMarkPrice(string $symbol, array $contractRaw): float
    {
        try {
            $now = time();
            $rows = $this->bitmartPublic->getMarkPriceKline(
                symbol: $symbol,
                step: 1,
                limit: 2,
                startTime: $now - 120,
                endTime: $now
            );
            if (!is_array($rows) || $rows === []) {
                throw new RuntimeException('markprice-kline empty');
            }
            $lastRow = end($rows);
            $close = (float)($lastRow['close_price'] ?? 0.0);
            if ($close <= 0.0) {
                throw new RuntimeException('markprice-kline close invalid');
            }
            return $close;
        } catch (Throwable $e) {
            $this->logger->warning('[Opening] Mark price fetch failed, fallback index', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            $index = (float)($contractRaw['index_price'] ?? 0.0);
            if ($index > 0.0) {
                return $index;
            }

            throw new RuntimeException(sprintf(
                "Impossible d'obtenir un mark price frais pour %s: %s",
                $symbol,
                $e->getMessage()
            ));
        }
    }
}
