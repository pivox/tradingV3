<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Common\Enum\Exchange;
use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\ExecutionSelectionDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\TradeEntry\Types\Side;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Strategy\PaperMtfPreparationResolver;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperMtfPreparationResolver::class)]
final class PaperMtfPreparationResolverTest extends TestCase
{
    public function testBuildsDeclaredPrudentPlanWithoutAmbientPreparationServices(): void
    {
        $cell = PaperExecutionCell::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'sha256:' . str_repeat('a', 64), 'scalper_micro', 'run-001');
        $event = PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable('2026-08-01T10:00:59Z'),
            new \DateTimeImmutable('2026-08-01T10:01:00Z'),
            '1',
            ['interval' => '1m', 'start_time' => '1785578400000', 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '5', 'confirmed' => true],
        );
        $result = new MtfResultDto(
            'BTCUSDT',
            'scalper_micro',
            'scalper_micro',
            $event->exchangeTimestamp,
            true,
            'long',
            '1m',
            new ContextDecisionDto(true, null, []),
            new ExecutionSelectionDto('1m', 'long', null, []),
        );
        $response = new MtfRunResponseDto('mtf-run', 'success', 0.0, 1, 1, 1, 0, 0, 100.0, [['symbol' => 'BTCUSDT', 'result' => $result]], [], $event->exchangeTimestamp);

        $prepared = (new PaperMtfPreparationResolver())($response, $cell, $event);

        self::assertNotNull($prepared);
        self::assertNotNull($prepared->plan);
        self::assertSame(Side::Long, $prepared->plan->side);
        self::assertSame('market', $prepared->plan->orderType);
        self::assertSame(100.0, $prepared->plan->entry);
        self::assertSame(98.0, $prepared->plan->stop);
        self::assertSame(102.0, $prepared->plan->takeProfit);
        self::assertSame(1, $prepared->plan->size);
        self::assertSame(1, $prepared->plan->leverage);
        self::assertSame(Exchange::FAKE, $prepared->plan->exchangeContext?->exchange);
        self::assertSame(PaperMtfPreparationResolver::MODEL_VERSION, $prepared->plan->entryZoneMeta['model_version'] ?? null);
        self::assertSame('2026-08-01T10:03:59+00:00', $prepared->plan->zoneExpiresAt?->format(DATE_ATOM));
    }
}
