<?php

declare(strict_types=1);

namespace App\TradingCore\Mtf\Mapper;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use App\TradingCore\Mtf\Dto\MtfRejectionReason;
use App\TradingCore\Mtf\Dto\MtfValidationResult;
use App\TradingCore\Mtf\Dto\ValidatedTimeframe;

final class MtfValidationResultMapper
{
    /**
     * @param array<string,mixed>      $metadata
     * @param array<string,mixed>|null $rawLegacyPayload
     */
    public function fromMtfResult(
        MtfResultDto $result,
        ?Exchange $exchange = null,
        ?MarketType $marketType = null,
        array $metadata = [],
        ?array $rawLegacyPayload = null,
    ): MtfValidationResult {
        $validatedTimeframes = [];
        $rejectedTimeframes = [];
        $rejectedBy = [];
        $timeframeRejectedBy = [];

        foreach ($this->collectTimeframeDecisions($result) as $decision) {
            $mapped = $this->mapTimeframeDecision($decision);
            if ($mapped->valid) {
                $validatedTimeframes[] = $mapped;
                continue;
            }

            $rejectedTimeframes[] = $mapped;
            if ($mapped->rejectionReason !== null) {
                $timeframeRejectedBy[] = $mapped->rejectionReason->reason;
            }
        }

        if (!$result->isTradable) {
            $rejectedBy[] = $result->finalReason;
            $rejectedBy[] = $result->context->reasonIfInvalid;
            $rejectedBy[] = $result->execution->reasonIfNone;
        }
        $rejectedBy = [...$rejectedBy, ...$timeframeRejectedBy];

        return new MtfValidationResult(
            symbol: $result->symbol,
            profile: $result->profile,
            exchange: $exchange,
            marketType: $marketType,
            status: $result->isTradable ? 'READY' : 'REJECTED',
            direction: $this->nullableString($result->side),
            executionTimeframe: $this->nullableString($result->executionTimeframe),
            validatedTimeframes: $validatedTimeframes,
            rejectedTimeframes: $rejectedTimeframes,
            rejectedBy: $this->uniqueStrings($rejectedBy),
            score: $this->nullableFloat($result->extra['score'] ?? null),
            confidence: $this->nullableFloat($result->extra['confidence'] ?? null),
            rawLegacyPayload: $rawLegacyPayload ?? $result->toArray(),
            metadata: $metadata,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    public function fromLegacyPayload(array $payload, array $metadata = []): MtfValidationResult
    {
        $status = $this->statusFromLegacyPayload($payload);
        $direction = $this->firstString($payload, ['signal_side', 'side', 'direction']);
        if ($direction !== null && strtoupper($direction) === 'NONE') {
            $direction = null;
        }

        return new MtfValidationResult(
            symbol: $this->firstString($payload, ['symbol', 'instrument']) ?? '',
            profile: $this->firstString($payload, ['profile', 'mtf_profile']) ?? $this->nestedString($payload, ['context', 'profile']) ?? '',
            exchange: $this->exchangeFrom($payload['exchange'] ?? null),
            marketType: $this->marketTypeFrom($payload['market_type'] ?? null),
            status: $status,
            direction: $direction,
            executionTimeframe: $this->firstString($payload, ['execution_tf', 'executionTimeframe', 'execution_timeframe']),
            validatedTimeframes: $this->timeframesFromLegacyPayload($payload, true),
            rejectedTimeframes: $this->timeframesFromLegacyPayload($payload, false),
            rejectedBy: $this->rejectedByFromPayload($payload, $status),
            score: $this->nullableFloat($payload['score'] ?? null),
            confidence: $this->nullableFloat($payload['confidence'] ?? null),
            rawLegacyPayload: $payload,
            metadata: $metadata,
            instrument: $this->firstString($payload, ['instrument', 'symbol']),
        );
    }

    /**
     * @return TimeframeDecisionDto[]
     */
    private function collectTimeframeDecisions(MtfResultDto $result): array
    {
        return [
            ...$this->typedTimeframeDecisions($result->context->timeframeDecisions),
            ...$this->typedTimeframeDecisions($result->execution->timeframeDecisions),
        ];
    }

    /**
     * @param mixed[] $decisions
     * @return TimeframeDecisionDto[]
     */
    private function typedTimeframeDecisions(array $decisions): array
    {
        return array_values(array_filter(
            $decisions,
            static fn (mixed $decision): bool => $decision instanceof TimeframeDecisionDto,
        ));
    }

    private function mapTimeframeDecision(TimeframeDecisionDto $decision): ValidatedTimeframe
    {
        $rejectionReason = null;
        if (!$decision->valid) {
            $reason = $decision->invalidReason ?? 'timeframe_rejected';
            $rejectionReason = new MtfRejectionReason(
                reason: $reason,
                timeframe: $decision->timeframe,
                phase: $decision->phase,
                rulesFailed: $this->strings($decision->rulesFailed),
                metadata: $decision->extra,
            );
        }

        return new ValidatedTimeframe(
            timeframe: $decision->timeframe,
            phase: $decision->phase,
            signal: $decision->signal,
            valid: $decision->valid,
            rejectionReason: $rejectionReason,
            rulesPassed: $this->strings($decision->rulesPassed),
            rulesFailed: $this->strings($decision->rulesFailed),
            metadata: $decision->extra,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return ValidatedTimeframe[]
     */
    private function timeframesFromLegacyPayload(array $payload, bool $valid): array
    {
        // SymbolResultDto::toArray() nests decisions under context.context / context.execution.
        // Flat legacy payloads use context.timeframe_decisions / execution.timeframe_decisions.
        // Both paths are mutually exclusive so collecting all four produces no duplicates.
        $decisions = [
            ...$this->legacyDecisionsFrom($payload['context']['timeframe_decisions'] ?? null),
            ...$this->legacyDecisionsFrom($payload['context']['context']['timeframe_decisions'] ?? null),
            ...$this->legacyDecisionsFrom($payload['execution']['timeframe_decisions'] ?? null),
            ...$this->legacyDecisionsFrom($payload['context']['execution']['timeframe_decisions'] ?? null),
        ];

        $timeframes = [];
        foreach ($decisions as $decision) {
            $decisionValid = (bool)($decision['valid'] ?? false);
            if ($decisionValid !== $valid) {
                continue;
            }

            $timeframe = $this->nullableString($decision['timeframe'] ?? null);
            if ($timeframe === null) {
                continue;
            }

            $reason = $this->nullableString($decision['invalid_reason'] ?? null);
            $rejectionReason = $decisionValid || $reason === null
                ? null
                : new MtfRejectionReason(
                    reason: $reason,
                    timeframe: $timeframe,
                    phase: $this->nullableString($decision['phase'] ?? null),
                    rulesFailed: $this->strings($decision['rules_failed'] ?? []),
                    metadata: \is_array($decision['extra'] ?? null) ? $decision['extra'] : [],
                );

            $timeframes[] = new ValidatedTimeframe(
                timeframe: $timeframe,
                phase: $this->nullableString($decision['phase'] ?? null) ?? 'unknown',
                signal: $this->nullableString($decision['signal'] ?? null) ?? 'unknown',
                valid: $decisionValid,
                rejectionReason: $rejectionReason,
                rulesPassed: $this->strings($decision['rules_passed'] ?? []),
                rulesFailed: $this->strings($decision['rules_failed'] ?? []),
                metadata: \is_array($decision['extra'] ?? null) ? $decision['extra'] : [],
            );
        }

        return $timeframes;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function legacyDecisionsFrom(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $decision): bool => \is_array($decision),
        ));
    }

    /**
     * @param array<string,mixed> $payload
     * @return string[]
     */
    private function rejectedByFromPayload(array $payload, string $status): array
    {
        $rejectedBy = [];

        if (\is_array($payload['rejected_by'] ?? null)) {
            $rejectedBy = $payload['rejected_by'];
        }

        if ($status !== 'READY') {
            $rejectedBy[] = $payload['reason'] ?? null;
            $rejectedBy[] = $payload['finalReason'] ?? null;
            $rejectedBy[] = $payload['final_reason'] ?? null;
        }

        return $this->uniqueStrings($rejectedBy);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function statusFromLegacyPayload(array $payload): string
    {
        $status = $this->nullableString($payload['status'] ?? null);
        if ($status !== null) {
            $upper = strtoupper($status);
            // SUCCESS and COMPLETED are legacy success statuses (SymbolResultDto normalizes SUCCESS → COMPLETED).
            // Normalize both to READY so isReady() and TradeCandidateMapper work correctly.
            if (in_array($upper, ['READY', 'SUCCESS', 'COMPLETED'], true)) {
                return 'READY';
            }

            return 'REJECTED';
        }

        if (($payload['isTradable'] ?? $payload['is_tradable'] ?? false) === true) {
            return 'READY';
        }

        return 'REJECTED';
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[]            $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nullableString($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[]            $path
     */
    private function nestedString(array $payload, array $path): ?string
    {
        $cursor = $payload;
        foreach ($path as $key) {
            if (!\is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }

            $cursor = $cursor[$key];
        }

        return $this->nullableString($cursor);
    }

    private function exchangeFrom(mixed $value): ?Exchange
    {
        if ($value instanceof Exchange) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        return Exchange::tryFrom(strtolower($value));
    }

    private function marketTypeFrom(mixed $value): ?MarketType
    {
        if ($value instanceof MarketType) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        return MarketType::tryFrom(strtolower($value));
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param mixed[] $values
     * @return string[]
     */
    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique($this->strings($values)));
    }

    /**
     * @param mixed[] $values
     * @return string[]
     */
    private function strings(array $values): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $value): ?string => \is_string($value) && $value !== '' ? $value : null,
                $values,
            ),
            static fn (?string $value): bool => $value !== null,
        ));
    }
}
