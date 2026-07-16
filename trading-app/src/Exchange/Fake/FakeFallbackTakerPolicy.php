<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeFallbackTakerPolicy
{
    public const VERSION = 'fake-fallback-taker-v1';
    public const VERSION_KEY = 'fake_fallback_policy_version';
    public const ENABLED_KEY = 'fake_fallback_enabled';
    public const ZONE_MIN_KEY = 'fake_fallback_zone_min';
    public const ZONE_MAX_KEY = 'fake_fallback_zone_max';
    public const MAX_SLIPPAGE_BPS_KEY = 'fake_fallback_max_slippage_bps';

    public function __construct(
        public bool $enabled,
        public float $zoneMin,
        public float $zoneMax,
        public float $maxSlippageBps,
    ) {
        if (
            !\is_finite($this->zoneMin)
            || !\is_finite($this->zoneMax)
            || $this->zoneMin <= 0.0
            || $this->zoneMax < $this->zoneMin
        ) {
            throw new \InvalidArgumentException('fake_fallback_zone_invalid');
        }
        if (!\is_finite($this->maxSlippageBps) || $this->maxSlippageBps < 0.0) {
            throw new \InvalidArgumentException('fake_fallback_max_slippage_invalid');
        }
    }

    /**
     * @return array<string, bool|float|string>
     */
    public function toMetadata(): array
    {
        return [
            self::VERSION_KEY => self::VERSION,
            self::ENABLED_KEY => $this->enabled,
            self::ZONE_MIN_KEY => $this->zoneMin,
            self::ZONE_MAX_KEY => $this->zoneMax,
            self::MAX_SLIPPAGE_BPS_KEY => $this->maxSlippageBps,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function fromMetadata(array $metadata): ?self
    {
        if (($metadata[self::VERSION_KEY] ?? null) !== self::VERSION) {
            return null;
        }
        if (!\is_bool($metadata[self::ENABLED_KEY] ?? null)) {
            return null;
        }
        foreach ([self::ZONE_MIN_KEY, self::ZONE_MAX_KEY, self::MAX_SLIPPAGE_BPS_KEY] as $key) {
            if (!\is_int($metadata[$key] ?? null) && !\is_float($metadata[$key] ?? null)) {
                return null;
            }
        }

        try {
            return new self(
                enabled: $metadata[self::ENABLED_KEY],
                zoneMin: (float) $metadata[self::ZONE_MIN_KEY],
                zoneMax: (float) $metadata[self::ZONE_MAX_KEY],
                maxSlippageBps: (float) $metadata[self::MAX_SLIPPAGE_BPS_KEY],
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
