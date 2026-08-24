<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\MarketData\CanonicalJson;

final class HyperliquidPaperPublicSubscriptionSet
{
    private const COINS = ['BTC', 'ETH'];
    private const SIMPLE_TYPES = ['trades', 'l2Book'];
    private const CANDLE_INTERVALS = ['1m', '5m', '15m', '1h'];

    /** @var list<array{method: string, subscription: array<string, string>}> */
    private array $subscriptions = [];

    /** @var array<string, true> */
    private array $required = [];

    /** @var array<string, true> */
    private array $acknowledged = [];

    public function __construct()
    {
        foreach (self::COINS as $coin) {
            foreach (self::SIMPLE_TYPES as $type) {
                $this->add(['type' => $type, 'coin' => $coin]);
            }
            foreach (self::CANDLE_INTERVALS as $interval) {
                $this->add([
                    'type' => 'candle',
                    'coin' => $coin,
                    'interval' => $interval,
                ]);
            }
        }
    }

    /** @return list<array{method: string, subscription: array<string, string>}> */
    public function subscriptions(): array
    {
        return $this->subscriptions;
    }

    /** @param array<array-key, mixed> $response */
    public function acknowledge(#[\SensitiveParameter] array $response): void
    {
        self::assertExactKeys($response, ['method', 'subscription']);
        if (($response['method'] ?? null) !== 'subscribe'
            || !\is_array($response['subscription'] ?? null)
        ) {
            self::invalid();
        }
        /** @var array<array-key, mixed> $subscription */
        $subscription = self::normalizeAcknowledgementSubscription(
            $response['subscription'],
        );
        self::assertSubscription($subscription);
        $key = CanonicalJson::encode($subscription);
        if (!isset($this->required[$key])) {
            self::invalid();
        }

        $this->acknowledged[$key] = true;
    }

    /** @param array<array-key, mixed> $message */
    public static function assertOutbound(#[\SensitiveParameter] array $message): void
    {
        if ($message === ['method' => 'ping']) {
            return;
        }

        self::assertExactKeys($message, ['method', 'subscription']);
        if (!\is_string($message['method'] ?? null)
            || !\in_array($message['method'], ['subscribe', 'unsubscribe'], true)
            || !\is_array($message['subscription'] ?? null)
        ) {
            self::invalid();
        }
        /** @var array<array-key, mixed> $subscription */
        $subscription = $message['subscription'];
        self::assertSubscription($subscription);
    }

    public function isReady(): bool
    {
        return \count($this->acknowledged) === \count($this->required);
    }

    public function reset(): void
    {
        $this->acknowledged = [];
    }

    /**
     * @param array<array-key, mixed> $subscription
     * @return array<array-key, mixed>
     */
    private static function normalizeAcknowledgementSubscription(array $subscription): array
    {
        if (($subscription['type'] ?? null) !== 'l2Book'
            || \count($subscription) === 2
        ) {
            return $subscription;
        }

        self::assertExactKeys(
            $subscription,
            ['type', 'coin', 'nSigFigs', 'mantissa', 'fast'],
        );
        if ($subscription['nSigFigs'] !== null
            || $subscription['mantissa'] !== null
            || $subscription['fast'] !== false
        ) {
            self::invalid();
        }

        return [
            'type' => $subscription['type'],
            'coin' => $subscription['coin'] ?? null,
        ];
    }

    /** @param array<string, string> $subscription */
    private function add(array $subscription): void
    {
        $message = ['method' => 'subscribe', 'subscription' => $subscription];
        $this->subscriptions[] = $message;
        $this->required[CanonicalJson::encode($subscription)] = true;
    }

    /** @param array<array-key, mixed> $subscription */
    private static function assertSubscription(array $subscription): void
    {
        $type = $subscription['type'] ?? null;
        $coin = $subscription['coin'] ?? null;
        if (!\is_string($type)
            || !\is_string($coin)
            || !\in_array($coin, self::COINS, true)
        ) {
            self::invalid();
        }

        if (\in_array($type, self::SIMPLE_TYPES, true)) {
            self::assertExactKeys($subscription, ['type', 'coin']);

            return;
        }

        if ($type !== 'candle') {
            self::invalid();
        }
        self::assertExactKeys($subscription, ['type', 'coin', 'interval']);
        if (!\is_string($subscription['interval'] ?? null)
            || !\in_array($subscription['interval'], self::CANDLE_INTERVALS, true)
        ) {
            self::invalid();
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $keys
     */
    private static function assertExactKeys(array $value, array $keys): void
    {
        if (\count($value) !== \count($keys)) {
            self::invalid();
        }
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $value)) {
                self::invalid();
            }
        }
    }

    private static function invalid(): never
    {
        throw new HyperliquidPaperLiveIntegrityException(
            'hyperliquid_paper_public_subscription_invalid',
        );
    }
}
