<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use App\Common\Enum\Timeframe as TimeframeEnum;
use App\Contract\Indicator\IndicatorEngineInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\MtfValidator\ConditionLoader\ConditionRegistry as MtfConditionRegistry;
use App\MtfValidator\ConditionLoader\TimeframeEvaluator as MtfTimeframeEvaluator;
use App\MtfValidator\Service\Rule\TimeframeRuleEvaluator;
use App\MtfValidator\Service\Rule\YamlRuleEngine;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TimeframeValidationService
{
    public function __construct(
        private readonly TimeframeRuleEvaluator $tfEvaluator,
        private readonly YamlRuleEngine $ruleEngine,
        private readonly ?LoggerInterface $mtfLogger = null,
        private readonly ?MtfTimeframeEvaluator $conditionTimeframeEvaluator = null,
        private readonly ?MtfConditionRegistry $conditionRegistry = null,
        private readonly ?IndicatorEngineInterface $indicatorEngine = null,
        private readonly ?KlineProviderInterface $klineProvider = null,
    ) {
    }

    /**
     * @param array<string,mixed> $mtfConfig
     * @param array<string,mixed> $indicators
     */
    public function validateTimeframe(
        string $symbol,
        string $timeframe,
        string $phase,
        ?string $mode,
        array $mtfConfig,
        array $indicators,
    ): TimeframeDecisionDto {
        $validationConfig = $mtfConfig['validation'] ?? [];
        $rulesConfig      = $mtfConfig['rules'] ?? [];

        /** ---------------------------------------------------------
         * 0) Pas de config pour ce timeframe → signal invalid
         * --------------------------------------------------------- */
        if (!isset($validationConfig['timeframe'][$timeframe])) {
            $decision = new TimeframeDecisionDto(
                timeframe: $timeframe,
                phase: $phase,
                signal: 'invalid',
                valid: false,
                invalidReason: 'NO_CONFIG_FOR_TF',
                rulesPassed: [],
                rulesFailed: [],
                extra: ['indicators' => $indicators],
            );

            $this->logInvalidContextTimeframe($symbol, $timeframe, $phase, $mode, $decision, 'yaml');

            return $decision;
        }

        $decision = null;
        $engine = 'yaml';

        // Si l'engine ConditionRegistry est disponible, on l'utilise en priorité.
        if ($this->canUseConditionRegistryEngine()) {
            try {
                $decision = $this->validateWithConditionRegistry(
                    symbol: $symbol,
                    timeframe: $timeframe,
                    phase: $phase,
                    mode: $mode,
                    mtfConfig: $mtfConfig,
                    indicators: $indicators,
                );
                $engine = 'condition_registry';
            } catch (\Throwable $e) {
                if ($this->mtfLogger) {
                    $this->mtfLogger->error('[MTF] ConditionRegistry timeframe validation failed, falling back to YAML engine', [
                        'symbol'    => $symbol,
                        'timeframe' => $timeframe,
                        'phase'     => $phase,
                        'error'     => $e->getMessage(),
                    ]);
                }
                // Fallback silencieux vers l'ancien moteur YAML.
            }
        }

        if ($decision === null) {
            $decision = $this->validateWithYamlEngine(
                symbol: $symbol,
                timeframe: $timeframe,
                phase: $phase,
                mode: $mode,
                mtfConfig: $mtfConfig,
                indicators: $indicators,
                validationConfig: $validationConfig,
                rulesConfig: $rulesConfig,
            );
            $engine = 'yaml';
        }

        $this->logInvalidContextTimeframe($symbol, $timeframe, $phase, $mode, $decision, $engine);

        return $decision;
    }

    /**
     * Fallback historique : moteur YAML (YamlRuleEngine + TimeframeRuleEvaluator).
     *
     * @param array<string,mixed> $mtfConfig
     * @param array<string,mixed> $validationConfig
     * @param array<string,mixed> $rulesConfig
     * @param array<string,mixed> $indicators
     */
    private function validateWithYamlEngine(
        string $symbol,
        string $timeframe,
        string $phase,
        ?string $mode,
        array $mtfConfig,
        array $indicators,
        array $validationConfig,
        array $rulesConfig,
    ): TimeframeDecisionDto {
        /** ---------------------------------------------------------
         * 1) Évaluer LONG / SHORT
         * --------------------------------------------------------- */
        $isLong = $this->tfEvaluator->evaluateTimeframeSide(
            $timeframe,
            'long',
            $rulesConfig,
            $validationConfig,
            $indicators
        );

        $isShort = $this->tfEvaluator->evaluateTimeframeSide(
            $timeframe,
            'short',
            $rulesConfig,
            $validationConfig,
            $indicators
        );

        // Aucun scénario valide
        if (!$isLong && !$isShort) {
            return new TimeframeDecisionDto(
                timeframe: $timeframe,
                phase: $phase,
                signal: 'invalid',
                valid: false,
                invalidReason: 'NO_LONG_NO_SHORT',
                rulesPassed: [],
                rulesFailed: [],
                extra: ['indicators' => $indicators],
            );
        }

        // Conflit complet
        if ($isLong && $isShort) {
            return new TimeframeDecisionDto(
                timeframe: $timeframe,
                phase: $phase,
                signal: 'invalid',
                valid: false,
                invalidReason: 'LONG_AND_SHORT',
                rulesPassed: [],
                rulesFailed: [],
                extra: ['indicators' => $indicators],
            );
        }

        $signal = $isLong ? 'long' : 'short';


        /** ---------------------------------------------------------
         * 2) FILTRES MANDATORIES
         * --------------------------------------------------------- */
        $filtersMandatory = $mtfConfig['filters_mandatory'] ?? [];

        foreach ($filtersMandatory as $filterBlock) {
            $ok = $this->ruleEngine->evaluate(
                $filterBlock,
                $rulesConfig,
                $indicators,
                $timeframe
            );

            if (!$ok) {
                return new TimeframeDecisionDto(
                    timeframe: $timeframe,
                    phase: $phase,
                    signal: 'invalid',   // signal invalid car veto global
                    valid: false,
                    invalidReason: 'FILTERS_MANDATORY_FAILED',
                    rulesPassed: [],
                    rulesFailed: [$filterBlock],
                    extra: ['indicators' => $indicators],
                );
            }
        }

        /** ---------------------------------------------------------
         * 3) TOUT EST OK → Timeframe VALIDE
         * --------------------------------------------------------- */
        return new TimeframeDecisionDto(
            timeframe: $timeframe,
            phase: $phase,
            signal: $signal,           // long ou short
            valid: true,
            invalidReason: null,
            rulesPassed: [],           // tu pourras les remplir plus tard
            rulesFailed: [],
            extra: ['indicators' => $indicators],
        );
    }

    private function canUseConditionRegistryEngine(): bool
    {
        return $this->conditionTimeframeEvaluator !== null
            && $this->conditionRegistry !== null
            && $this->indicatorEngine !== null
            && $this->klineProvider !== null;
    }

    /**
     * Nouveau moteur basé sur ConditionRegistry + IndicatorEngine.
     *
     * @param array<string,mixed> $mtfConfig
     * @param array<string,mixed> $indicators  Indicateurs "plats" (conservés pour debug)
     */
    private function validateWithConditionRegistry(
        string $symbol,
        string $timeframe,
        string $phase,
        ?string $mode,
        array $mtfConfig,
        array $indicators,
    ): TimeframeDecisionDto {
        if (!$this->canUseConditionRegistryEngine()) {
            throw new \LogicException('ConditionRegistry engine not available');
        }

        // Charger la config courante dans le ConditionRegistry (profil scalper, regular, ...)
        $this->conditionRegistry->load(['mtf_validation' => $mtfConfig]);

        // Récupérer les klines et construire le contexte indicateur complet
        try {
            $tfEnum = TimeframeEnum::from($timeframe);
        } catch (\ValueError) {
            // Timeframe inconnu par l'enum → on marque invalid proprement
            return new TimeframeDecisionDto(
                timeframe: $timeframe,
                phase: $phase,
                signal: 'invalid',
                valid: false,
                invalidReason: 'UNKNOWN_TIMEFRAME',
                rulesPassed: [],
                rulesFailed: [],
                extra: ['indicators' => $indicators],
            );
        }

        $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 250);
        if ($klines === []) {
            return new TimeframeDecisionDto(
                timeframe: $timeframe,
                phase: $phase,
                signal: 'invalid',
                valid: false,
                invalidReason: 'NO_KLINES',
                rulesPassed: [],
                rulesFailed: [],
                extra: ['indicators' => $indicators],
            );
        }

        $context = $this->indicatorEngine->buildContext($symbol, $timeframe, $klines, []);

        // Évaluation LONG / SHORT via TimeframeEvaluator (ConditionRegistry)
        $evaluation = $this->conditionTimeframeEvaluator->evaluate($timeframe, $context);

        $passedLong  = (bool) ($evaluation['passed']['long']  ?? false);
        $passedShort = (bool) ($evaluation['passed']['short'] ?? false);

        $longConditions  = isset($evaluation['long']['conditions']) && \is_array($evaluation['long']['conditions'])
            ? $this->flattenConditions($evaluation['long']['conditions'])
            : [];
        $shortConditions = isset($evaluation['short']['conditions']) && \is_array($evaluation['short']['conditions'])
            ? $this->flattenConditions($evaluation['short']['conditions'])
            : [];

        $this->logTimeframeValidationSummary(
            symbol: $symbol,
            timeframe: $timeframe,
            mode: $mode,
            phase: $phase,
            longPassed: $passedLong,
            shortPassed: $passedShort,
            longConditions: $longConditions,
            shortConditions: $shortConditions,
        );

        // Logging ciblé des règles EMA clés (inputs / outputs)
        $this->logKeyRules($timeframe, $longConditions + $shortConditions);

        // Résolution du signal avant filtres mandatoires
        if (!$passedLong && !$passedShort) {
            $signal = 'invalid';
            $valid = false;
            $invalidReason = 'NO_LONG_NO_SHORT';
        } elseif ($passedLong && $passedShort) {
            $signal = 'invalid';
            $valid = false;
            $invalidReason = 'LONG_AND_SHORT';
        } else {
            $signal = $passedLong ? 'long' : 'short';
            $valid = true;
            $invalidReason = null;
        }

        // Évaluation des filtres globaux obligatoires via ConditionRegistry
        $filtersMandatory = $mtfConfig['filters_mandatory'] ?? [];
        $filterNames = [];
        foreach ($filtersMandatory as $entry) {
            if (\is_string($entry)) {
                $filterNames[] = $entry;
                continue;
            }
            if (\is_array($entry) && $entry !== []) {
                $name = \array_key_first($entry);
                if (\is_string($name)) {
                    $filterNames[] = $name;
                }
            }
        }
        $filterNames = \array_values(\array_unique($filterNames));

        $filtersResults = [];
        $filtersFailed = [];
        if ($filterNames !== []) {
            $filtersResults = $this->conditionRegistry->evaluate($context, $filterNames);
            foreach ($filtersResults as $name => $res) {
                if (!(bool) ($res['passed'] ?? false)) {
                    $filtersFailed[] = $name;
                }
            }

            if ($this->mtfLogger !== null && $phase === 'context') {
                foreach ($filtersResults as $name => $res) {
                    $payload = [
                        'symbol'    => $symbol,
                        'timeframe' => $timeframe,
                        'mode'      => $mode,
                        'filter'    => $name,
                        'passed'    => (bool) ($res['passed'] ?? false),
                        'value'     => $res['value'] ?? null,
                        'threshold' => $res['threshold'] ?? null,
                    ];
                    if (isset($res['meta']) && \is_array($res['meta'])) {
                        $payload['meta'] = $res['meta'];
                    }

                    $this->mtfLogger->info('[MTF] Context filter check', $payload);
                }
            }

            if ($filtersFailed !== []) {
                $valid = false;
                $signal = 'invalid';
                $invalidReason = 'FILTERS_MANDATORY_FAILED';
            }
        }

        // Construction des listes rulesPassed / rulesFailed
        $rulesPassed = [];
        $rulesFailed = [];

        foreach ([$longConditions, $shortConditions] as $collection) {
            foreach ($collection as $name => $node) {
                $passed = (bool) ($node['passed'] ?? false);
                if ($passed) {
                    $rulesPassed[$name] = true;
                } else {
                    $rulesFailed[$name] = true;
                }
            }
        }

        foreach ($filterNames as $name) {
            $passed = isset($filtersResults[$name]) && (bool) ($filtersResults[$name]['passed'] ?? false);
            if ($passed) {
                $rulesPassed[$name] = true;
            } else {
                $rulesFailed[$name] = true;
            }
        }

        return new TimeframeDecisionDto(
            timeframe: $timeframe,
            phase: $phase,
            signal: $signal,
            valid: $valid,
            invalidReason: $invalidReason,
            rulesPassed: \array_keys($rulesPassed),
            rulesFailed: \array_keys($rulesFailed),
            extra: [
                'indicator_context'   => $context,
                'indicators_flat'     => $indicators,
                'conditions_long'     => $longConditions,
                'conditions_short'    => $shortConditions,
                'filters_mandatory'   => $filtersResults,
            ],
        );
    }

    /**
     * Aplatis un arbre de conditions (any_of/all_of) en map name => node.
     *
     * @param array<int,mixed> $nodes
     * @return array<string,array<string,mixed>>
     */
    private function flattenConditions(array $nodes): array
    {
        $collected = [];
        $this->traverseConditionNodes($nodes, $collected);
        return $collected;
    }

    /**
     * @param array<int,mixed>                      $nodes
     * @param array<string,array<string,mixed>>     $collected
     */
    private function traverseConditionNodes(array $nodes, array &$collected): void
    {
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if (isset($node['name']) && \is_string($node['name'])) {
                $collected[$node['name']] = $node;
            }
            if (isset($node['items']) && \is_array($node['items'])) {
                $this->traverseConditionNodes($node['items'], $collected);
            }
        }
    }

    /**
     * Logge les inputs / outputs de règles clés via error_log pour inspection rapide.
     *
     * @param array<string,array<string,mixed>> $conditions
     */
    private function logKeyRules(string $timeframe, array $conditions): void
    {
        $targets = [
            // EMA / régime
            'ema_50_gt_200',
            'ema_50_lt_200',
            'ema_above_200_with_tolerance',
            'ema_above_200_with_tolerance_moderate',
            'ema_below_200_with_tolerance',
            'price_regime_ok_long',
            'price_regime_ok_short',

            // Structure courte terme
            'ema20_over_50_with_tolerance',
            'ema20_over_50_with_tolerance_moderate',
            'ema_20_lt_50',

            // RSI
            'rsi_lt_70',
            'rsi_lt_softcap',
            'rsi_gt_softfloor',
            'rsi_bullish',
            'rsi_bearish',

            // MACD
            'macd_hist_gt_eps',
            'macd_hist_slope_pos',
            'macd_hist_slope_neg',
            'macd_line_above_signal',
            'macd_line_below_signal',
            'macd_line_cross_up_with_hysteresis',
            'macd_line_cross_down_with_hysteresis',

            // ATR / volatilité
            'atr_rel_in_range_15m',
            'atr_rel_in_range_5m',
            'atr_rel_in_range_micro',

            // VWAP / pullback
            'close_above_vwap',
            'close_below_vwap',
            'close_above_vwap_or_ma9',
            'close_above_vwap_and_ma9',
            'close_above_vwap_or_ma9_relaxed',
            'pullback_confirmed',

            // Prix / extensions
            'price_lte_ma21_plus_k_atr',
            'price_below_ma21_plus_2atr',

            // Liquidité
            'volume_ratio_ok',
        ];

        foreach ($conditions as $name => $node) {
            if (!\in_array($name, $targets, true)) {
                continue;
            }

            $payload = [
                'rule'      => $name,
                'timeframe' => $timeframe,
                'result'    => ((bool) ($node['passed'] ?? false)) ? 'PASS' : 'FAIL',
                'value'     => $node['value'] ?? null,
                'threshold' => $node['threshold'] ?? null,
                'meta'      => $node['meta'] ?? [],
                'engine'    => 'ConditionRegistry',
            ];

            if ($this->mtfLogger !== null) {
                $this->mtfLogger->info('[MTF_RULE_DEBUG]', $payload);
            } else {
                @error_log('[MTF_RULE_DEBUG] ' . \json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
        }
    }

    private function logInvalidContextTimeframe(
        string $symbol,
        string $timeframe,
        string $phase,
        ?string $mode,
        TimeframeDecisionDto $decision,
        string $engine
    ): void {
        if ($this->mtfLogger === null) {
            return;
        }

        if ($phase !== 'context') {
            return;
        }

        if ($decision->valid) {
            return;
        }

        $this->mtfLogger->info('[MTF] Context timeframe invalid', [
            'symbol'         => $symbol,
            'timeframe'      => $timeframe,
            'phase'          => $phase,
            'mode'           => $mode,
            'engine'         => $engine,
            'invalid_reason' => $decision->invalidReason,
            'signal'         => $decision->signal,
            'rules_failed'   => $decision->rulesFailed,
            'rules_passed'   => $decision->rulesPassed,
        ]);
    }

    /**
     * @param array<string,array<string,mixed>> $longConditions
     * @param array<string,array<string,mixed>> $shortConditions
     */
    private function logTimeframeValidationSummary(
        string $symbol,
        string $timeframe,
        ?string $mode,
        string $phase,
        bool $longPassed,
        bool $shortPassed,
        array $longConditions,
        array $shortConditions,
    ): void {
        if ($this->mtfLogger === null) {
            return;
        }

        $shouldLogPhase = $phase === 'context';
        $shouldLogMicroTf = \in_array($timeframe, ['5m', '1m'], true);

        if (!$shouldLogPhase && !$shouldLogMicroTf) {
            return;
        }

        $this->mtfLogger->info('[MTF] Timeframe validation detail', [
            'symbol'        => $symbol,
            'timeframe'     => $timeframe,
            'mode'          => $mode,
            'phase'         => $phase,
            'long_passed'   => $longPassed,
            'short_passed'  => $shortPassed,
            'long_rules'    => $this->summarizeConditions($longConditions),
            'short_rules'   => $this->summarizeConditions($shortConditions),
        ]);
    }

    /**
     * @param array<string,array<string,mixed>> $conditions
     * @return array<string,array<string,mixed>>
     */
    private function summarizeConditions(array $conditions): array
    {
        $summary = [];
        foreach ($conditions as $name => $node) {
            $summary[$name] = [
                'passed'    => (bool) ($node['passed'] ?? false),
                'value'     => $node['value'] ?? null,
                'threshold' => $node['threshold'] ?? null,
            ];
        }

        return $summary;
    }
}
