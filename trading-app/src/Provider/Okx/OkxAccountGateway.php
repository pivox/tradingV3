<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;

final class OkxAccountGateway implements AccountProviderInterface
{
    private OkxPrivateReadMapper $mapper;
    private OkxPositionGateway $positions;

    public function __construct(
        private readonly ?OkxRestClientInterface $client = null,
        private readonly ?OkxInstrumentResolver $instruments = null,
        ?OkxPositionGateway $positions = null,
    ) {
        $this->mapper = new OkxPrivateReadMapper($this->resolver());
        $this->positions = $positions ?? new OkxPositionGateway($client, $instruments);
    }

    public function getAccountInfo(): ?AccountDto
    {
        return $this->mapper->account(
            $this->firstRow($this->privateGet('/api/v5/account/balance', [], __METHOD__), __METHOD__),
            'USDT',
        );
    }

    public function getAccountBalance(string $basicCurrency = 'USDT'): float
    {
        $account = $this->mapper->account(
            $this->firstRow($this->privateGet('/api/v5/account/balance', ['ccy' => strtoupper($basicCurrency)], __METHOD__), __METHOD__),
            $basicCurrency,
        );

        return $account instanceof AccountDto ? $account->availableBalance->toFloat() : 0.0;
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->positions->getOpenPositions($symbol);
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositionsOrFail(?string $symbol = null): array
    {
        return $this->positions->getOpenPositionsOrFail($symbol);
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        return $this->positions->getPosition($symbol);
    }

    /**
     * @return array<int, mixed>
     */
    public function getTradeHistory(string $symbol, int $limit = 100): array
    {
        return $this->getTrades($symbol, $limit);
    }

    /**
     * @return array<int, mixed>
     */
    public function getTrades(
        ?string $symbol = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        $query = [
            'instType' => 'SWAP',
            'limit' => max(1, min($limit, 100)),
        ];
        if ($symbol !== null) {
            $query['instId'] = $this->resolver()->instId($symbol);
        }
        if ($startTime !== null) {
            $query['begin'] = (string) ($startTime * 1000);
        }
        if ($endTime !== null) {
            $query['end'] = (string) ($endTime * 1000);
        }

        $path = $this->usesHistoricalFillsEndpoint($startTime, $endTime)
            ? '/api/v5/trade/fills-history'
            : '/api/v5/trade/fills';

        $fills = [];
        foreach ($this->dataRows($this->privateGet($path, $query, __METHOD__), __METHOD__) as $row) {
            $fills[] = $this->mapper->legacyTrade($row);
        }

        return $fills;
    }

    /**
     * @return array<int, mixed>
     */
    public function getTransactionHistory(
        ?string $symbol = null,
        ?int $flowType = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        $query = ['limit' => max(1, min($limit, 100))];
        if ($symbol !== null) {
            $query['instId'] = $this->resolver()->instId($symbol);
        }
        if ($flowType !== null) {
            $query['type'] = (string) $flowType;
        }
        if ($startTime !== null) {
            $query['begin'] = (string) ($startTime * 1000);
        }
        if ($endTime !== null) {
            $query['end'] = (string) ($endTime * 1000);
        }

        $path = $this->usesArchivedBillsEndpoint($startTime, $endTime)
            ? '/api/v5/account/bills-archive'
            : '/api/v5/account/bills';

        return $this->dataRows($this->privateGet($path, $query, __METHOD__), __METHOD__);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTradingFees(string $symbol): array
    {
        return $this->firstRow($this->privateGet('/api/v5/account/trade-fee', [
            'instType' => 'SWAP',
            'instFamily' => $this->swapInstFamily($symbol),
        ], __METHOD__), __METHOD__);
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
        return 'OKX';
    }

    private function notImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_account_not_implemented', $operation);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function privateGet(string $path, array $query, string $operation): array
    {
        if (!$this->client instanceof OkxRestClientInterface) {
            throw $this->notImplemented($operation);
        }

        try {
            return $this->client->privateGet($path, $query);
        } catch (\Throwable $exception) {
            throw new OkxProviderUnavailableException($this->reason($exception), $operation, $exception);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private function dataRows(array $payload, string $operation): array
    {
        $code = (string) ($payload['code'] ?? '');
        if ($code !== '0') {
            $reason = $code === '50011' ? 'okx_private_rate_limited' : 'okx_private_api_error';

            throw new OkxProviderUnavailableException($reason, $operation);
        }

        $data = $payload['data'] ?? [];
        if (!\is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, \is_array(...)));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstRow(array $payload, string $operation): array
    {
        return $this->dataRows($payload, $operation)[0] ?? [];
    }

    private function resolver(): OkxInstrumentResolver
    {
        return $this->instruments ?? new OkxInstrumentResolver();
    }

    private function usesHistoricalFillsEndpoint(?int $startTime, ?int $endTime): bool
    {
        $threeDaysAgo = time() - (3 * 24 * 60 * 60);

        return ($startTime !== null && $startTime < $threeDaysAgo)
            || ($endTime !== null && $endTime < $threeDaysAgo);
    }

    private function usesArchivedBillsEndpoint(?int $startTime, ?int $endTime): bool
    {
        $sevenDaysAgo = time() - (7 * 24 * 60 * 60);

        return ($startTime !== null && $startTime < $sevenDaysAgo)
            || ($endTime !== null && $endTime < $sevenDaysAgo);
    }

    private function swapInstFamily(string $symbol): string
    {
        return preg_replace('/-SWAP$/', '', $this->resolver()->instId($symbol)) ?? $this->resolver()->instId($symbol);
    }

    private function reason(\Throwable $exception): string
    {
        return str_contains($exception->getMessage(), '429')
            || str_contains(strtolower($exception->getMessage()), 'rate')
            ? 'okx_private_rate_limited'
            : 'okx_private_network_error';
    }
}
