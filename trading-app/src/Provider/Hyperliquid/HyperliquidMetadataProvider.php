<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\ContractDto;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Provider\Hyperliquid\Dto\HyperliquidInstrumentMetadataDto;

final class HyperliquidMetadataProvider implements ContractProviderInterface
{
    private HyperliquidPublicReadMapper $mapper;

    public function __construct(
        private readonly ?HyperliquidRestClientInterface $client = null,
        private readonly ?HyperliquidAssetResolver $assets = null,
    ) {
        $this->mapper = new HyperliquidPublicReadMapper();
    }

    /**
     * @return ContractDto[]
     */
    public function getContracts(): array
    {
        [$assets, $contexts] = $this->metaAndContexts(__METHOD__);
        $contracts = [];
        foreach ($assets as $assetId => $asset) {
            $contracts[] = $this->mapper->contract($asset, $assetId, $contexts[$assetId] ?? null);
        }

        usort($contracts, static fn (ContractDto $a, ContractDto $b): int => $a->symbol <=> $b->symbol);

        return $contracts;
    }

    public function getContractDetails(string $symbol): ?ContractDto
    {
        [$assetId, $asset, $context] = $this->assetContext($symbol, __METHOD__);

        if ($asset === null) {
            return null;
        }

        return $this->mapper->contract($asset, $assetId, $context);
    }

    public function getLastPrice(string $symbol): ?float
    {
        $coin = $this->resolver()->coin($symbol);
        $mids = $this->info(['type' => 'allMids'], __METHOD__);
        $last = $mids[$coin] ?? null;

        return \is_numeric($last) ? (float) $last : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        $coin = $this->resolver()->coin($symbol);
        $payload = $this->info(['type' => 'l2Book', 'coin' => $coin], __METHOD__);
        $levels = $payload['levels'] ?? [];
        $bids = \is_array($levels) && \is_array($levels[0] ?? null) ? $levels[0] : [];
        $asks = \is_array($levels) && \is_array($levels[1] ?? null) ? $levels[1] : [];

        return [
            'symbol' => strtoupper($symbol),
            'coin' => $coin,
            'timestamp' => $this->mapper->time($payload['time'] ?? null),
            'bids' => array_slice($this->mapper->orderBookLevels($bids), 0, max(1, min($limit, 50))),
            'asks' => array_slice($this->mapper->orderBookLevels($asks), 0, max(1, min($limit, 50))),
        ];
    }

    /**
     * @return array<int,mixed>
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return array<int,mixed>
     */
    public function getMarkPriceKline(
        string $symbol,
        int $step = 1,
        int $limit = 1,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        $coin = $this->resolver()->coin($symbol);
        $end = $endTime ?? time();
        $start = $startTime ?? ($end - (max(1, min($limit, 500)) * max(1, $step) * 60));

        return $this->info([
            'type' => 'candleSnapshot',
            'req' => [
                'coin' => $coin,
                'interval' => sprintf('%dm', max(1, $step)),
                'startTime' => $start * 1000,
                'endTime' => $end * 1000,
            ],
        ], __METHOD__);
    }

    /**
     * @return array<int,mixed>
     */
    public function getLeverageBrackets(string $symbol): array
    {
        [$assetId, $asset] = $this->assetContext($symbol, __METHOD__);
        if ($asset === null) {
            return [];
        }

        return [[
            'symbol' => strtoupper($symbol),
            'coin' => $this->mapper->coin($asset),
            'asset_id' => $assetId,
            'min_leverage' => '1',
            'max_leverage' => (string) ($asset['maxLeverage'] ?? '1'),
            'source' => 'hyperliquid_public_meta',
        ]];
    }

    /**
     * @param array<string>|null $symbols
     * @return array{upserted: int, total_fetched: int, errors: array<string>}
     */
    public function syncContracts(?array $symbols = null): array
    {
        $contracts = $this->getContracts();
        $requested = array_map(static fn (string $symbol): string => strtoupper($symbol), $symbols ?? []);
        $errors = ['hyperliquid_contract_sync_read_only_not_persisted'];
        $totalFetched = count($contracts);

        if ($requested !== []) {
            $available = array_fill_keys(array_map(static fn (ContractDto $contract): string => $contract->symbol, $contracts), true);
            foreach ($requested as $symbol) {
                if (!isset($available[$symbol])) {
                    $errors[] = sprintf('Contract not found for symbol %s', $symbol);
                }
            }
        }

        return [
            'upserted' => 0,
            'total_fetched' => $totalFetched,
            'errors' => $errors,
        ];
    }

