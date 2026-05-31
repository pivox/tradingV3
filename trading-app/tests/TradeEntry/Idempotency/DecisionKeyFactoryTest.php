<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Idempotency;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Idempotency\DecisionKeyFactory;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\TradeEntry\Types\Side;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecisionKeyFactory::class)]
#[CoversClass(IdempotencyPolicy::class)]
final class DecisionKeyFactoryTest extends TestCase
{
    public function testBuildsStableBusinessDecisionKey(): void
    {
        $factory = new DecisionKeyFactory();
        $context = new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL);
        $evaluatedAt = new \DateTimeImmutable('2025-11-26 12:34:56 UTC');

        $key = $factory->key(
            context: $context,
            symbol: 'btcusdt',
            timeframe: '1m',
            candleOpenTs: null,
            side: Side::Long,
            strategyProfile: 'scalper_micro',
            strategyVersion: 'v1.1.7',
            evaluatedAt: $evaluatedAt,
        );

        self::assertSame('bitmart:perpetual:BTCUSDT:1m:1764160440:long:scalper_micro:v1.1.7', $key);
        self::assertSame([
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'candle_open_ts' => 1764160440,
            'side' => 'long',
            'strategy_profile' => 'scalper_micro',
            'strategy_version' => 'v1.1.7',
        ], $factory->parse($key));
    }

    public function testClientOrderIdIsDeterministicAndBitmartSafe(): void
    {
        $policy = new IdempotencyPolicy();
        $decisionKey = 'bitmart:perpetual:BTCUSDT:1m:1764160440:long:scalper_micro:v1.1.7';

        $first = $policy->newClientOrderId($decisionKey);
        $second = $policy->newClientOrderId($decisionKey);

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/^CID[A-F0-9]{29}$/', $first);
        self::assertSame(32, strlen($first));
    }
}
