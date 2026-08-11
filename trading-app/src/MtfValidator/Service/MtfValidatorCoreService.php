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
use App\Indicator\Exception\NotEnoughKlinesException;
use App\MtfValidator\Policy\CanonicalMtfPolicyPreflight;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Provider\Context\ExchangeContext;
use App\MtfValidator\Service\Execution\ExecutionSelectorMetrics;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

class MtfValidatorCoreService
{
    public function __construct(
        private readonly CanonicalMtfPolicyPreflight $canonicalPolicyPreflight,
        private readonly MtfValidationConfigProvider $configProvider,
        private readonly IndicatorProviderInterface $indicatorProvider,
        private readonly ContextValidationService $contextValidationService,
        private readonly ExecutionSelectionService $executionSelectionService,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $mtfLogger,
        private readonly MtfTimeframeResolver $timeframeResolver,
        private readonly ?CanonicalSetupRuleRuntime $canonicalRuleRuntime = null,
    ) {
    }

    public function validate(MtfRunDto $input): MtfResultDto
    {
        $now = $input->now ?? $this->clock->now();
        $canonicalRejection = $this->rejectBlockedCanonicalRun($input, $now);
        if ($canonicalRejection !== null) {
            return $canonicalRejection;
        }
        $exchangeContext = ExchangeContext::fromArray($input->options);

        // 1. Config
        $rawConfig = $this->configProvider->getConfigForProfile($input->profile);
        $mtfConfig = $rawConfig['mtf_validation'] ?? $rawConfig;

        $mode = $input->mode ?? ($mtfConfig['mode'] ?? null);

        // 2. Timeframes
        $contextTimeframes   = $this->timeframeResolver->resolveContext($mtfConfig);
        $executionTimeframes = $this->timeframeResolver->resolveExecution($mtfConfig, $contextTimeframes);

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
        try {
            $indicatorsByTimeframe = $this->indicatorProvider->getIndicatorsForSymbolAndTimeframes(
                $input->symbol,
                $allTimeframes,
                $now,
                $exchangeContext,
                $input->lineageContext?->isModern() === true
                    ? $input->lineageContext->environment
                    : null,
            );
        } catch (NotEnoughKlinesException $e) {
            $this->mtfLogger->info('MTF not enough klines', [
                'symbol'             => $input->symbol,
                'exchange'           => $exchangeContext->exchange->value,
                'market_type'         => $exchangeContext->marketType->value,
                'profile'            => $input->profile,
                'mode'               => $mode,
                'timeframe'          => $e->getTimeframe(),
                'required'           => $e->getRequired(),
                'actual'             => $e->getActual(),
            ]);

            $result = $this->buildEmptyResult(
                input: $input,
                mode: $mode,
                now: $now,
                reason: 'not_enough_klines',
            );

            $this->auditResult($input, $result, 'MTF_NOT_ENOUGH_KLINES');

            return $result;
        }

        // Canonical modern requests have exactly one rule authority. They never
        // continue into ConditionRegistry/YAML, even when strict evaluation fails.
        if ($input->lineageContext?->isModern()) {
            if ($this->canonicalRuleRuntime === null) {
                $result = $this->buildEmptyResult(
                    input: $input,
                    mode: $mode,
                    now: $now,
                    reason: 'canonical_strict_rule_runtime_unavailable',
                );
                $this->auditResult($input, $result, 'MTF_CANONICAL_RULE_RUNTIME_UNAVAILABLE');

                return $result;
            }
            $strict = $this->canonicalRuleRuntime->evaluate($input->lineageContext, $indicatorsByTimeframe, $now);
            $reason = $strict->passed
                ? 'canonical_execution_projection_pending_306'
                : 'canonical_rule_rejected:' . $strict->reasonCode;
            $result = $this->buildEmptyResult(
                input: $input,
                mode: $mode,
                now: $now,
                reason: $reason,
                extra: ['canonical_rule_trace' => $strict->trace],
            );
            $this->auditResult($input, $result, $strict->passed ? 'MTF_CANONICAL_EXECUTION_PENDING' : 'MTF_CANONICAL_RULE_REJECTED');

            return $result;
        }

        // 4. Contexte
        $contextDecision = $this->contextValidationService->validateContext(
            symbol: $input->symbol,
            mode: $mode,
            contextTimeframes: $contextTimeframes,
            mtfConfig: $mtfConfig,
            indicatorsByTimeframe: $indicatorsByTimeframe,
            exchangeContext: $exchangeContext,
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
            exchangeContext: $exchangeContext,
            lineageContext: $input->lineageContext,
            selectorMetrics: $input->lineageContext?->isModern()
                ? new ExecutionSelectorMetrics($input->lineageContext, $indicatorsByTimeframe)
                : null,
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

    /** @param array<string,mixed> $extra */
    private function buildEmptyResult(
        MtfRunDto $input,
        ?string $mode,
        \DateTimeImmutable $now,
        string $reason,
        array $extra = [],
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
            ] + $extra,
        );
    }

    private function rejectBlockedCanonicalRun(MtfRunDto $input, \DateTimeImmutable $now): ?MtfResultDto
    {
        $identity = $input->lineageContext;
        if ($identity === null) {
            return null;
        }

        $rejection = $this->canonicalPolicyPreflight->reject($identity);
        if ($rejection === null) {
            return null;
        }

        $reason = $rejection->reason;
        $blockers = $rejection->blockers;
        $this->mtfLogger->warning('mtf.canonical_policy_rejected', [
            'symbol' => $input->symbol,
            'profile' => $input->profile,
            'reason' => $reason,
            'blockers' => $blockers,
            'identity' => $identity->redacted(),
        ]);
        $result = $this->buildEmptyResult(
            input: $input,
            mode: $identity->modeId,
            now: $now,
            reason: $reason,
            extra: [
                'canonical_status' => 'canonical_policy_rejected',
                'canonical_policy_blockers' => $blockers,
            ],
        );
        $this->auditResult($input, $result, 'MTF_CANONICAL_POLICY_REJECTED');

        return $result;
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
