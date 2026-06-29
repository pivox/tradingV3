<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\ContractDto;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;

final class OkxMetadataProvider implements ContractProviderInterface
{
    private OkxPublicReadMapper $mapper;

    public function __construct(
        private readonly ?OkxRestClientInterface $client = null,
        private readonly ?OkxInstrumentResolver $instruments = null,
    ) {
        $this->mapper = new OkxPublicReadMapper($this->resolver());
    }

    /**
     * @return ContractDto[]
     */
    public function getContracts(): array
    {
        $contracts = [];
        foreach ($this->instrumentRows(__METHOD__) as $row) {
            $contracts[] = $this->mapper->contract($row);
        }

        usort(
            $contracts,
            static fn (ContractDto $a, ContractDto $b): int => $a->symbol <=> $b->symbol,
        );

        return $contracts;
    }

    public function getContractDetails(string $symbol): ?ContractDto
    {
        $instId = $this->resolver()->instId($symbol);
        foreach ($this->instrumentRows(__METHOD__) as $row) {
            if ((string) ($row['instId'] ?? '') !== $instId) {
                continue;
            }

            return $this->mapper->contract($row, $this->ticker($instId, __METHOD__));
        }

        return null;
    }

    public function getLastPrice(string $symbol): ?float
    {
        $ticker = $this->ticker($this->resolver()->instId($symbol), __METHOD__);
        $last = $ticker['last'] ?? null;

        return \is_numeric($last) ? (float) $last : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        $instId = $this->resolver()->instId($symbol);
        $payload = $this->publicGet('/api/v5/market/books', [
            'instId' => $instId,
            'sz' => max(1, min($limit, 400)),
        ], __METHOD__);
        $row = $this->firstRow($payload, __METHOD__);

        return [
            'symbol' => strtoupper($symbol),
            'instrument_id' => $instId,
            'timestamp' => $this->mapper->time($row['ts'] ?? null),
            'bids' => $this->mapper->orderBookLevels(\is_array($row['bids'] ?? null) ? $row['bids'] : []),
            'asks' => $this->mapper->orderBookLevels(\is_array($row['asks'] ?? null) ? $row['asks'] : []),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        $payload = $this->publicGet('/api/v5/market/trades', [
            'instId' => $this->resolver()->instId($symbol),
            'limit' => max(1, min($limit, 500)),
        ], __METHOD__);

        return $this->dataRows($payload, __METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getMarkPriceKline(
        string $symbol,
        int $step = 1,
        int $limit = 1,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        $query = [
            'instId' => $this->resolver()->instId($symbol),
            'bar' => $this->barFromStep($step),
            'limit' => max(1, min($limit, 100)),
        ];
        if ($startTime !== null) {
            $query['before'] = (string) ($startTime * 1000);
        }
        if ($endTime !== null) {
            $query['after'] = (string) ($endTime * 1000);
        }

        $payload = $this->publicGet('/api/v5/market/mark-price-candles', $query, __METHOD__);

        return $this->dataRows($payload, __METHOD__);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getLeverageBrackets(string $symbol): array
    {
        return [];
    }

    /**
     * @param array<string>|null $symbols
     * @return array{upserted: int, total_fetched: int, errors: array<string>}
     */
    public function syncContracts(?array $symbols = null): array
    {
        $contracts = $this->getContracts();
        $requested = array_map(static fn (string $symbol): string => strtoupper($symbol), $symbols ?? []);
        $errors = [];
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
        if (!$this->client instanceof OkxRestClientInterface) {
            return false;
        }

        try {
            $this->instrumentRows(__METHOD__);

            return true;
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
        return new OkxProviderNotReadyException('okx_metadata_not_implemented', $operation);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function instrumentRows(string $operation): array
    {
        $payload = $this->publicGet('/api/v5/public/instruments', ['instType' => 'SWAP'], $operation);

        return array_values(array_filter(
            $this->dataRows($payload, $operation),
            static fn (mixed $row): bool => \is_array($row) && ($row['instType'] ?? 'SWAP') === 'SWAP',
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function ticker(string $instId, string $operation): array
    {
        return $this->firstRow(
            $this->publicGet('/api/v5/market/ticker', ['instId' => $instId], $operation),
            $operation,
        );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function publicGet(string $path, array $query, string $operation): array
    {
        if (!$this->client instanceof OkxRestClientInterface) {
            throw $this->notImplemented($operation);
        }

        try {
            return $this->client->publicGet($path, $query);
        } catch (\Throwable $exception) {
            throw new OkxProviderUnavailableException($this->reason($exception), $operation, $exception);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<mixed>
     */
    private function dataRows(array $payload, string $operation): array
    {
        $code = (string) ($payload['code'] ?? '');
        if ($code !== '0') {
            $reason = $code === '50011' ? 'okx_public_rate_limited' : 'okx_public_api_error';

            throw new OkxProviderUnavailableException($reason, $operation);
        }

        $data = $payload['data'] ?? [];

        return \is_array($data) ? array_values($data) : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstRow(array $payload, string $operation): array
    {
        $row = $this->dataRows($payload, $operation)[0] ?? [];

        return \is_array($row) ? $row : [];
    }

    private function resolver(): OkxInstrumentResolver
    {
        return $this->instruments ?? new OkxInstrumentResolver();
    }

    private function reason(\Throwable $exception): string
    {
        return str_contains($exception->getMessage(), '429')
            || str_contains(strtolower($exception->getMessage()), 'rate')
            ? 'okx_public_rate_limited'
            : 'okx_public_network_error';
    }

    private function barFromStep(int $step): string
    {
        return match ($step) {
            1 => '1m',
            5 => '5m',
            15 => '15m',
            30 => '30m',
            60 => '1H',
            240 => '4H',
            1440 => '1Dutc',
            default => throw new \InvalidArgumentException(sprintf('Unsupported OKX mark price kline step: %d', $step)),
        };
    }
}
