<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Mtf;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\TradingCore\Mtf\Dto\MtfValidationRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfValidationRequest::class)]
final class MtfValidationRequestTest extends TestCase
{
    public function testConstructsExplicitValidationRequest(): void
    {
        $request = new MtfValidationRequest(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            requestedTimeframe: '1m',
            direction: 'LONG',
            dryRun: true,
            forceRun: false,
            forceTimeframeCheck: true,
            metadata: ['run_id' => 'run-123'],
        );

        self::assertSame('BTCUSDT', $request->symbol);
        self::assertSame('BTCUSDT', $request->instrument);
        self::assertSame('scalper_micro', $request->profile);
        self::assertSame(Exchange::FAKE, $request->exchange);
        self::assertSame(MarketType::PERPETUAL, $request->marketType);
        self::assertSame('1m', $request->requestedTimeframe);
        self::assertSame('LONG', $request->direction);
        self::assertTrue($request->dryRun);
        self::assertFalse($request->forceRun);
        self::assertTrue($request->forceTimeframeCheck);
        self::assertSame(['run_id' => 'run-123'], $request->metadata);
    }

    public function testBuildsFromLegacyMtfRunDtoWithoutChangingOptions(): void
    {
        $legacy = new MtfRunDto(
            symbol: 'ETHUSDT',
            profile: 'regular',
            mode: 'pragmatic',
            now: new \DateTimeImmutable('2026-06-15T11:00:00+00:00'),
            requestId: 'run-456',
            dryRun: true,
            options: [
                'exchange' => 'bitmart',
                'market_type' => 'perpetual',
                'current_tf' => '5m',
                'force_run' => true,
                'force_timeframe_check' => false,
                'direction' => 'SHORT',
                'extra_key' => 'kept',
            ],
        );

        $request = MtfValidationRequest::fromMtfRunDto($legacy);

        self::assertSame('ETHUSDT', $request->symbol);
        self::assertSame('regular', $request->profile);
        self::assertSame(Exchange::BITMART, $request->exchange);
        self::assertSame(MarketType::PERPETUAL, $request->marketType);
        self::assertSame('5m', $request->requestedTimeframe);
        self::assertSame('SHORT', $request->direction);
        self::assertTrue($request->dryRun);
        self::assertTrue($request->forceRun);
        self::assertFalse($request->forceTimeframeCheck);
        self::assertSame('run-456', $request->metadata['request_id']);
        self::assertSame('pragmatic', $request->metadata['mode']);
        self::assertSame('kept', $request->metadata['options']['extra_key']);
    }
}
