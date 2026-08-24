<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Market;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final class PaperMarketEffectCodec
{
    private const SCHEMA_VERSION = 1;

    /** @return array<string, mixed> */
    public function encode(PaperMarketEvent $event): array
    {
        $payload = $event->toArray();

        return [
            'effect_type' => 'market_event',
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $payload,
            'payload_checksum' => hash('sha256', CanonicalJson::encode($payload)),
        ];
    }

    /** @param array<string, mixed> $encoded */
    public function supports(array $encoded): bool
    {
        return ($encoded['effect_type'] ?? null) === 'market_event';
    }

    /** @param array<string, mixed> $encoded */
    public function decode(array $encoded): PaperMarketEvent
    {
        try {
            $actualKeys = array_keys($encoded);
            $expectedKeys = ['effect_type', 'schema_version', 'payload', 'payload_checksum'];
            sort($actualKeys, SORT_STRING);
            sort($expectedKeys, SORT_STRING);

            if ($actualKeys !== $expectedKeys
                || !$this->supports($encoded)
                || $encoded['schema_version'] !== self::SCHEMA_VERSION
                || !is_array($encoded['payload'])
                || !is_string($encoded['payload_checksum'])
                || !hash_equals(hash('sha256', CanonicalJson::encode($encoded['payload'])), $encoded['payload_checksum'])
            ) {
                throw new \InvalidArgumentException();
            }

            return PaperMarketEvent::fromArray($encoded['payload']);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('paper_market_effect_payload_invalid');
        }
    }
}
