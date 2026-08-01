<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Market;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Context\ExchangeContext;

/** Read-only KlineProvider port used by the FAKE MTF context during Paper runs. */
final readonly class PaperKlineProviderAdapter implements KlineProviderInterface
{
    public function __construct(private PaperKlineProvider $paper)
    {
    }

    /** @return list<KlineDto> */
    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 490, ?ExchangeContext $context = null): array
    {
        return $this->paper->getKlines($symbol, $timeframe, $limit);
    }

    /** @return list<KlineDto> */
    public function getKlinesInWindow(string $symbol, Timeframe $timeframe, \DateTimeImmutable $start, \DateTimeImmutable $end, int $limit = 500, ?ExchangeContext $context = null): array
    {
        return array_values(array_slice(array_filter(
            $this->paper->getKlines($symbol, $timeframe),
            static fn (KlineDto $kline): bool => $kline->openTime >= $start && $kline->openTime <= $end,
        ), -$limit));
    }

    public function getLastKline(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): ?KlineDto
    {
        return $this->paper->getLastKline($symbol, $timeframe);
    }

    public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void
    {
        throw new \LogicException('paper_kline_provider_read_only');
    }

    /** @param list<KlineDto> $klines */
    public function saveKlines(array $klines, string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): void
    {
        throw new \LogicException('paper_kline_provider_read_only');
    }

    public function hasGaps(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): bool
    {
        return false;
    }

    /** @return list<mixed> */
    public function getGaps(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): array
    {
        return [];
    }

    public function healthCheck(): bool
    {
        return true;
    }

    public function getProviderName(): string
    {
        return 'Paper';
    }
}
