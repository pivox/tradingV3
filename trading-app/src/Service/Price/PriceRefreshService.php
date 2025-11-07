<?php

declare(strict_types=1);

namespace App\Service\Price;

use App\Provider\Repository\ContractRepository;
use Psr\Log\LoggerInterface;

final class PriceRefreshService
{
    public function __construct(
        private readonly PriceProviderService $priceProvider,
        private readonly ContractRepository $contractRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rafraîchit le prix d'un symbole et met à jour la base de données
     */
    public function refreshPrice(string $symbol): bool
    {
        try {
            $price = $this->priceProvider->getPrice($symbol);
            if ($price === null) {
                $this->logger->warning('[PriceRefresh] Unable to get price', [
                    'symbol' => $symbol
                ]);
                return false;
            }

            // Mettre à jour le prix dans la base de données
            $contract = $this->contractRepository->findBySymbol($symbol);
            if ($contract) {
                $contract->setLastPrice((string) $price);
                $this->contractRepository->getEntityManager()->flush();

                $this->logger->info('[PriceRefresh] Price updated', [
                    'symbol' => $symbol,
                    'price' => $price
                ]);

                return true;
            }

            $this->logger->warning('[PriceRefresh] Contract not found', [
                'symbol' => $symbol
            ]);

            return false;

        } catch (\Throwable $e) {
            $this->logger->error('[PriceRefresh] Error refreshing price', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Rafraîchit les prix de plusieurs symboles
     */
    public function refreshMultiplePrices(array $symbols): array
    {
        $results = [];

        foreach ($symbols as $symbol) {
            $results[$symbol] = $this->refreshPrice($symbol);
        }

        return $results;
    }
}
