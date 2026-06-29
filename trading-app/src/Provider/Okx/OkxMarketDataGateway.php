<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Provider\Context\ExchangeContext;

final class OkxMarketDataGateway implements KlineProviderInterface
{
    private OkxPublicReadMapper $mapper;

    public function __construct(
        private readonly ?OkxRestClientInterface $client = null,
        private readonly ?OkxInstrumentResolver $instruments = null,
    ) {
        $this->mapper = new OkxPublicReadMapper($this->resolver());
    }

    /**
     * @return KlineDto[]
     */
    public function getKlines(
        string $symbol,
        Timeframe $timeframe,
        int $limit = 490,
        ?ExchangeContext $context = null,
    ): array {
        $instId = $this->resolver()->instId($symbol);
        $payload = $this->publicGet('/api/v5/market/candles', [
            'instId' => $instId,
            'bar' => $this->mapper->bar($timeframe),
            'limit' => max(1, min($limit, 300)),
        ], __METHOD__);

        $klines = [];
        foreach ($this->dataRows($payload, __METHOD__) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $klines[] = $this->mapper->kline(array_values($row), strtoupper($symbol), $timeframe);
        }

        usort(
            $klines,
            static fn (KlineDto $a, KlineDto $b): int => $a->openTime <=> $b->openTime,
        );

        return $klines;
    }

    /**
     * @return KlineDto[]
     */
    public function getKlinesInWindow(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $limit = 500,
        ?ExchangeContext $context = null,
    ): array {
        $query = [
            'instId' => $this->resolver()->instId($symbol),
            'bar' => $this->mapper->bar($timeframe),
            'after' => (string) ($end->getTimestamp() * 1000),
            'before' => (string) ($start->getTimestamp() * 1000),
            'limit' => max(1, min($limit, 300)),
        ];

        $payload = $this->publicGet('/api/v5/market/candles', $query, __METHOD__);
        $klines = [];
        foreach ($this->dataRows($payload, __METHOD__) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $kline = $this->mapper->kline(array_values($row), strtoupper($symbol), $timeframe);
            if ($kline->openTime >= $start && $kline->openTime <= $end) {
                $klines[] = $kline;
            }
        }

        usort(
            $klines,
            static fn (KlineDto $a, KlineDto $b): int => $a->openTime <=> $b->openTime,
        );

        return $klines;
    }

    public function getLastKline(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): ?KlineDto {
        return $this->getKlines($symbol, $timeframe, 1, $context)[0] ?? null;
    }

    public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void
    {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @param KlineDto[] $klines
     */
    public function saveKlines(
        array $klines,
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): void {
        throw $this->notImplemented(__METHOD__);
    }

    public function hasGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): bool {
        return false;
    }

    /**
     * @return array<int, mixed>
     */
    public function getGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): array {
        return [];
    }

    public function healthCheck(): bool
    {
        if (!$this->client instanceof OkxRestClientInterface) {
            return false;
        }

        try {
            $this->publicGet('/api/v5/public/instruments', ['instType' => 'SWAP'], __METHOD__);

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
        return new OkxProviderNotReadyException('okx_market_data_not_implemented', $operation);
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
            throw new OkxProviderUnavailableException('okx_public_api_error', $operation);
        }

        $data = $payload['data'] ?? [];

        return \is_array($data) ? array_values($data) : [];
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
}
