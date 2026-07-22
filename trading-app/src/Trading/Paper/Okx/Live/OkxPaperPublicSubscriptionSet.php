<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\OkxPaperInstrumentMap;

final class OkxPaperPublicSubscriptionSet
{
    private const PUBLIC_CHANNELS = [
        'trades',
        'books',
    ];

    private const BUSINESS_CHANNELS = [
        'candle1m',
        'candle5m',
        'candle15m',
        'candle1H',
    ];

    /** @var list<array{channel: string, instId: string}> */
    private array $publicArguments = [];

    /** @var list<array{channel: string, instId: string}> */
    private array $businessArguments = [];

    /** @var array<string, true> */
    private array $publicRequired = [];

    /** @var array<string, true> */
    private array $businessRequired = [];

    /** @var array<string, true> */
    private array $publicAcknowledged = [];

    /** @var array<string, true> */
    private array $businessAcknowledged = [];

    public function __construct(OkxPaperInstrumentMap $instruments)
    {
        foreach ($instruments->nativeInstrumentIds() as $instrumentId) {
            foreach (self::PUBLIC_CHANNELS as $channel) {
                $this->publicArguments[] = ['channel' => $channel, 'instId' => $instrumentId];
                $this->publicRequired[self::key($channel, $instrumentId)] = true;
            }
            foreach (self::BUSINESS_CHANNELS as $channel) {
                $this->businessArguments[] = ['channel' => $channel, 'instId' => $instrumentId];
                $this->businessRequired[self::key($channel, $instrumentId)] = true;
            }
        }
    }

    /** @return list<array{channel: string, instId: string}> */
    public function publicArguments(): array
    {
        return $this->publicArguments;
    }

    /** @return list<array{channel: string, instId: string}> */
    public function businessArguments(): array
    {
        return $this->businessArguments;
    }

    /** @param array<array-key, mixed> $arg */
    public function acknowledgePublic(array $arg): void
    {
        $key = $this->requiredKey($arg, $this->publicRequired);
        $this->publicAcknowledged[$key] = true;
    }

    /** @param array<array-key, mixed> $arg */
    public function acknowledgeBusiness(array $arg): void
    {
        $key = $this->requiredKey($arg, $this->businessRequired);
        $this->businessAcknowledged[$key] = true;
    }

    public function isPublicRequired(string $channel, string $instrumentId): bool
    {
        return isset($this->publicRequired[self::key($channel, $instrumentId)]);
    }

    public function isBusinessRequired(string $channel, string $instrumentId): bool
    {
        return isset($this->businessRequired[self::key($channel, $instrumentId)]);
    }

    public function isPublicReady(): bool
    {
        return count($this->publicAcknowledged) === count($this->publicRequired);
    }

    public function isBusinessReady(): bool
    {
        return count($this->businessAcknowledged) === count($this->businessRequired);
    }

    public function isReady(): bool
    {
        return $this->isPublicReady() && $this->isBusinessReady();
    }

    public function reset(): void
    {
        $this->publicAcknowledged = [];
        $this->businessAcknowledged = [];
    }

    /**
     * @param array<array-key, mixed> $arg
     * @param array<string, true>     $required
     */
    private function requiredKey(array $arg, array $required): string
    {
        if (!self::hasExactKeys($arg, ['channel', 'instId'])) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_subscription_invalid');
        }

        $channel = $arg['channel'];
        $instrumentId = $arg['instId'];
        if (!is_string($channel) || !is_string($instrumentId)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_subscription_invalid');
        }

        $key = self::key($channel, $instrumentId);
        if (!isset($required[$key])) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_subscription_invalid');
        }

        return $key;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $keys
     */
    private static function hasExactKeys(array $value, array $keys): bool
    {
        if (count($value) !== count($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }

        return true;
    }

    private static function key(string $channel, string $instrumentId): string
    {
        return $channel."\0".$instrumentId;
    }
}
