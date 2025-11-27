<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Config\MtfValidationConfigProvider;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\ExecutionSelectionDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

class MtfValidatorCoreService
{
    public function __construct(
        private readonly MtfValidationConfigProvider $configProvider,
        private readonly IndicatorProviderInterface $indicatorProvider,
        private readonly ContextValidationService $contextValidationService,
        private readonly ExecutionSelectionService $executionSelectionService,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $mtfLogger,
    ) {
    }

    public function validate(MtfRunDto $input): MtfResultDto
    {
        $now = $input->now ?? $this->clock->now();

        // 1. Config
        $rawConfig = $this->configProvider->getConfigForProfile($input->profile);
        $mtfConfig = $rawConfig['mtf_validation'] ?? $rawConfig;

        $mode = $input->mode ?? ($mtfConfig['mode'] ?? null);

        // 2. Timeframes
        $contextTimeframes   = $this->resolveContextTimeframes($mtfConfig);
        $executionTimeframes = $this->resolveExecutionTimeframes($mtfConfig, $contextTimeframes);

        $allTimeframes = \array_values(\array_unique(\array_merge(
            $contextTimeframes,
            $executionTimeframes,
        )));

        if (empty($allTimeframes)) {
            $this->mtfLogger->warning('MTF config has no timeframes', [
                'symbol'  => $input->symbol,
                'profile' => $input->profile,
            ]);

            $result = $this->buildEmptyResult(
                input: $input,
                mode: $mode,
                now: $now,
                reason: 'no_timeframes_in_config',
            );

            $this->auditResult($input, $result, 'MTF_EMPTY_RESULT');

            return $result;
        }

        // 3. Indicateurs
        $indicatorsByTimeframe = $this->indicatorProvider->getIndicatorsForSymbolAndTimeframes(
            $input->symbol,
            $allTimeframes,
            $now,
        );

        // 4. Contexte
        $contextDecision = $this->contextValidationService->validateContext(
            symbol: $input->symbol,
            mode: $mode,
            contextTimeframes: $contextTimeframes,
            mtfConfig: $mtfConfig,
            indicatorsByTimeframe: $indicatorsByTimeframe,
        );

        if (!$contextDecision->isValid) {
            $result = $this->buildResultContextKo(
                input: $input,
                mode: $mode,
                now: $now,
                contextDecision: $contextDecision,
            );

            $this->mtfLogger->info('MTF context invalid', [
                'symbol'             => $input->symbol,
                'profile'            => $input->profile,
                'mode'               => $mode,
                'reason'             => $contextDecision->reasonIfInvalid,
                'context_timeframes' => $contextTimeframes,
            ]);

            $this->auditResult($input, $result, 'MTF_CONTEXT_KO');

            return $result;
        }

        // 5. Exécution
        $executionSelection = $this->executionSelectionService->selectExecutionTimeframe(
            symbol: $input->symbol,
            mode: $mode,
            executionTimeframes: $executionTimeframes,
            mtfConfig: $mtfConfig,
            indicatorsByTimeframe: $indicatorsByTimeframe,
            contextDecision: $contextDecision,
        );

        $result = $this->buildResultExecution(
            input: $input,
            mode: $mode,
            now: $now,
            contextDecision: $contextDecision,
            executionSelection: $executionSelection,
        );

        $this->auditResult($input, $result, 'MTF_EXECUTION_RESULT');

        return $result;
    }

    /**
     * @param array<string,mixed> $mtfConfig
     * @return string[]
     */
    private function resolveContextTimeframes(array $mtfConfig): array
    {
        $context = $mtfConfig['context_timeframes'] ?? null;

        if (\is_string($context)) {
            $context = [$context];
        }

        if (\is_array($context) && !empty($context)) {
            /** @var string[] $context */
            return $context;
        }

        $validationTimeframes = \array_keys($mtfConfig['validation']['timeframe'] ?? []);
        if (!empty($validationTimeframes)) {
            /** @var string[] $validationTimeframes */
            return $validationTimeframes;
        }

        return ['4h', '1h'];
    }

