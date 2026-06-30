<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;

final class HyperliquidAccountGateway implements AccountProviderInterface
{
    private const DEFAULT_FUNDING_LOOKBACK_SECONDS = 30 * 24 * 60 * 60;

    private HyperliquidPrivateReadMapper $mapper;

    public function __construct(
        private readonly ?HyperliquidRestClientInterface $client = null,
        private readonly ?HyperliquidAssetResolver $assets = null,
        private readonly ?HyperliquidConfig $config = null,
    ) {
        $this->mapper = new HyperliquidPrivateReadMapper();
    }

    public function getAccountInfo(): ?AccountDto
    {
        return $this->mapper->account($this->userState(__METHOD__));
    }

    public function getAccountBalance(string $basicCurrency = 'USDT'): float
    {
        if (strtoupper($basicCurrency) !== 'USDC') {
            return 0.0;
        }

        $account = $this->getAccountInfo();

        return $account instanceof AccountDto ? $account->availableBalance->toFloat() : 0.0;
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        try {
            return $this->fetchOpenPositions($symbol, __METHOD__);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositionsOrFail(?string $symbol = null): array
    {
        return $this->fetchOpenPositions($symbol, __METHOD__);
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        foreach ($this->getOpenPositionsOrFail($symbol) as $position) {
            return $position;
        }

        return null;
    }

    /**
     * @return array<int,mixed>
     */
    public function getTradeHistory(string $symbol, int $limit = 100): array
    {
        return $this->getTrades($symbol, $limit);
    }

    /**
     * @return array<int,mixed>
     */
    public function getTrades(
        ?string $symbol = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        $request = [
            'type' => $startTime !== null || $endTime !== null ? 'userFillsByTime' : 'userFills',
            'user' => $this->accountAddress(__METHOD__),
            'limit' => $this->limit($limit),
        ];
        if ($startTime !== null) {
            $request['startTime'] = $startTime * 1000;
        }
        if ($endTime !== null) {
            $request['endTime'] = $endTime * 1000;
        }

        $target = $symbol !== null ? $this->coin($symbol) : null;
        $fills = [];
        foreach ($this->infoRows($request, __METHOD__) as $row) {
            $coin = strtoupper((string) ($row['coin'] ?? ''));
            if ($target !== null && $coin !== $target) {
                continue;
            }

            $fills[] = $this->mapper->legacyTrade($row);
        }

        return array_slice($fills, 0, $this->limit($limit));
    }

    /**
     * @return array<int,mixed>
     */
    public function getTransactionHistory(
        ?string $symbol = null,
        ?int $flowType = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        if ($flowType !== null && $flowType !== 3) {
            return [];
        }

        $request = [
            'type' => 'userFunding',
            'user' => $this->accountAddress(__METHOD__),
            'limit' => $this->limit($limit),
            'startTime' => ($startTime ?? (time() - self::DEFAULT_FUNDING_LOOKBACK_SECONDS)) * 1000,
        ];
        if ($endTime !== null) {
            $request['endTime'] = $endTime * 1000;
        }

        $target = $symbol !== null ? $this->coin($symbol) : null;
        $transactions = [];
        foreach ($this->infoRows($request, __METHOD__) as $row) {
            $row = $this->fundingRow($row);
            $coin = strtoupper((string) ($row['coin'] ?? ''));
            if ($target !== null && $coin !== $target) {
                continue;
            }

            $transactions[] = $this->mapper->legacyFunding($row, $flowType);
        }

        return array_slice($transactions, 0, $this->limit($limit));
    }

    /**
     * @return array<string,mixed>
     */
    public function getTradingFees(string $symbol): array
    {
        throw $this->notReady(__METHOD__);
    }

    public function healthCheck(): bool
    {
        try {
            return $this->getAccountInfo() instanceof AccountDto;
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
        return new HyperliquidProviderNotReadyException('hyperliquid_account_not_ready', $operation);
    }

    /**
     * @return PositionDto[]
     */
    private function fetchOpenPositions(?string $symbol, string $operation): array
    {
        $target = $symbol !== null ? $this->coin($symbol) : null;
        $positions = [];
        foreach ($this->positions($this->userState($operation)) as $row) {
            $position = \is_array($row['position'] ?? null) ? $row['position'] : $row;
            if (!\is_array($position)) {
                continue;
            }

            $coin = strtoupper((string) ($position['coin'] ?? ''));
            if ($target !== null && $coin !== $target) {
                continue;
            }

            $mapped = $this->mapper->position($row);
            if ($mapped instanceof PositionDto) {
                $positions[] = $mapped;
            }
        }

        return $positions;
    }

    /**
     * @return array<string,mixed>
     */
    private function userState(string $operation): array
    {
        $payload = $this->info([
            'type' => 'clearinghouseState',
            'user' => $this->accountAddress($operation),
        ], $operation);

        return $this->assoc($payload);
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
     * @param array<string,mixed> $request
     * @return list<array<string,mixed>>
     */
    private function infoRows(array $request, string $operation): array
    {
        return array_values(array_filter($this->info($request, $operation), \is_array(...)));
    }

    private function accountAddress(string $operation): string
    {
        $config = $this->config ?? HyperliquidConfig::fromEnv();
        if ($config->configuredEnvironment() !== 'testnet') {
            throw new HyperliquidProviderNotReadyException('hyperliquid_account_environment_not_testnet', $operation);
        }
        if ($config->normalizedNetwork() !== 'testnet') {
            throw new HyperliquidProviderNotReadyException('hyperliquid_account_network_not_testnet', $operation);
        }

        $accountAddress = $config->signingAccountAddress();
        $signerAddress = $config->signerAddress();
        if ($accountAddress === '') {
            $reason = $signerAddress !== ''
                ? 'hyperliquid_account_address_missing_for_signer'
                : 'hyperliquid_account_address_missing';

            throw new HyperliquidProviderNotReadyException($reason, $operation);
        }
        if ($signerAddress !== '' && $accountAddress === $signerAddress) {
            throw new HyperliquidProviderNotReadyException('hyperliquid_account_address_matches_agent', $operation);
        }

        return $accountAddress;
    }

    /**
     * @param array<mixed> $payload
     * @return array<string,mixed>
     */
    private function assoc(array $payload): array
    {
        return array_is_list($payload) ? [] : $payload;
    }

    /**
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    private function positions(array $state): array
    {
        $positions = $state['assetPositions'] ?? [];
        if (!\is_array($positions)) {
            return [];
        }

        return array_values(array_filter($positions, \is_array(...)));
    }

    private function coin(string $symbol): string
    {
        return $this->assets instanceof HyperliquidAssetResolver
            ? $this->assets->coin($symbol)
            : $this->fallbackCoin($symbol);
    }

    private function fallbackCoin(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        foreach (['-PERP', 'PERP', '/USDC', '-USDC', 'USDC', '/USDT', '-USDT', 'USDT'] as $suffix) {
            if (str_ends_with($symbol, $suffix)) {
                return substr($symbol, 0, -strlen($suffix));
            }
        }

        return $symbol;
    }

    private function limit(int $limit): int
    {
        return max(1, min($limit, 200));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function fundingRow(array $row): array
    {
        $delta = \is_array($row['delta'] ?? null) ? $row['delta'] : [];

        return $delta + $row;
    }

    private function reason(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, '429') || str_contains($message, 'rate')) {
            return 'hyperliquid_private_rate_limited';
        }
        if (str_contains($message, 'network')) {
            return 'hyperliquid_private_network_error';
        }

        return 'hyperliquid_private_api_error';
    }
}
