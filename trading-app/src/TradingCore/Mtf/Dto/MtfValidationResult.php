<?php

declare(strict_types=1);

namespace App\TradingCore\Mtf\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

final class MtfValidationResult
{
    /**
     * @param ValidatedTimeframe[] $validatedTimeframes
     * @param ValidatedTimeframe[] $rejectedTimeframes
     * @param string[]             $rejectedBy
     * @param array<string,mixed>  $rawLegacyPayload
     * @param array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $profile,
        public readonly ?Exchange $exchange = null,
        public readonly ?MarketType $marketType = null,
        public readonly string $status = 'REJECTED',
        public readonly ?string $direction = null,
        public readonly ?string $executionTimeframe = null,
        public readonly array $validatedTimeframes = [],
        public readonly array $rejectedTimeframes = [],
        public readonly array $rejectedBy = [],
        public readonly ?float $score = null,
        public readonly ?float $confidence = null,
        public readonly array $rawLegacyPayload = [],
        public readonly array $metadata = [],
        ?string $instrument = null,
    ) {
        $this->instrument = $instrument ?? $symbol;
    }

    public readonly string $instrument;

    public function isReady(): bool
    {
        return strtoupper($this->status) === 'READY';
    }
}
