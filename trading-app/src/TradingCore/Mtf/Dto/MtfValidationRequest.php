<?php

declare(strict_types=1);

namespace App\TradingCore\Mtf\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\MtfValidator\Dto\MtfRunDto;

final class MtfValidationRequest
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $profile,
        public readonly ?Exchange $exchange = null,
        public readonly ?MarketType $marketType = null,
        public readonly ?string $requestedTimeframe = null,
        public readonly ?string $direction = null,
        public readonly bool $dryRun = false,
        public readonly bool $forceRun = false,
        public readonly bool $forceTimeframeCheck = false,
        public readonly array $metadata = [],
        ?string $instrument = null,
    ) {
        $this->instrument = $instrument ?? $symbol;
    }

    public readonly string $instrument;

    public static function fromMtfRunDto(MtfRunDto $legacy): self
    {
        $options = $legacy->options;

        return new self(
            symbol: $legacy->symbol,
            profile: $legacy->profile,
            exchange: self::exchangeFrom($options['exchange'] ?? null),
            marketType: self::marketTypeFrom($options['market_type'] ?? null),
            requestedTimeframe: self::nullableString($options['current_tf'] ?? $options['requested_timeframe'] ?? null),
            direction: self::nullableString($options['direction'] ?? $options['side'] ?? null),
            dryRun: $legacy->dryRun,
            forceRun: (bool)($options['force_run'] ?? false),
            forceTimeframeCheck: (bool)($options['force_timeframe_check'] ?? false),
            metadata: [
                'request_id' => $legacy->requestId,
                'mode' => $legacy->mode,
                'now' => $legacy->now?->format(\DateTimeInterface::ATOM),
                'options' => $options,
            ],
        );
    }

    private static function exchangeFrom(mixed $value): ?Exchange
    {
        if ($value instanceof Exchange) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        return Exchange::tryFrom(strtolower($value));
    }

    private static function marketTypeFrom(mixed $value): ?MarketType
    {
        if ($value instanceof MarketType) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        return MarketType::tryFrom(strtolower($value));
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
