<?php

declare(strict_types=1);

namespace App\Provider;

use App\Provider\Bitmart\BitmartContractProvider;
use App\Provider\Entity\Contract;
use App\Provider\Bitmart\Dto\ContractDto as BitmartContractDto;
use App\Provider\Repository\ContractRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
#[Route(path: '/api/provider/contracts', name: 'app_provider_contracts_')]
class ContractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly BitmartContractProvider $bitmartContractProvider
    ) {}

    #[Route(path: '/db', name: 'db', methods: ['GET'])]
    public function listFromDatabase(): JsonResponse
    {
        $contracts = $this->contractRepository->findActiveContracts();
        $normalized = array_map(fn (Contract $contract) => $this->normalizeDatabaseContract($contract), $contracts);

        return $this->buildResponse('database', $normalized);
    }

    #[Route(path: '/bitmart', name: 'bitmart', methods: ['GET'])]
    public function listFromBitmart(): JsonResponse
    {
        $contracts = $this->bitmartContractProvider->getContracts();
        $normalized = array_map(
            fn (BitmartContractDto|array $contract) => $this->normalizeBitmartContract($contract),
            $contracts
        );

        return $this->buildResponse('bitmart', $normalized);
    }
    #[Route(path: '/diff/bitmart', name: 'diff_bitmart', methods: ['GET'])]
    public function diffApi(): JsonResponse
    {
        $contracts = $this->contractRepository->findActiveContracts();
        $contractsSymbols = array_map(fn ($contract) => $contract->getSymbol(), $contracts);
        $contractsBySymbol = array_combine($contractsSymbols, $contracts);
        $contractBitmart = $this->bitmartContractProvider->getContracts();
        return new JsonResponse(array_filter($contractBitmart, function ($contract) use($contractsBySymbol) {
            return isset($contractsBySymbol[$contract->symbol]) && $contract->expireTimestamp?->getTimestamp() != $contractsBySymbol[$contract->symbol]->getExpireTimestamp();
        }));
    }

    /**
     * Ensure every response shares the same envelope.
     *
     * @param array<int,array<string,mixed>> $contracts
     */
    private function buildResponse(string $source, array $contracts): JsonResponse
    {
        return new JsonResponse([
            'source' => $source,
            'count' => count($contracts),
            'contracts' => $contracts,
        ]);
    }

    private function normalizeDatabaseContract(Contract $contract): array
    {
        return $this->formatContractPayload([
            'symbol' => $contract->getSymbol(),
            'name' => $contract->getName(),
            'product_type' => $contract->getProductType(),
            'open_timestamp' => $contract->getOpenTimestamp(),
            'expire_timestamp' => $contract->getExpireTimestamp(),
            'settle_timestamp' => $contract->getSettleTimestamp(),
            'base_currency' => $contract->getBaseCurrency(),
            'quote_currency' => $contract->getQuoteCurrency(),
            'last_price' => $contract->getLastPrice(),
            'volume_24h' => $contract->getVolume24h(),
            'turnover_24h' => $contract->getTurnover24h(),
            'index_price' => $contract->getIndexPrice(),
            'index_name' => $contract->getIndexName(),
            'contract_size' => $contract->getContractSize(),
            'min_leverage' => $contract->getMinLeverage(),
            'max_leverage' => $contract->getMaxLeverage(),
            'price_precision' => $contract->getPricePrecision(),
            'vol_precision' => $contract->getVolPrecision(),
            'max_volume' => $contract->getMaxVolume(),
            'min_volume' => $contract->getMinVolume(),
            'funding_rate' => $contract->getFundingRate(),
            'expected_funding_rate' => $contract->getExpectedFundingRate(),
            'open_interest' => $contract->getOpenInterest(),
            'open_interest_value' => $contract->getOpenInterestValue(),
            'high_24h' => $contract->getHigh24h(),
            'low_24h' => $contract->getLow24h(),
            'change_24h' => $contract->getChange24h(),
            'funding_time' => null,
            'market_max_volume' => $contract->getMarketMaxVolume(),
            'funding_interval_hours' => $contract->getFundingIntervalHours(),
            'status' => $contract->getStatus(),
            'delist_time' => $contract->getDelistTime(),
            'min_size' => $contract->getMinSize(),
            'max_size' => $contract->getMaxSize(),
            'tick_size' => $contract->getTickSize(),
            'multiplier' => $contract->getMultiplier(),
        ]);
    }

    private function normalizeBitmartContract(BitmartContractDto|array $contract): array
    {
        $payload = $contract instanceof BitmartContractDto ? $contract->toArray() : $contract;

        return $this->formatContractPayload([
            'symbol' => $payload['symbol'] ?? null,
            'name' => $payload['name'] ?? ($payload['symbol'] ?? null),
            'product_type' => $payload['product_type'] ?? null,
            'open_timestamp' => $payload['open_timestamp'] ?? null,
            'expire_timestamp' => $payload['expire_timestamp'] ?? null,
            'settle_timestamp' => $payload['settle_timestamp'] ?? null,
            'base_currency' => $payload['base_currency'] ?? null,
            'quote_currency' => $payload['quote_currency'] ?? null,
            'last_price' => $payload['last_price'] ?? null,
            'volume_24h' => $payload['volume_24h'] ?? null,
            'turnover_24h' => $payload['turnover_24h'] ?? null,
            'index_price' => $payload['index_price'] ?? null,
            'index_name' => $payload['index_name'] ?? null,
            'contract_size' => $payload['contract_size'] ?? null,
            'min_leverage' => $payload['min_leverage'] ?? null,
            'max_leverage' => $payload['max_leverage'] ?? null,
            'price_precision' => $payload['price_precision'] ?? null,
            'vol_precision' => $payload['vol_precision'] ?? null,
            'max_volume' => $payload['max_volume'] ?? null,
            'min_volume' => $payload['min_volume'] ?? null,
            'funding_rate' => $payload['funding_rate'] ?? null,
            'expected_funding_rate' => $payload['expected_funding_rate'] ?? null,
            'open_interest' => $payload['open_interest'] ?? null,
            'open_interest_value' => $payload['open_interest_value'] ?? null,
            'high_24h' => $payload['high_24h'] ?? null,
            'low_24h' => $payload['low_24h'] ?? null,
            'change_24h' => $payload['change_24h'] ?? null,
            'funding_time' => $payload['funding_time'] ?? null,
            'market_max_volume' => $payload['market_max_volume'] ?? null,
            'funding_interval_hours' => $payload['funding_interval_hours'] ?? null,
            'status' => $payload['status'] ?? null,
            'delist_time' => $payload['delist_time'] ?? null,
            'min_size' => $payload['min_size'] ?? null,
            'max_size' => $payload['max_size'] ?? null,
            'tick_size' => $payload['tick_size'] ?? null,
            'multiplier' => $payload['multiplier'] ?? null,
        ]);
    }

    /**
     * Normalise les clés et s'assure d'une structure identique pour chaque contrat retourné.
     *
     * @param array<string,mixed> $payload
     */
    private function formatContractPayload(array $payload): array
    {
        return [
            'symbol' => $payload['symbol'] ?? null,
            'name' => $payload['name'] ?? ($payload['symbol'] ?? null),
            'status' => $payload['status'] ?? null,
            'product_type' => $payload['product_type'] ?? null,
            'base_currency' => $payload['base_currency'] ?? null,
            'quote_currency' => $payload['quote_currency'] ?? null,
            'open_timestamp' => $payload['open_timestamp'] ?? null,
            'expire_timestamp' => $payload['expire_timestamp'] ?? null,
            'settle_timestamp' => $payload['settle_timestamp'] ?? null,
            'delist_time' => $payload['delist_time'] ?? null,
            'contract_size' => $payload['contract_size'] ?? null,
            'min_leverage' => $payload['min_leverage'] ?? null,
            'max_leverage' => $payload['max_leverage'] ?? null,
            'min_size' => $payload['min_size'] ?? null,
            'max_size' => $payload['max_size'] ?? null,
            'min_volume' => $payload['min_volume'] ?? null,
            'max_volume' => $payload['max_volume'] ?? null,
            'market_max_volume' => $payload['market_max_volume'] ?? null,
            'tick_size' => $payload['tick_size'] ?? null,
            'multiplier' => $payload['multiplier'] ?? null,
            'price_precision' => $payload['price_precision'] ?? null,
            'vol_precision' => $payload['vol_precision'] ?? null,
            'last_price' => $payload['last_price'] ?? null,
            'volume_24h' => $payload['volume_24h'] ?? null,
            'turnover_24h' => $payload['turnover_24h'] ?? null,
            'index_price' => $payload['index_price'] ?? null,
            'index_name' => $payload['index_name'] ?? null,
            'high_24h' => $payload['high_24h'] ?? null,
            'low_24h' => $payload['low_24h'] ?? null,
            'change_24h' => $payload['change_24h'] ?? null,
            'open_interest' => $payload['open_interest'] ?? null,
            'open_interest_value' => $payload['open_interest_value'] ?? null,
            'funding_rate' => $payload['funding_rate'] ?? null,
            'expected_funding_rate' => $payload['expected_funding_rate'] ?? null,
            'funding_time' => $payload['funding_time'] ?? null,
            'funding_interval_hours' => $payload['funding_interval_hours'] ?? null,
        ];
    }
}
