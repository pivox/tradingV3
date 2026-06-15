<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Decision;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\TradingCore\Decision\Dto\TradeCandidate;
use App\TradingCore\Decision\Mapper\TradeCandidateMapper;
use App\TradingCore\Mtf\Dto\MtfValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TradeCandidateMapper::class)]
#[CoversClass(TradeCandidate::class)]
#[CoversClass(MtfValidationResult::class)]
final class TradeCandidateMapperTest extends TestCase
{
    public function testBuildsCandidateFromReadyValidationResult(): void
    {
        $validationResult = new MtfValidationResult(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            status: 'READY',
            direction: 'LONG',
            executionTimeframe: '1m',
            metadata: ['validation_mode' => 'pragmatic'],
            rawLegacyPayload: ['status' => 'READY'],
        );
        $signalTime = new \DateTimeImmutable('2026-06-15T12:00:00+00:00');

        $candidate = (new TradeCandidateMapper())->fromValidationResult(
            validationResult: $validationResult,
            dryRun: true,
            signalTime: $signalTime,
            entryContext: ['current_price' => 105.25],
            metadata: ['run_id' => 'run-123'],
        );

        self::assertInstanceOf(TradeCandidate::class, $candidate);
        self::assertSame('BTCUSDT', $candidate->symbol);
        self::assertSame('BTCUSDT', $candidate->instrument);
        self::assertSame('scalper_micro', $candidate->profile);
        self::assertSame(Exchange::BITMART, $candidate->exchange);
        self::assertSame(MarketType::PERPETUAL, $candidate->marketType);
        self::assertSame('LONG', $candidate->direction);
        self::assertSame('1m', $candidate->executionTimeframe);
        self::assertSame($signalTime, $candidate->signalTime);
        self::assertSame($validationResult, $candidate->validationResult);
        self::assertSame(['current_price' => 105.25], $candidate->entryContext);
        self::assertTrue($candidate->dryRun);
        self::assertSame(['run_id' => 'run-123'], $candidate->metadata);
    }

    public function testReturnsNullForRejectedValidationResult(): void
    {
        $validationResult = new MtfValidationResult(
            symbol: 'ETHUSDT',
            profile: 'regular',
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            status: 'REJECTED',
            direction: null,
            executionTimeframe: null,
            rejectedBy: ['context_invalid'],
            rawLegacyPayload: ['status' => 'REJECTED'],
        );

        self::assertNull((new TradeCandidateMapper())->fromValidationResult(
            validationResult: $validationResult,
            dryRun: true,
        ));
    }

    public function testMapsReadyLegacySymbolResultToTradeCandidate(): void
    {
        $symbolResult = new SymbolResultDto(
            symbol: 'SOLUSDT',
            status: 'READY',
            executionTf: '5m',
            signalSide: 'SHORT',
            context: [
                'profile' => 'scalper',
                'extra' => ['request_id' => 'run-789'],
            ],
            currentPrice: 42.5,
            atr: 0.25,
            validationModeUsed: 'strict',
            tradeEntryModeUsed: 'scalper',
        );
        $signalTime = new \DateTimeImmutable('2026-06-15T12:30:00+00:00');

        $candidate = (new TradeCandidateMapper())->fromSymbolResult(
            symbolResult: $symbolResult,
            profile: 'scalper',
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            dryRun: true,
            signalTime: $signalTime,
            metadata: ['worker' => 'mtf:run-worker'],
        );

        self::assertInstanceOf(TradeCandidate::class, $candidate);
        self::assertSame('SOLUSDT', $candidate->symbol);
        self::assertSame('scalper', $candidate->profile);
        self::assertSame(Exchange::OKX, $candidate->exchange);
        self::assertSame(MarketType::PERPETUAL, $candidate->marketType);
        self::assertSame('SHORT', $candidate->direction);
        self::assertSame('5m', $candidate->executionTimeframe);
        self::assertSame($signalTime, $candidate->signalTime);
        self::assertSame(
            [
                'current_price' => 42.5,
                'atr' => 0.25,
            ],
            $candidate->entryContext,
        );
        self::assertSame(['worker' => 'mtf:run-worker'], $candidate->metadata);
        self::assertSame('READY', $candidate->validationResult->status);
        self::assertSame('run-789', $candidate->validationResult->rawLegacyPayload['context']['extra']['request_id']);
    }
}
