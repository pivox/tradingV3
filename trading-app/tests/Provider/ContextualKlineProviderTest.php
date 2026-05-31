<?php

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Context\ExchangeContext;
use App\Provider\ContextualKlineProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ContextualKlineProviderTest extends TestCase
{
    public function testScopedProviderPassesDefaultContextToInnerProvider(): void
    {
        $inner = new class implements KlineProviderInterface {
            public ?ExchangeContext $lastContext = null;

            public function getKlines(
                string $symbol,
                Timeframe $timeframe,
                int $limit = 490,
                ?ExchangeContext $context = null,
            ): array {
                $this->lastContext = $context;
                return [];
            }

            public function getKlinesInWindow(
                string $symbol,
                Timeframe $timeframe,
                \DateTimeImmutable $start,
                \DateTimeImmutable $end,
                int $limit = 500,
                ?ExchangeContext $context = null,
            ): array {
                $this->lastContext = $context;
                return [];
            }

            public function getLastKline(
                string $symbol,
                Timeframe $timeframe,
                ?ExchangeContext $context = null,
            ): ?KlineDto {
                $this->lastContext = $context;
                return null;
            }

            public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void
            {
                $this->lastContext = $context;
            }

            public function saveKlines(
                array $klines,
                string $symbol,
                Timeframe $timeframe,
                ?ExchangeContext $context = null,
            ): void {
                $this->lastContext = $context;
            }

            public function hasGaps(
                string $symbol,
                Timeframe $timeframe,
                ?ExchangeContext $context = null,
            ): bool {
                $this->lastContext = $context;
                return false;
            }

            public function getGaps(
                string $symbol,
                Timeframe $timeframe,
                ?ExchangeContext $context = null,
            ): array {
                $this->lastContext = $context;
                return [];
            }
        };
        $binance = new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL);
        $provider = new ContextualKlineProvider($inner, $binance);

        $provider->getKlines('BTCUSDT', Timeframe::TF_1M);
        self::assertTrue($inner->lastContext?->equals($binance));

        $provider->getLastKline('BTCUSDT', Timeframe::TF_1M, ExchangeContext::legacyDefault());
        self::assertTrue($inner->lastContext?->equals(ExchangeContext::legacyDefault()));
    }
}