    /**
     * @param array<string,mixed> $mtfConfig
     * @param string[]            $contextTimeframes
     * @return string[]
     */
    private function resolveExecutionTimeframes(array $mtfConfig, array $contextTimeframes): array
    {
        $execution = $mtfConfig['execution_timeframes'] ?? null;

        if (\is_string($execution)) {
            $execution = [$execution];
        }

        if (\is_array($execution) && !empty($execution)) {
            /** @var string[] $execution */
            return $execution;
        }

        $validationTimeframes = \array_keys($mtfConfig['validation']['timeframe'] ?? []);
        if (!empty($validationTimeframes)) {
            $executionDerived = \array_values(\array_diff($validationTimeframes, $contextTimeframes));
            if (!empty($executionDerived)) {
                /** @var string[] $executionDerived */
                return $executionDerived;
            }
        }

        return ['15m', '5m', '1m'];
    }

    private function buildEmptyResult(
        MtfRunDto $input,
        ?string $mode,
        \DateTimeImmutable $now,
        string $reason,
    ): MtfResultDto {
        $emptyContext = new ContextDecisionDto(
            isValid: false,
            reasonIfInvalid: $reason,
            timeframeDecisions: [],
        );

        $emptyExecution = new ExecutionSelectionDto(
            selectedTimeframe: null,
            selectedSide: null,
            reasonIfNone: $reason,
            timeframeDecisions: [],
        );

        return new MtfResultDto(
            symbol: $input->symbol,
            profile: $input->profile,
            mode: $mode,
            evaluatedAt: $now,
            isTradable: false,
            side: null,
            executionTimeframe: null,
            context: $emptyContext,
            execution: $emptyExecution,
            finalReason: $reason,
            extra: [
                'request_id' => $input->requestId,
                'options'    => $input->options,
            ],
        );
    }

    private function buildResultContextKo(
        MtfRunDto $input,
        ?string $mode,
        \DateTimeImmutable $now,
        ContextDecisionDto $contextDecision,
    ): MtfResultDto {
        $emptyExecution = new ExecutionSelectionDto(
            selectedTimeframe: null,
            selectedSide: null,
            reasonIfNone: 'context_invalid',
            timeframeDecisions: [],
        );

        return new MtfResultDto(
            symbol: $input->symbol,
            profile: $input->profile,
            mode: $mode,
            evaluatedAt: $now,
            isTradable: false,
            side: null,
            executionTimeframe: null,
            context: $contextDecision,
            execution: $emptyExecution,
            finalReason: $contextDecision->reasonIfInvalid ?? 'context_invalid',
            extra: [
                'request_id' => $input->requestId,
                'options'    => $input->options,
            ],
        );
    }

    private function buildResultExecution(
        MtfRunDto $input,
        ?string $mode,
        \DateTimeImmutable $now,
        ContextDecisionDto $contextDecision,
        ExecutionSelectionDto $executionSelection,
    ): MtfResultDto {
        $isTradable = $executionSelection->selectedTimeframe !== null
            && $executionSelection->selectedSide !== null;

        return new MtfResultDto(
            symbol: $input->symbol,
            profile: $input->profile,
            mode: $mode,
            evaluatedAt: $now,
            isTradable: $isTradable,
            side: $executionSelection->selectedSide,
            executionTimeframe: $executionSelection->selectedTimeframe,
            context: $contextDecision,
            execution: $executionSelection,
            finalReason: $isTradable
                ? null
                : ($executionSelection->reasonIfNone ?? 'no_execution_timeframe_selected'),
            extra: [
                'request_id' => $input->requestId,
                'options'    => $input->options,
            ],
        );
    }

    /**
     * Centralise la façon dont on loggue un résultat MTF via l'AuditLogger.
     */
    private function auditResult(MtfRunDto $input, MtfResultDto $result, string $eventType): void
    {
        // On utilise l'API existante: logAction(action, entity, entityId, data, userId, ipAddress)
        $data = [
            'symbol'        => $result->symbol,
            'profile'       => $result->profile,
            'mode'          => $result->mode,
            'is_tradable'   => $result->isTradable,
            'side'          => $result->side,
            'execution_tf'  => $result->executionTimeframe,
            'final_reason'  => $result->finalReason,
            'extra'         => $result->extra,
        ];

        $this->auditLogger->logAction(
            action: $eventType,
            entity: 'MTF_VALIDATION',
            entityId: $result->symbol,
            data: $data,
            userId: $input->options['user_id'] ?? null,
            ipAddress: $input->options['ip_address'] ?? null,
        );
    }
}
