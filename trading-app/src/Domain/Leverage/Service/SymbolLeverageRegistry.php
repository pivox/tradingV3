<?php

declare(strict_types=1);

namespace App\Domain\Leverage\Service;

use App\Repository\ContractRepository;
use Psr\Log\LoggerInterface;

final class SymbolLeverageRegistry
{
    /** @var array<string,float> */
    private array $runtimeCaps = [];

    public function __construct(
        private readonly LeverageConfigService $configService,
        private readonly ContractRepository $contractRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(string $symbol): float
    {
        $symbolKey = strtoupper($symbol);
        $configuredCap = $this->configService->getConfig()->getSymbolCap($symbolKey);
        $runtimeCap = $this->runtimeCaps[$symbolKey] ?? null;

        $effectiveCap = $runtimeCap !== null
            ? min($configuredCap, $runtimeCap)
            : $configuredCap;

        $contract = $this->contractRepository->findBySymbol($symbolKey);
        if ($contract !== null && method_exists($contract, 'getMaxLeverage')) {
            $contractCap = $contract->getMaxLeverage();
            if ($contractCap !== null && $contractCap > 0) {
                $effectiveCap = min($effectiveCap, (float) $contractCap);
            }
        }

        return max(1.0, $effectiveCap);
    }

    public function remember(string $symbol, float $cap): void
    {
        if ($cap <= 0) {
            return;
        }

        $symbolKey = strtoupper($symbol);
        $existing = $this->runtimeCaps[$symbolKey] ?? null;
        if ($existing !== null && $existing <= $cap) {
            return;
        }

        $this->runtimeCaps[$symbolKey] = $cap;
        try {
            $this->logger->info('[Leverage Registry] Updated runtime cap', [
                'symbol' => $symbolKey,
                'cap' => $cap,
            ]);
        } catch (\Throwable) {
            // best-effort logging
        }
    }
}
