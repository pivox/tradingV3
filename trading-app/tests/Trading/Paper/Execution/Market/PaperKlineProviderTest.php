<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Market;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperKlineProvider::class)]
final class PaperKlineProviderTest extends TestCase
{
    #[DataProvider('timeframes')]
    public function testEverySupportedTimeframeIsOrderedAndBounded(Timeframe $timeframe): void
    {
        $provider = new PaperKlineProvider(500);
        for ($index = 500; $index >= 0; --$index) {
            $provider->put($this->kline($timeframe, $index));
        }

        $window = $provider->getKlines('BTCUSDT', $timeframe);
        self::assertCount(500, $window);
        self::assertSame('1', (string) $window[0]->close);
        self::assertSame('500', (string) $window[499]->close);
        self::assertSame('500', (string) $provider->getLastKline('BTCUSDT', $timeframe)?->close);
    }

    /** @return iterable<array{Timeframe}> */
    public static function timeframes(): iterable
    {
        yield [Timeframe::TF_1M];
        yield [Timeframe::TF_5M];
        yield [Timeframe::TF_15M];
        yield [Timeframe::TF_1H];
    }

    public function testDuplicateValueIsIdempotentAndConflictingCandleFails(): void
    {
        $provider = new PaperKlineProvider();
        $provider->put($this->kline(Timeframe::TF_1M, 1));
        $provider->put($this->kline(Timeframe::TF_1M, 1));
        self::assertCount(1, $provider->getKlines('BTCUSDT', Timeframe::TF_1M));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_market_candle_conflict');
        $provider->put(new KlineDto(
            'BTCUSDT',
            Timeframe::TF_1M,
            new \DateTimeImmutable('@60'),
            BigDecimal::of('1'), BigDecimal::of('2'), BigDecimal::of('0.5'), BigDecimal::of('999'), BigDecimal::of('10'),
            'paper',
        ));
    }

    private function kline(Timeframe $timeframe, int $index): KlineDto
    {
        return new KlineDto(
            'BTCUSDT',
            $timeframe,
            new \DateTimeImmutable('@' . ($index * 60)),
            BigDecimal::of((string) $index),
            BigDecimal::of((string) ($index + 1)),
            BigDecimal::of((string) max(0, $index - 1)),
            BigDecimal::of((string) $index),
            BigDecimal::of('10'),
            'paper',
        );
    }
}
