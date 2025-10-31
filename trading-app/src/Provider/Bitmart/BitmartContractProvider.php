<?php

declare(strict_types=1);

namespace App\Provider\Bitmart;

use App\Contract\Provider\Dto\ContractDto;
use App\Contract\Provider\ContractProviderInterface;
use App\Provider\Bitmart\Dto\ContractDto as BitmartContractDto;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\Repository\ContractRepository;
use Psr\Log\LoggerInterface;

/**
 * Provider Bitmart pour les contrats
 */
#[\Symfony\Component\DependencyInjection\Attribute\Autoconfigure(
    bind: [
        ContractProviderInterface::class => '@app.provider.bitmart.contract'
    ]
)]
final class BitmartContractProvider implements ContractProviderInterface
{
    public function __construct(
        private readonly BitmartHttpClientPublic $bitmartClient,
        private readonly LoggerInterface $logger,
        private readonly ContractRepository $contractRepository
    ) {}

    public function getContracts(): array
    {
        try {
            $contractDetails = $this->bitmartClient->getContractDetails();
            return $contractDetails->toArray();
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des contrats", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getContractDetails(string $symbol): ?ContractDto
    {
        try {
            // Préférence: API typée qui retourne une liste, puis extraction
            try {
                $list = $this->bitmartClient->getContractDetails($symbol); // ListContractDto
                $items = \method_exists($list, 'toArray') ? $list->toArray() : [];
                if (\is_array($items) && !empty($items)) {
                    $first = $items[0];
                    if (\is_array($first)) {
                        return ContractDto::fromArray($first);
                    }
                }
            } catch (\Throwable $e) {
                // continue vers fallback
            }

            // Fallback: méthode utilitaire (peut retourner array ou ContractDto selon versions)
            $details = $this->bitmartClient->fetchContractDetails($symbol);
            if ($details instanceof ContractDto) {
                return $details;
            }
            if (\is_array($details) && !empty($details)) {
                return ContractDto::fromArray($details);
            }
            return null;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des détails du contrat", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getLastPrice(string $symbol): ?float
    {
        try {
            return $this->bitmartClient->getLastPrice($symbol);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération du dernier prix", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        try {
            return $this->bitmartClient->getOrderBook($symbol, $limit);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération du carnet d'ordres", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        try {
            return $this->bitmartClient->getMarketTrade($symbol, $limit);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des trades récents", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getMarkPriceKline(string $symbol, int $step = 1, int $limit = 1, ?int $startTime = null, ?int $endTime = null): array
    {
        try {
            return $this->bitmartClient->getMarkPriceKline($symbol, $step, $limit, $startTime, $endTime);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des mark price klines", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getLeverageBrackets(string $symbol): array
    {
        try {
            return $this->bitmartClient->getLeverageBrackets($symbol);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des brackets de levier", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function healthCheck(): bool
    {
        try {
            return $this->bitmartClient->healthCheck();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'Bitmart';
    }

    public function syncContracts(?array $symbols = null): array
    {
        $errors = [];
        $totalFetched = 0;
        $upserted = 0;

        try {
            // 1. Récupérer tous les contrats depuis l'API
            $contractPayload = $this->getContracts();

            if ($contractPayload instanceof \Traversable) {
                $contractPayload = iterator_to_array($contractPayload);
            }

            if (!is_array($contractPayload)) {
                $contractPayload = [];
            }

            // 2. Indexer les contrats par symbole
            $indexedContracts = [];
            foreach ($contractPayload as $contract) {
                $symbol = $this->extractContractSymbol($contract);
                if ($symbol === null) {
                    continue;
                }
                $indexedContracts[strtoupper($symbol)] = $contract;
            }

            $totalFetched = count($indexedContracts);

            // 3. Filtrer par symboles si demandé
            $fetchedContracts = [];
            if (!empty($symbols)) {
                foreach ($symbols as $s) {
                    $key = strtoupper($s);
                    if (!isset($indexedContracts[$key])) {
                        $errors[] = sprintf('Contract not found for symbol %s', $s);
                        continue;
                    }
                    $fetchedContracts[] = $this->prepareContractPayload($indexedContracts[$key]);
                }
            } else {
                foreach ($indexedContracts as $contract) {
                    $fetchedContracts[] = $this->prepareContractPayload($contract);
                }
            }

            // 4. Upsert en base
            if (!empty($fetchedContracts)) {
                $upserted = $this->contractRepository->upsertContracts($fetchedContracts);
                $this->logger->info('[BitmartContractProvider] Contracts synchronized', [
                    'upserted' => $upserted,
                    'total_fetched' => $totalFetched,
                    'symbols_requested' => $symbols,
                ]);
            } else {
                $this->logger->warning('[BitmartContractProvider] No contracts fetched from provider');
            }
        } catch (\Throwable $e) {
            $errors[] = sprintf('Synchronization failed: %s', $e->getMessage());
            $this->logger->error('[BitmartContractProvider] Contract synchronization failed', [
                'error' => $e->getMessage(),
                'symbols' => $symbols,
            ]);
        }

        return [
            'upserted' => $upserted,
            'total_fetched' => $totalFetched,
            'errors' => $errors,
        ];
    }

    private function extractContractSymbol(mixed $contract): ?string
    {
        if (is_array($contract)) {
            return isset($contract['symbol']) ? (string) $contract['symbol'] : null;
        }

        if ($contract instanceof BitmartContractDto) {
            return $contract->symbol ?? null;
        }

        if ($contract instanceof ContractDto) {
            return $contract->symbol ?? null;
        }

        return null;
    }

    private function prepareContractPayload(mixed $contract): array|BitmartContractDto
    {
        if ($contract instanceof BitmartContractDto) {
            return $contract;
        }

        if (is_array($contract)) {
            return $contract;
        }

        throw new \InvalidArgumentException('Unsupported contract payload type.');
    }
}
