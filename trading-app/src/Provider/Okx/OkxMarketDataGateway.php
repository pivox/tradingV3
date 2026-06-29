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
    private const int MAX_CANDLE_PAGE_SIZE = 300;
    private const int MAX_CANDLE_LIMIT = 1500;

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
        return $this->rowsToKlines(
            $this->fetchCandleRows($symbol, $timeframe, $limit, __METHOD__),
            $symbol,
            $timeframe,
        );
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
        return $this->rowsToKlines(
            $this->fetchCandleRows($symbol, $timeframe, $limit, __METHOD__, $start, $end),
            $symbol,
            $timeframe,
            $start,
            $end,
        );
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
            $reason = $code === '50011' ? 'okx_public_rate_limited' : 'okx_public_api_error';

            throw new OkxProviderUnavailableException($reason, $operation);
        }

        $data = $payload['data'] ?? [];

        return \is_array($data) ? array_values($data) : [];
    }

    /**
     * @return list<array<int,mixed>>
     */
    private function fetchCandleRows(
        string $symbol,
        Timeframe $timeframe,
        int $limit,
        string $operation,
        ?\DateTimeImmutable $start = null,
        ?\DateTimeImmutable $end = null,
    ): array {
        $requested = $this->requestedLimit($limit);
        $instId = $this->resolver()->instId($symbol);
        $before = $start instanceof \DateTimeImmutable ? (string) ($start->getTimestamp() * 1000) : null;
        $after = $end instanceof \DateTimeImmutable ? (string) ($end->getTimestamp() * 1000) : null;
        $startMs = $start instanceof \DateTimeImmutable ? $start->getTimestamp() * 1000 : null;
        $rowsByOpenTime = [];

        while (\count($rowsByOpenTime) < $requested) {
            $pageLimit = min(self::MAX_CANDLE_PAGE_SIZE, $requested - \count($rowsByOpenTime));
            $query = [
                'instId' => $instId,
                'bar' => $this->mapper->bar($timeframe),
                'limit' => $pageLimit,
            ];
            if ($after !== null) {
                $query['after'] = $after;
            }
            if ($before !== null) {
                $query['before'] = $before;
            }

            $pageRows = $this->dataRows($this->publicGet('/api/v5/market/candles', $query, $operation), $operation);
            if ($pageRows === []) {
                break;
            }

            $oldestOpenMs = null;
            foreach ($pageRows as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $normalized = array_values($row);
                $openMs = $this->rowOpenMilliseconds($normalized);
                if ($openMs === null) {
                    continue;
                }

                $rowsByOpenTime[(string) $openMs] = $normalized;
                $oldestOpenMs = $oldestOpenMs === null ? $openMs : min($oldestOpenMs, $openMs);
            }

            if ($oldestOpenMs === null || $after === (string) $oldestOpenMs) {
                break;
            }

            $after = (string) $oldestOpenMs;
            if ($startMs !== null && $oldestOpenMs <= $startMs) {
                break;
            }
            if (\count($pageRows) < $pageLimit) {
                break;
            }
        }

        return array_values($rowsByOpenTime);
    }

    /**
     * @param list<array<int,mixed>> $rows
     * @return KlineDto[]
     */
    private function rowsToKlines(
        array $rows,
        string $symbol,
        Timeframe $timeframe,
        ?\DateTimeImmutable $start = null,
        ?\DateTimeImmutable $end = null,
    ): array {
        $klines = [];
        foreach ($rows as $row) {
            $kline = $this->mapper->kline($row, strtoupper($symbol), $timeframe);
            if ($start instanceof \DateTimeImmutable && $kline->openTime < $start) {
                continue;
            }
            if ($end instanceof \DateTimeImmutable && $kline->openTime > $end) {
                continue;
            }

            $klines[] = $kline;
        }

        usort(
            $klines,
            static fn (KlineDto $a, KlineDto $b): int => $a->openTime <=> $b->openTime,
        );

        return $klines;
    }

    private function requestedLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }
        if ($limit > self::MAX_CANDLE_LIMIT) {
            throw new \InvalidArgumentException(sprintf('OKX candle limit must be <= %d, got %d.', self::MAX_CANDLE_LIMIT, $limit));
        }

        return $limit;
    }

    /**
     * @param array<int,mixed> $row
     */
    private function rowOpenMilliseconds(array $row): ?int
    {
        $value = $row[0] ?? null;
        if (!\is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }

        return (int) $value;
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
