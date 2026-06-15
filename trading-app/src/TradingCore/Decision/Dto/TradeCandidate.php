<?php

declare(strict_types=1);

namespace App\TradingCore\Decision\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\TradingCore\Mtf\Dto\MtfValidationResult;

final class TradeCandidate
{
    /**
     * @param array<string,mixed> $entryContext
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $profile,
        public readonly ?Exchange $exchange,
        public readonly ?MarketType $marketType,
        public readonly string $direction,
        public readonly string $executionTimeframe,
        public readonly \DateTimeImmutable $signalTime,
        public readonly MtfValidationResult $validationResult,
        public readonly array $entryContext = [],
        public readonly bool $dryRun = false,
        public readonly array $metadata = [],
        ?string $instrument = null,
    ) {
        $this->instrument = $instrument ?? $symbol;
    }

    public readonly string $instrument;
}
