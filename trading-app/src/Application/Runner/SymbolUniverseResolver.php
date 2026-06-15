<?php

declare(strict_types=1);

namespace App\Application\Runner;

use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Context\ExchangeContext;
use App\Provider\Repository\ContractRepository;
use Psr\Log\LoggerInterface;

final class SymbolUniverseResolver
{
    private const FALLBACK_SYMBOLS = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];

    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $mtfLogger,
    ) {
    }

    /**
     * @param array<string> $inputSymbols Liste des symboles fournis en entrée
     * @return array<string> Liste des symboles à traiter
     */
    public function resolve(array $inputSymbols, ?string $profile = null, ?ExchangeContext $context = null): array
    {
        $symbols = [];

        foreach ($inputSymbols as $symbol) {
            if (is_string($symbol) && $symbol !== '') {
                $symbols[] = strtoupper(trim($symbol));
            }
        }

        $symbols = array_values(array_unique(array_filter($symbols)));

        if (empty($symbols)) {
            try {
                $fetched = $this->contractRepository->allActiveSymbolNames([], false, $profile, $context);
                if (!empty($fetched)) {
                    $symbols = array_values(array_unique(array_map('strval', $fetched)));
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MTF Runner] Failed to load active symbols, using fallback', [
                    'error' => $e->getMessage(),
                    'profile' => $profile,
                ]);
                $symbols = self::FALLBACK_SYMBOLS;
            }
        }

        $queuedSymbols = $this->consumeSymbolsFromSwitchQueue();
        if (!empty($queuedSymbols)) {
            $symbols = array_values(array_unique(array_merge($symbols, $queuedSymbols)));
            $this->mtfLogger->info('[MTF Runner] Added symbols from switch queue', [
                'count' => count($queuedSymbols),
            ]);
        }

        if (empty($symbols)) {
            $symbols = self::FALLBACK_SYMBOLS;
        }

        return $symbols;
    }

    /**
     * @return array<string>
     */
    private function consumeSymbolsFromSwitchQueue(): array
    {
        try {
            return $this->mtfSwitchRepository->consumeSymbolsWithFutureExpiration();
        } catch (\Throwable $e) {
            $this->logger->warning('[MTF Runner] Failed to consume symbols from switch queue', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
