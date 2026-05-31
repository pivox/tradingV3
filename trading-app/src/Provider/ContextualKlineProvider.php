<?php

declare(strict_types=1);

namespace App\Provider;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Context\ExchangeContext;

final readonly class ContextualKlineProvider implements KlineProviderInterface
{
    public function __construct(
        private KlineProviderInterface $inner,
        private ExchangeContext $context,
    ) {
    }

    public function getKlines(
        string $symbol,
        Timeframe $timeframe,
        int $limit = 490,
        ?ExchangeContext $context = null,
    ): array {
        return $this->inner->getKlines($symbol, $timeframe, $limit, $context ?? $this->context);
    }

    public function getKlinesInWindow(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $limit = 500,
        ?ExchangeContext $context = null,
    ): array {
        return $this->inner->getKlinesInWindow($symbol, $timeframe, $start, $end, $limit, $context ?? $this->context);
    }

    public function getLastKline(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): ?KlineDto {
        return $this->inner->getLastKline($symbol, $timeframe, $context ?? $this->context);
    }

    public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void
    {
        $this->inner->saveKline($kline, $context ?? $this->context);
    }

    public function saveKlines(
        array $klines,
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): void {
        $this->inner->saveKlines($klines, $symbol, $timeframe, $context ?? $this->context);
    }

    public function hasGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): bool {
        return $this->inner->hasGaps($symbol, $timeframe, $context ?? $this->context);
    }

    public function getGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): array {
        return $this->inner->getGaps($symbol, $timeframe, $context ?? $this->context);
    }
}
