<?php

declare(strict_types=1);

namespace App\TradingCore\Decision\Mapper;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\TradingCore\Decision\Dto\TradeCandidate;
use App\TradingCore\Mtf\Dto\MtfValidationResult;
use App\TradingCore\Mtf\Mapper\MtfValidationResultMapper;

final class TradeCandidateMapper
{
    public function __construct(
        private readonly MtfValidationResultMapper $validationResultMapper = new MtfValidationResultMapper(),
    ) {
    }

    /**
     * @param array<string,mixed> $entryContext
     * @param array<string,mixed> $metadata
     */
    public function fromValidationResult(
        MtfValidationResult $validationResult,
        bool $dryRun,
        ?\DateTimeImmutable $signalTime = null,
        array $entryContext = [],
        array $metadata = [],
    ): ?TradeCandidate {
        if (!$validationResult->isReady()) {
            return null;
        }

        if ($validationResult->direction === null || $validationResult->executionTimeframe === null) {
            return null;
        }

        return new TradeCandidate(
            symbol: $validationResult->symbol,
            profile: $validationResult->profile,
            exchange: $validationResult->exchange,
            marketType: $validationResult->marketType,
            direction: $validationResult->direction,
            executionTimeframe: $validationResult->executionTimeframe,
            signalTime: $signalTime ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            validationResult: $validationResult,
            entryContext: $entryContext,
            dryRun: $dryRun,
            metadata: $metadata,
            instrument: $validationResult->instrument,
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function fromSymbolResult(
        SymbolResultDto $symbolResult,
        string $profile,
        ?Exchange $exchange,
        ?MarketType $marketType,
        bool $dryRun,
        ?\DateTimeImmutable $signalTime = null,
        array $metadata = [],
    ): ?TradeCandidate {
        $legacyPayload = $symbolResult->toArray();
        $legacyPayload['profile'] = $profile;
        $legacyPayload['exchange'] = $exchange?->value;
        $legacyPayload['market_type'] = $marketType?->value;

        $validationResult = $this->validationResultMapper->fromLegacyPayload(
            payload: $legacyPayload,
            metadata: $metadata,
        );

        return $this->fromValidationResult(
            validationResult: $validationResult,
            dryRun: $dryRun,
            signalTime: $signalTime,
            entryContext: $this->entryContextFromSymbolResult($symbolResult),
            metadata: $metadata,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function entryContextFromSymbolResult(SymbolResultDto $symbolResult): array
    {
        $entryContext = [];

        if ($symbolResult->currentPrice !== null) {
            $entryContext['current_price'] = $symbolResult->currentPrice;
        }

        if ($symbolResult->atr !== null) {
            $entryContext['atr'] = $symbolResult->atr;
        }

        return $entryContext;
    }
}
