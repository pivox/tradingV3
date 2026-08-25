<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveIntegrityException;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicSubscriptionSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperPublicSubscriptionSet::class)]
#[CoversClass(HyperliquidPaperLivePolicy::class)]
#[CoversClass(HyperliquidPaperLiveIntegrityException::class)]
final class HyperliquidPaperPublicSubscriptionSetTest extends TestCase
{
    public function testBuildsExactlyTheTwelveCanonicalPublicSubscriptions(): void
    {
        $set = new HyperliquidPaperPublicSubscriptionSet();

        self::assertSame([
            self::subscription('trades', 'BTC'),
            self::subscription('l2Book', 'BTC'),
            self::subscription('candle', 'BTC', '1m'),
            self::subscription('candle', 'BTC', '5m'),
            self::subscription('candle', 'BTC', '15m'),
            self::subscription('candle', 'BTC', '1h'),
            self::subscription('trades', 'ETH'),
            self::subscription('l2Book', 'ETH'),
            self::subscription('candle', 'ETH', '1m'),
            self::subscription('candle', 'ETH', '5m'),
            self::subscription('candle', 'ETH', '15m'),
            self::subscription('candle', 'ETH', '1h'),
        ], $set->subscriptions());
        self::assertFalse($set->isReady());
    }

    public function testRequiresEveryCanonicalAcknowledgementAndCanReset(): void
    {
        $set = new HyperliquidPaperPublicSubscriptionSet();
        foreach ($set->subscriptions() as $index => $message) {
            $set->acknowledge([
                'method' => 'subscribe',
                'subscription' => $message['subscription'],
            ]);
            self::assertSame($index === 11, $set->isReady());
        }

        $set->reset();
        self::assertFalse($set->isReady());
    }

    public function testNormalizesExactServerDefaultsOnL2BookAcknowledgements(): void
    {
        $set = new HyperliquidPaperPublicSubscriptionSet();
        foreach ($set->subscriptions() as $message) {
            $subscription = $message['subscription'];
            if ($subscription['type'] === 'l2Book') {
                $subscription += [
                    'nSigFigs' => null,
                    'mantissa' => null,
                    'fast' => false,
                ];
            }
            $set->acknowledge([
                'method' => 'subscribe',
                'subscription' => $subscription,
            ]);
        }

        self::assertTrue($set->isReady());
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function forbiddenMessages(): iterable
    {
        foreach ([
            'notification',
            'webData3',
            'openOrders',
            'orderUpdates',
            'userEvents',
            'userFills',
            'userFundings',
            'activeAssetData',
        ] as $type) {
            yield $type => [[
                'method' => 'subscribe',
                'subscription' => ['type' => $type, 'user' => '0xsecret'],
            ]];
        }

        yield 'post action' => [[
            'method' => 'post',
            'id' => 1,
            'request' => ['type' => 'action', 'payload' => ['wallet' => 'secret']],
        ]];
        yield 'post info' => [[
            'method' => 'post',
            'id' => 1,
            'request' => ['type' => 'info', 'payload' => []],
        ]];
        yield 'extra subscription key' => [[
            'method' => 'subscribe',
            'subscription' => ['type' => 'trades', 'coin' => 'BTC', 'user' => '0xsecret'],
        ]];
        yield 'unsupported coin' => [self::subscription('trades', 'SOL')];
        yield 'unsupported interval' => [self::subscription('candle', 'BTC', '3m')];
        yield 'unknown method' => [[
            'method' => 'action',
            'subscription' => ['type' => 'trades', 'coin' => 'BTC'],
        ]];
    }

    /** @param array<array-key, mixed> $message */
    #[DataProvider('forbiddenMessages')]
    public function testRejectsEveryNonCanonicalOutboundShapeWithoutLeakingIt(
        array $message,
    ): void {
        try {
            HyperliquidPaperPublicSubscriptionSet::assertOutbound($message);
            self::fail('Expected public subscription rejection.');
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_subscription_invalid',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testAcceptsOnlyCanonicalSubscribeUnsubscribeAndPingOutbound(): void
    {
        $subscription = self::subscription('trades', 'BTC');
        HyperliquidPaperPublicSubscriptionSet::assertOutbound($subscription);
        HyperliquidPaperPublicSubscriptionSet::assertOutbound([
            'method' => 'unsubscribe',
            'subscription' => $subscription['subscription'],
        ]);
        HyperliquidPaperPublicSubscriptionSet::assertOutbound(['method' => 'ping']);

        self::addToAssertionCount(3);
    }

    public function testPinsFiniteLivePolicyBounds(): void
    {
        self::assertSame(
            [1.0, 2.0, 4.0, 8.0, 15.0, 30.0],
            HyperliquidPaperLivePolicy::RECONNECT_DELAYS_SECONDS,
        );
        self::assertSame(5.0, HyperliquidPaperLivePolicy::HEARTBEAT_IDLE_SECONDS);
        self::assertSame(10.0, HyperliquidPaperLivePolicy::PONG_TIMEOUT_SECONDS);
        self::assertSame(1_048_576, HyperliquidPaperLivePolicy::MAX_FRAME_BYTES);
        self::assertSame(256, HyperliquidPaperLivePolicy::MAX_QUEUED_FRAMES);
        self::assertSame(2_097_152, HyperliquidPaperLivePolicy::MAX_QUEUED_BYTES);
        self::assertSame(64, HyperliquidPaperLivePolicy::NETWORK_RESUME_FRAME_LOW_WATER);
        self::assertSame(1_048_576, HyperliquidPaperLivePolicy::NETWORK_PUMP_BYTE_HIGH_WATER);
        self::assertSame(524_288, HyperliquidPaperLivePolicy::NETWORK_RESUME_BYTE_LOW_WATER);
        self::assertSame(500, HyperliquidPaperLivePolicy::MAX_BOOK_LEVELS_PER_SIDE);
        self::assertSame(1_048_576, HyperliquidPaperLivePolicy::MAX_CHECKPOINT_BYTES);
        self::assertSame(8, HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS);
        self::assertSame(512, HyperliquidPaperLivePolicy::MAX_ACKNOWLEDGED_EVENT_IDENTITIES);
        self::assertSame(
            500,
            HyperliquidPaperLivePolicy::MAX_ACKNOWLEDGED_IDENTITIES_PER_STREAM,
        );
    }

    /** @return array{method: string, subscription: array<string, string>} */
    private static function subscription(
        string $type,
        string $coin,
        ?string $interval = null,
    ): array {
        $subscription = ['type' => $type, 'coin' => $coin];
        if ($interval !== null) {
            $subscription['interval'] = $interval;
        }

        return ['method' => 'subscribe', 'subscription' => $subscription];
    }
}