    public function healthCheck(): bool
    {
        if (!$this->client instanceof HyperliquidRestClientInterface) {
            return false;
        }

        try {
            $this->info(['type' => 'meta'], __METHOD__);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'Hyperliquid';
    }

    private function notReady(string $operation): HyperliquidProviderNotReadyException
    {
        return new HyperliquidProviderNotReadyException('hyperliquid_metadata_not_ready', $operation);
    }

    public function getInstrumentMetadata(string $symbol): ?HyperliquidInstrumentMetadataDto
    {
        [$assetId, $asset, $context] = $this->assetContext($symbol, __METHOD__);
        if ($asset === null) {
            return null;
        }

        $funding = $this->latestFunding($this->mapper->coin($asset), __METHOD__);

        return $this->mapper->metadata($asset, $assetId, $context, $funding, []);
    }

    public function assetId(string $symbol): int
    {
        return $this->resolver()->assetId($symbol);
    }

    public function coin(string $symbol): string
    {
        return $this->resolver()->coin($symbol);
    }

    /**
     * @param array<string,mixed> $request
     * @return array<mixed>
     */
    private function info(array $request, string $operation): array
    {
        if (!$this->client instanceof HyperliquidRestClientInterface) {
            throw $this->notReady($operation);
        }

        try {
            return $this->client->info($request);
        } catch (\Throwable $exception) {
            throw new HyperliquidProviderUnavailableException($this->reason($exception), $operation, $exception);
        }
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>}
     */
    private function metaAndContexts(string $operation): array
    {
        $payload = $this->info(['type' => 'metaAndAssetCtxs'], $operation);
        $meta = $payload[0] ?? [];
        $assets = \is_array($meta) && \is_array($meta['universe'] ?? null) ? array_values($meta['universe']) : [];
        $contexts = \is_array($payload[1] ?? null) ? array_values($payload[1]) : [];
        $assets = array_values(array_filter($assets, static fn (mixed $row): bool => \is_array($row)));
        $this->assertUniqueAssets($assets, $operation);

        return [
            $assets,
            array_values(array_filter($contexts, static fn (mixed $row): bool => \is_array($row))),
        ];
    }

    /**
     * @param list<array<string,mixed>> $assets
     */
    private function assertUniqueAssets(array $assets, string $operation): void
    {
        $seen = [];
        foreach ($assets as $asset) {
            $coin = $this->mapper->coin($asset);
            if ($coin === '') {
                continue;
            }
            if (isset($seen[$coin])) {
                throw new HyperliquidProviderUnavailableException('hyperliquid_asset_collision', $operation);
            }

            $seen[$coin] = true;
        }
    }

    /**
     * @return array{0: int, 1: array<string,mixed>|null, 2: array<string,mixed>|null}
     */
    private function assetContext(string $symbol, string $operation): array
    {
        $target = $this->resolver()->coin($symbol);
        [$assets, $contexts] = $this->metaAndContexts($operation);
        foreach ($assets as $assetId => $asset) {
            if ($this->mapper->coin($asset) === $target) {
                return [$assetId, $asset, $contexts[$assetId] ?? null];
            }
        }

        return [-1, null, null];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestFunding(string $coin, string $operation): ?array
    {
        $rows = $this->info([
            'type' => 'fundingHistory',
            'coin' => $coin,
            'startTime' => (time() - 86400) * 1000,
            'endTime' => time() * 1000,
        ], $operation);
        $fundingRows = array_values(array_filter($rows, static fn (mixed $row): bool => \is_array($row)));

        return $fundingRows === [] ? null : $fundingRows[array_key_last($fundingRows)];
    }

    private function resolver(): HyperliquidAssetResolver
    {
        return $this->assets ?? new HyperliquidAssetResolver($this->client ?? throw $this->notReady(__METHOD__));
    }

    private function reason(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, '429')) {
            return 'hyperliquid_public_rate_limited';
        }

        return 'hyperliquid_public_api_error';
    }
}
