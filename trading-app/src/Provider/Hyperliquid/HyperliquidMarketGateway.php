<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Provider\Context\ExchangeContext;

final class HyperliquidMarketGateway implements KlineProviderInterface
{
    private const int MAX_CANDLE_LIMIT = 500;

    private HyperliquidPublicReadMapper $mapper;

    public function __construct(
        private readonly ?HyperliquidRestClientInterface $client = null,
        private readonly ?HyperliquidAssetResolver $assets = null,
    ) {
        $this->mapper = new HyperliquidPublicReadMapper();
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
        $end = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add(new \DateInterval('P1D'));
        $start = new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));

        return $this->fetchKlines($symbol, $timeframe, $start, $end, $limit, __METHOD__);
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
        return $this->fetchKlines($symbol, $timeframe, $start, $end, $limit, __METHOD__);
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
        throw $this->notReady(__METHOD__);
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
        throw $this->notReady(__METHOD__);
    }

    public function hasGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): bool {
        return false;
    }

    /**
     * @return array<int,mixed>
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
        return new HyperliquidProviderNotReadyException('hyperliquid_market_data_not_ready', $operation);
    }

    /**
     * @return KlineDto[]
     */
    private function fetchKlines(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $limit,
        string $operation,
    ): array {
        $requested = $this->requestedLimit($limit);
        $coin = $this->resolver()->coin($symbol);
        $rows = $this->info([
            'type' => 'candleSnapshot',
            'req' => [
                'coin' => $coin,
                'interval' => $this->mapper->interval($timeframe),
                'startTime' => $start->getTimestamp() * 1000,
                'endTime' => $end->getTimestamp() * 1000,
            ],
        ], $operation);

        $byOpenTime = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $kline = $this->mapper->kline($row, strtoupper($symbol), $timeframe);
            if ($kline->openTime < $start || $kline->openTime > $end) {
                continue;
            }
            $byOpenTime[$kline->openTime->format('U.u')] = $kline;
        }

        ksort($byOpenTime);

        return array_slice(array_values($byOpenTime), -$requested);
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

    private function resolver(): HyperliquidAssetResolver
    {
        return $this->assets ?? new HyperliquidAssetResolver($this->client ?? throw $this->notReady(__METHOD__));
    }

    private function requestedLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_CANDLE_LIMIT));
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
