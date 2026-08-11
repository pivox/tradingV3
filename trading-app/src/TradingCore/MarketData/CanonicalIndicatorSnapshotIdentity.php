<?php

declare(strict_types=1);

namespace App\TradingCore\MarketData;

final readonly class CanonicalIndicatorSnapshotIdentity
{
    private const KEYS = ['timeframe', 'symbol', 'exchange', 'environment', 'market_type'];

    public function __construct(
        public string $timeframe,
        public string $symbol,
        public string $exchange,
        public string $environment,
        public string $marketType,
    ) {
        foreach ($this->toArray() as $value) {
            if ($value === '' || trim($value) !== $value) {
                throw new \InvalidArgumentException('canonical_indicator_snapshot_identity_invalid');
            }
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function tryFromArray(array $data): ?self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        $expected = self::KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            return null;
        }
        foreach (self::KEYS as $key) {
            if (!\is_string($data[$key]) || $data[$key] === '') {
                return null;
            }
        }

        try {
            return new self(
                $data['timeframe'],
                $data['symbol'],
                $data['exchange'],
                $data['environment'],
                $data['market_type'],
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function matches(
        string $timeframe,
        string $symbol,
        string $exchange,
        string $environment,
        string $marketType,
    ): bool {
        return $this->timeframe === $timeframe
            && $this->symbol === $symbol
            && $this->exchange === $exchange
            && $this->environment === $environment
            && $this->marketType === $marketType;
    }

    /** @return array{timeframe:string,symbol:string,exchange:string,environment:string,market_type:string} */
    public function toArray(): array
    {
        return [
            'timeframe' => $this->timeframe,
            'symbol' => $this->symbol,
            'exchange' => $this->exchange,
            'environment' => $this->environment,
            'market_type' => $this->marketType,
        ];
    }
}
