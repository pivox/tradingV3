<?php

declare(strict_types=1);

namespace App\Domain\Trading\Service;

use App\Config\TradingParameters;
use App\Domain\Ports\Out\TradingProviderPort;
use Psr\Log\LoggerInterface;

final class TradeContextService
{
    private ?float $cachedAccountBalance = null;
    private array $cachedMultipliers = [];

    public function __construct(
        private readonly TradingProviderPort $tradingProvider,
        private readonly TradingParameters $tradingParameters,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getRiskPercentage(): float
    {
        $riskRatio = max(0.0, $this->tradingParameters->riskPct());
        return max(0.01, round($riskRatio * 100, 4));
    }

    public function getAccountBalance(): float
    {
        if ($this->cachedAccountBalance !== null) {
            return $this->cachedAccountBalance;
        }

        try {
            $response = $this->tradingProvider->getAssetsDetail();
            $balance = $this->extractUsdtBalance($response);
            if ($balance !== null && $balance > 0.0) {
                return $this->cachedAccountBalance = $balance;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Trade Context] Unable to fetch account balance from provider', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->cachedAccountBalance = $this->fallbackAccountBalance();
    }

    public function getTimeframeMultiplier(?string $timeframe): float
    {
        if ($timeframe === null) {
            return 1.0;
        }

        if ($this->cachedMultipliers === []) {
            $this->cachedMultipliers = $this->loadTimeframeMultipliers();
        }

        $key = strtolower($timeframe);
        return $this->cachedMultipliers[$key] ?? 1.0;
    }

    private function extractUsdtBalance(array $payload): ?float
    {
        $data = $payload['data'] ?? $payload;
        if (!is_array($data)) {
            return null;
        }

        $assets = $data['assets'] ?? $data;
        if (!is_array($assets)) {
            return null;
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $coin = strtoupper((string)($asset['coin_code'] ?? $asset['currency'] ?? ''));
            if ($coin !== 'USDT') {
                continue;
            }

            $available = $asset['available_balance'] ?? $asset['available'] ?? $asset['cash_balance'] ?? null;
            if (is_numeric($available)) {
                return (float) $available;
            }
        }

        return null;
    }

    private function fallbackAccountBalance(): float
    {
        $envValue = getenv('TRADING_ACCOUNT_BALANCE');
        if (is_string($envValue) && is_numeric($envValue)) {
            $balance = (float) $envValue;
            if ($balance > 0.0) {
                return $balance;
            }
        }

        $entryConfig = $this->tradingParameters->getTradingConf('entry');
        $budget = is_array($entryConfig['budget'] ?? null) ? $entryConfig['budget'] : [];

        foreach (['fixed_usdt_if_available', 'max_usdt', 'default_usdt'] as $key) {
            if (isset($budget[$key]) && is_numeric($budget[$key])) {
                $value = (float) $budget[$key];
                if ($value > 0.0) {
                    return $value;
                }
            }
        }

        return 1000.0;
    }

    /**
     * @return array<string,float>
     */
    private function loadTimeframeMultipliers(): array
    {
        $multipliers = $this->tradingParameters->getTimeframeMultipliers();
        $normalized = [];
        foreach ($multipliers as $tf => $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $normalized[strtolower((string) $tf)] = (float) $value;
        }

        return $normalized;
    }
}
