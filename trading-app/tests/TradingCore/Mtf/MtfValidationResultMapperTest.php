<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Mtf;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\ExecutionSelectionDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use App\TradingCore\Mtf\Dto\MtfRejectionReason;
use App\TradingCore\Mtf\Dto\MtfValidationResult;
use App\TradingCore\Mtf\Dto\ValidatedTimeframe;
use App\TradingCore\Mtf\Mapper\MtfValidationResultMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfValidationResultMapper::class)]
#[CoversClass(MtfValidationResult::class)]
#[CoversClass(ValidatedTimeframe::class)]
#[CoversClass(MtfRejectionReason::class)]
final class MtfValidationResultMapperTest extends TestCase
{
    public function testMapsTradableMtfResultToReadyValidationResult(): void
    {
        $evaluatedAt = new \DateTimeImmutable('2026-06-15T10:00:00+00:00');
        $legacyResult = new MtfResultDto(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            mode: 'pragmatic',
            evaluatedAt: $evaluatedAt,
            isTradable: true,
            side: 'LONG',
            executionTimeframe: '1m',
            context: new ContextDecisionDto(
                isValid: true,
                reasonIfInvalid: null,
                timeframeDecisions: [
                    new TimeframeDecisionDto(
                        timeframe: '1h',
                        phase: 'context',
                        signal: 'long',
                        valid: true,
                        rulesPassed: ['trend_ok'],
                    ),
                ],
            ),
            execution: new ExecutionSelectionDto(
                selectedTimeframe: '1m',
                selectedSide: 'LONG',
                reasonIfNone: null,
                timeframeDecisions: [
                    new TimeframeDecisionDto(
                        timeframe: '1m',
                        phase: 'execution',
                        signal: 'long',
                        valid: true,
                        rulesPassed: ['pullback_ok'],
                    ),
                ],
            ),
            finalReason: null,
            extra: [
                'request_id' => 'run-123',
                'options' => ['dry_run' => true],
            ],
        );

        $result = (new MtfValidationResultMapper())->fromMtfResult(
            result: $legacyResult,
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            metadata: ['source' => 'unit-test'],
        );

        self::assertSame('BTCUSDT', $result->symbol);
        self::assertSame('BTCUSDT', $result->instrument);
        self::assertSame('scalper_micro', $result->profile);
        self::assertSame(Exchange::BITMART, $result->exchange);
        self::assertSame(MarketType::PERPETUAL, $result->marketType);
        self::assertSame('READY', $result->status);
        self::assertSame('LONG', $result->direction);
        self::assertSame('1m', $result->executionTimeframe);
        self::assertCount(2, $result->validatedTimeframes);
        self::assertSame([], $result->rejectedTimeframes);
        self::assertSame([], $result->rejectedBy);
        self::assertSame(['source' => 'unit-test'], $result->metadata);
        self::assertSame('BTCUSDT', $result->rawLegacyPayload['symbol']);
        self::assertSame('run-123', $result->rawLegacyPayload['extra']['request_id']);
    }

    public function testMapsRejectedMtfResultAndPreservesRejectedByAndRawPayload(): void
    {
        $legacyResult = new MtfResultDto(
            symbol: 'ETHUSDT',
            profile: 'regular',
            mode: null,
            evaluatedAt: new \DateTimeImmutable('2026-06-15T10:05:00+00:00'),
            isTradable: false,
            side: null,
            executionTimeframe: null,
            context: new ContextDecisionDto(
                isValid: false,
                reasonIfInvalid: 'context_invalid',
                timeframeDecisions: [
                    new TimeframeDecisionDto(
                        timeframe: '1h',
                        phase: 'context',
                        signal: 'invalid',
                        valid: false,
                        invalidReason: 'ema_trend_failed',
                        rulesFailed: ['ema50_gt_200'],
                    ),
                ],
            ),
            execution: new ExecutionSelectionDto(
                selectedTimeframe: null,
                selectedSide: null,
                reasonIfNone: 'context_invalid',
                timeframeDecisions: [],
            ),
            finalReason: 'context_invalid',
            extra: ['request_id' => 'run-456'],
        );

        $result = (new MtfValidationResultMapper())->fromMtfResult($legacyResult);

        self::assertSame('REJECTED', $result->status);
        self::assertNull($result->direction);
        self::assertNull($result->executionTimeframe);
        self::assertSame(['context_invalid', 'ema_trend_failed'], $result->rejectedBy);
        self::assertCount(1, $result->rejectedTimeframes);
        self::assertSame('1h', $result->rejectedTimeframes[0]->timeframe);
        self::assertSame('ema_trend_failed', $result->rejectedTimeframes[0]->rejectionReason?->reason);
        self::assertSame('context_invalid', $result->rawLegacyPayload['finalReason']);
    }

    public function testMapsLegacyPayloadPreservingExplicitStatusAndMetadata(): void
    {
        $payload = [
            'symbol' => 'SOLUSDT',
            'profile' => 'scalper',
            'exchange' => 'okx',
            'market_type' => 'perpetual',
            'status' => 'REJECTED',
            'execution_tf' => '5m',
            'signal_side' => 'NONE',
            'rejected_by' => ['rsi_lt_softcap'],
            'reason' => 'rsi_lt_softcap',
            'context' => ['mode' => 'strict'],
        ];

        $result = (new MtfValidationResultMapper())->fromLegacyPayload(
            payload: $payload,
            metadata: ['worker' => 'mtf:run-worker'],
        );

        self::assertSame('SOLUSDT', $result->symbol);
        self::assertSame('scalper', $result->profile);
        self::assertSame(Exchange::OKX, $result->exchange);
        self::assertSame(MarketType::PERPETUAL, $result->marketType);
        self::assertSame('REJECTED', $result->status);
        self::assertSame('5m', $result->executionTimeframe);
        self::assertSame(['rsi_lt_softcap'], $result->rejectedBy);
        self::assertSame(['worker' => 'mtf:run-worker'], $result->metadata);
        self::assertSame($payload, $result->rawLegacyPayload);
    }
}
