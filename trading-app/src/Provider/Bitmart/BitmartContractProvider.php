<?php

declare(strict_types=1);

namespace App\Provider\Bitmart;

use App\Contract\Provider\Dto\ContractDto;
use App\Contract\Provider\ContractProviderInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
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
        private readonly LoggerInterface $logger
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
}
