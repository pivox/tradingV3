<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Leverage;

use App\Service\Bitmart\Private\PositionsService as BitmartPositionsService;
use App\Service\Trading\Opening\Config\TradingConfig;
use App\Service\Trading\Opening\Market\MarketSnapshot;
use App\Service\Exception\Trade\Position\LeverageLowException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class LeverageService
{
    public function __construct(
        private readonly BitmartPositionsService $positionsService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function plan(string $symbol, TradingConfig $config, MarketSnapshot $snapshot): LeveragePlan
    {
        $stopPct = $snapshot->stopPct > 0 ? $snapshot->stopPct : 1e-9;
        $notionalRisk = ($config->budgetCapUsdt * $config->riskPct) / max(1e-9, $stopPct);
        $levFloor = (int) ceil($notionalRisk / max(1e-9, $config->budgetCapUsdt));
        $levFromSizing = max(1, $levFloor);

        $target = (int) max(1, floor($snapshot->maxLeverage * 0.2));
        if ($target <= 0) {
            $target = 1;
        }

        if ($levFromSizing < $target) {
            throw LeverageLowException::trigger($symbol, $snapshot->maxLeverage, $levFromSizing);
        }

        $current = $this->getCurrentLeverageSafe($symbol);

        return new LeveragePlan(target: $target, current: $current, sizingFloor: $levFromSizing);
    }

    public function apply(string $symbol, LeveragePlan $plan, string $openType): void
    {
        $this->logger->info('[Opening] submit leverage', [
            'symbol' => $symbol,
            'target' => $plan->target,
            'open_type' => $openType,
        ]);

        try {
            $resp = $this->positionsService->setLeverage($symbol, $plan->target, $openType);
        } catch (Throwable $e) {
            $this->logger->warning('[Opening] submit-leverage transport exception', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $code = (int) ($resp['code'] ?? 0);
        $this->logger->info('[Opening] submit-leverage response', ['code' => $code, 'resp' => $resp]);

        if ($code === 1000) {
            return;
        }

        if ($code === 40012) {
            $this->logger->warning('[Opening] submit-leverage refused (40012), attempting fallback', [
                'symbol' => $symbol,
            ]);
            $existingType = $this->detectExistingOpenType($symbol);
            if ($existingType !== null && $existingType !== $openType) {
                try {
                    $resp2 = $this->positionsService->setLeverage($symbol, $plan->target, (string) $existingType);
                    $code2 = (int) ($resp2['code'] ?? 0);
                    $this->logger->info('[Opening] submit-leverage fallback response', ['code' => $code2, 'resp' => $resp2]);
                    if ($code2 === 1000) {
                        return;
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('[Opening] submit-leverage fallback exception', [
                        'symbol' => $symbol,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->warning('[Opening] continue with existing leverage/mode', ['symbol' => $symbol]);
            return;
        }

        throw new RuntimeException('submit-leverage error: ' . json_encode($resp, JSON_UNESCAPED_SLASHES));
    }

    private function getCurrentLeverageSafe(string $symbol): int
    {
        try {
            $resp = $this->positionsService->list(['symbol' => $symbol]);
            $data = $resp['data'] ?? [];
            $leverage = null;
            if (is_array($data)) {
                if (isset($data['leverage'])) {
                    $leverage = (int) $data['leverage'];
                } elseif (isset($data[0]['leverage'])) {
                    $leverage = (int) $data[0]['leverage'];
                }
            }
            $lev = $leverage ?? 0;
            return $lev > 0 ? $lev : 1;
        } catch (Throwable $e) {
            $this->logger->warning('[Opening] getCurrentLeverageSafe failed, default=1', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return 1;
        }
    }

    private function detectExistingOpenType(string $symbol): ?string
    {
        try {
            $resp = $this->positionsService->list(['symbol' => $symbol]);
            $rows = $resp['data'] ?? [];
            if (!is_array($rows)) {
                return null;
            }
            if (isset($rows['open_type'])) {
                return (string) $rows['open_type'];
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sym = strtoupper((string)($row['symbol'] ?? ''));
                if ($sym !== strtoupper($symbol)) {
                    continue;
                }
                return (string)($row['open_type'] ?? $row['margin_mode'] ?? $row['position_mode'] ?? null);
            }
        } catch (Throwable $e) {
            $this->logger->warning('[Opening] detectExistingOpenType failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
