<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto\Mapper;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\MtfValidator\Service\Dto\Internal\InternalMtfRunDto;
use App\MtfValidator\Service\Dto\Internal\InternalRunSummaryDto;

final class ContractMapper
{
    public static function fromContractRequest(
        string $runId,
        MtfRunRequestDto $request,
        ?\DateTimeImmutable $startedAt = null
    ): InternalMtfRunDto {
        return new InternalMtfRunDto(
            runId: $runId,
            symbols: $request->symbols,
            dryRun: $request->dryRun,
            forceRun: $request->forceRun,
            currentTf: $request->currentTf,
            forceTimeframeCheck: $request->forceTimeframeCheck,
            skipContextValidation: $request->skipContextValidation,
            lockPerSymbol: $request->lockPerSymbol,
            startedAt: $startedAt ?? new \DateTimeImmutable(),
            userId: $request->userId,
            ipAddress: $request->ipAddress
        );
    }

    public static function toContractRunDto(InternalMtfRunDto $internal): MtfRunDto
    {
        return new MtfRunDto(
            symbols: $internal->symbols,
            dryRun: $internal->dryRun,
            forceRun: $internal->forceRun,
            currentTf: $internal->currentTf,
            forceTimeframeCheck: $internal->forceTimeframeCheck,
            skipContextValidation: $internal->skipContextValidation,
            lockPerSymbol: $internal->lockPerSymbol
        );
    }

    public static function toInternalSummary(
        InternalMtfRunDto $internal,
        float $executionTimeSeconds,
        int $symbolsProcessed,
        int $symbolsSuccessful,
        int $symbolsFailed,
        int $symbolsSkipped,
        float $successRate,
        string $status,
        ?array $summaryPayload = null
    ): InternalRunSummaryDto {
        $timestamp = self::extractTimestamp($summaryPayload['timestamp'] ?? null) ?? new \DateTimeImmutable();
        $message = is_array($summaryPayload) ? ($summaryPayload['message'] ?? null) : null;

        return new InternalRunSummaryDto(
            runId: $internal->runId,
            executionTimeSeconds: round($executionTimeSeconds, 3),
            symbolsRequested: count($internal->symbols),
            symbolsProcessed: $symbolsProcessed,
            symbolsSuccessful: $symbolsSuccessful,
            symbolsFailed: $symbolsFailed,
            symbolsSkipped: $symbolsSkipped,
            successRate: $successRate,
            dryRun: $internal->dryRun,
            forceRun: $internal->forceRun,
            currentTf: $internal->currentTf,
            timestamp: $timestamp,
            status: $status,
            message: $message
        );
    }

    public static function toContractResponse(
        InternalRunSummaryDto $summary,
        array $results,
        array $errors
    ): MtfRunResponseDto {
        return new MtfRunResponseDto(
            runId: $summary->runId,
            status: $summary->status,
            executionTimeSeconds: $summary->executionTimeSeconds,
            symbolsRequested: $summary->symbolsRequested,
            symbolsProcessed: $summary->symbolsProcessed,
            symbolsSuccessful: $summary->symbolsSuccessful,
            symbolsFailed: $summary->symbolsFailed,
            symbolsSkipped: $summary->symbolsSkipped,
            successRate: $summary->successRate,
            results: $results,
            errors: $errors,
            timestamp: $summary->timestamp,
            message: $summary->message
        );
    }

    private static function extractTimestamp(?string $timestamp): ?\DateTimeImmutable
    {
        if ($timestamp === null) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp);
        if ($parsed !== false) {
            return $parsed;
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return null;
        }
    }
}
