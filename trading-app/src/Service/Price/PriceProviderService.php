<?php

declare(strict_types=1);

namespace App\Service\Price;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;

final class PriceProviderService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly BitmartHttpClientPublic $bitmartRestClient,
    ) {
    }

    /**
     * Récupère le prix d'un symbole depuis Bitmart Contract Details
     */
    public function getPrice(string $symbol): ?float
    {
        try {
            $contractDetails = $this->bitmartRestClient->fetchContractDetails($symbol);
            if (\is_array($contractDetails) && isset($contractDetails['last_price'])) {
                $price = (float) $contractDetails['last_price'];

                $this->logger->info('[PriceProvider] Price retrieved from Bitmart Contract Details', [
                    'symbol' => $symbol,
                    'price' => $price,
                    'source' => 'bitmart_contract_details'
                ]);

                return $price;
            }

            $this->logger->warning('[PriceProvider] No last_price found in contract details', [
                'symbol' => $symbol,
                'contract_data' => $contractDetails
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('[PriceProvider] Failed to get price from Bitmart Contract Details', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Récupère les prix depuis toutes les sources pour comparaison
     */
    public function getAllPrices(string $symbol): array
    {
        $prices = [];

        try {
            $prices['bitmart_contract'] = $this->getPrice($symbol);
        } catch (\Throwable $e) {
            $prices['bitmart_contract'] = null;
        }

        return $prices;
    }
}
