<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use App\Common\Enum\Timeframe as TimeframeEnum;
use App\Contract\Indicator\IndicatorEngineInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\MtfValidator\ConditionLoader\ConditionRegistry as MtfConditionRegistry;
use App\MtfValidator\ConditionLoader\TimeframeEvaluator as MtfTimeframeEvaluator;
use App\MtfValidator\Service\Rule\TimeframeRuleEvaluator;
use App\MtfValidator\Service\Rule\YamlRuleEngine;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TimeframeValidationService
{
    private const CONDITION_REGISTRY_KLINE_WINDOW_SIZE = 250;

    public function __construct(
        private readonly TimeframeRuleEvaluator $tfEvaluator,
        private readonly YamlRuleEngine $ruleEngine,
        private readonly ?LoggerInterface $mtfLogger = null,
        private readonly ?MtfTimeframeEvaluator $conditionTimeframeEvaluator = null,
        private readonly ?MtfConditionRegistry $conditionRegistry = null,
        private readonly ?IndicatorEngineInterface $indicatorEngine = null,
        private readonly ?KlineProviderInterface $klineProvider = null,
        private readonly ?MainProviderInterface $mainProvider = null,
        private readonly ?MtfValidationEngineMetrics $validationEngineMetrics = null,
        private readonly ?ClockInterface $clock = null,
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
        $fallbackContext = null;

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

                $fallbackCount = $this->validationEngineMetrics?->recordConditionRegistryFallback(
                    symbol: $symbol,
                    timeframe: $timeframe,
                    phase: $phase,
                    mode: $mode,
                    error: $e,
                );
                $fallbackContext = [
                    'metric' => MtfValidationEngineMetrics::CONDITION_REGISTRY_FALLBACK_COUNT,
                    'fallback_count' => $fallbackCount,
                    'source_engine' => 'condition_registry',
                    'fallback_engine' => 'yaml',
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ];
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

            if ($fallbackContext !== null) {
                $decision = $this->withValidationEngineFallback($decision, $fallbackContext);
            }
        }

        $this->logInvalidContextTimeframe($symbol, $timeframe, $phase, $mode, $decision, $engine);

        return $decision;
    }

    /**
     * @param array<string,mixed> $fallbackContext
     */
    private function withValidationEngineFallback(
        TimeframeDecisionDto $decision,
        array $fallbackContext,
    ): TimeframeDecisionDto {
        return new TimeframeDecisionDto(
            timeframe: $decision->timeframe,
            phase: $decision->phase,
            signal: $decision->signal,
            valid: $decision->valid,
            invalidReason: $decision->invalidReason,
            rulesPassed: $decision->rulesPassed,
            rulesFailed: $decision->rulesFailed,
            extra: $decision->extra + ['validation_engine_fallback' => $fallbackContext],
        );
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

        $klines = $this->fetchClosedKlinesForConditionRegistry($symbol, $tfEnum);
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

        // Enrichissement microstructure (spread / OFI) pour les gardes 1m.
        // Best-effort: si indisponible (provider non injecté ou erreur API), on laisse la condition gérer missing_data.
        $this->enrichMicrostructureContext(symbol: $symbol, timeframe: $timeframe, phase: $phase, context: $context);

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
            'macd_hist_increasing_n',
            'macd_hist_decreasing_n',
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
            'near_vwap',
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

    /**
     * @param array<string,mixed> $context
     */
    private function enrichMicrostructureContext(string $symbol, string $timeframe, string $phase, array &$context): void
    {
        if ($phase !== 'execution') {
            return;
        }

        if ($timeframe !== '1m') {
            return;
        }

        if ($this->mainProvider === null) {
            return;
        }

        // Déjà fourni par une autre couche → ne pas écraser.
        $hasSpread = \array_key_exists('spread_bps', $context) && \is_numeric($context['spread_bps']);
        $hasOfi = \array_key_exists('order_flow_imbalance', $context) && \is_numeric($context['order_flow_imbalance']);
        if ($hasSpread && $hasOfi) {
            return;
        }

        try {
            $orderBook = $this->mainProvider->getContractProvider()->getOrderBook($symbol, 20);
        } catch (\Throwable) {
            return;
        }

        if (!\is_array($orderBook)) {
            return;
        }

        $bids = $orderBook['bids'] ?? null;
        $asks = $orderBook['asks'] ?? null;
        if (!\is_array($bids) || !\is_array($asks) || $bids === [] || $asks === []) {
            return;
        }

        // Best bid/ask (assume first level is best).
        $bestBid = $this->extractPriceLevel($bids[0] ?? null);
        $bestAsk = $this->extractPriceLevel($asks[0] ?? null);

        if (!$hasSpread && $bestBid !== null && $bestAsk !== null && $bestBid > 0.0 && $bestAsk > 0.0) {
            $mid = 0.5 * ($bestBid + $bestAsk);
            if ($mid > 0.0) {
                $context['spread_bps'] = 10000.0 * ($bestAsk - $bestBid) / $mid;
            }
        }

        if (!$hasOfi) {
            $bidQty = $this->sumOrderBookQty($bids, 20);
            $askQty = $this->sumOrderBookQty($asks, 20);
            $denom = $bidQty + $askQty;
            if ($denom > 0.0) {
                $context['order_flow_imbalance'] = ($bidQty - $askQty) / $denom;
            }
        }
    }

    private function extractPriceLevel(mixed $row): ?float
    {
        if (\is_array($row)) {
            $price = $row[0] ?? $row['price'] ?? null;
            return \is_numeric($price) ? (float) $price : null;
        }
        if (\is_object($row)) {
            $price = $row->price ?? null;
            return \is_numeric($price) ? (float) $price : null;
        }
        return null;
    }

    /**
     * @param array<int,mixed> $levels
     */
    private function sumOrderBookQty(array $levels, int $limit): float
    {
        $sum = 0.0;
        $n = 0;
        foreach ($levels as $row) {
            if ($n >= $limit) {
                break;
            }
            $qty = null;
            if (\is_array($row)) {
                $qty = $row[1] ?? $row['qty'] ?? $row['size'] ?? null;
            } elseif (\is_object($row)) {
                $qty = $row->qty ?? $row->size ?? null;
            }
            if (\is_numeric($qty)) {
                $sum += (float) $qty;
                $n++;
            }
        }
        return $sum;
    }

    /**
     * @return array<int,mixed>
     */
    private function fetchClosedKlinesForConditionRegistry(string $symbol, TimeframeEnum $timeframe): array
    {
        if ($this->klineProvider === null) {
            return [];
        }

        $klines = $this->klineProvider->getKlines(
            $symbol,
            $timeframe,
            self::CONDITION_REGISTRY_KLINE_WINDOW_SIZE + 1,
        );
        $lastClosedOpenTs = $this->lastClosedKlineOpenTime($this->nowUtc(), $timeframe)->getTimestamp();
        $closed = [];
        $hasOpenTime = false;

        foreach ($klines as $kline) {
            $openTime = $this->extractKlineOpenTime($kline);
            if ($openTime === null) {
                continue;
            }

            $hasOpenTime = true;
            $openTs = $this->toUtcImmutable($openTime)->getTimestamp();
            if ($openTs <= $lastClosedOpenTs) {
                $closed[] = [
                    'open_ts' => $openTs,
                    'kline' => $kline,
                ];
            }
        }

        if (!$hasOpenTime) {
            return \array_slice(
                \array_slice($klines, 0, max(0, \count($klines) - 1)),
                -self::CONDITION_REGISTRY_KLINE_WINDOW_SIZE,
            );
        }

        \usort(
            $closed,
            static fn (array $a, array $b): int => $a['open_ts'] <=> $b['open_ts'],
        );

        return \array_map(
            static fn (array $row): mixed => $row['kline'],
            \array_slice($closed, -self::CONDITION_REGISTRY_KLINE_WINDOW_SIZE),
        );
    }

    private function extractKlineOpenTime(mixed $kline): ?\DateTimeImmutable
    {
        if (\is_object($kline)) {
            if (property_exists($kline, 'openTime') && $kline->openTime instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($kline->openTime);
            }

            if (method_exists($kline, 'getOpenTime')) {
                $candidate = $kline->getOpenTime();
                if ($candidate instanceof \DateTimeInterface) {
                    return \DateTimeImmutable::createFromInterface($candidate);
                }
            }
        }

        if (\is_array($kline)) {
            $candidate = $kline['openTime'] ?? $kline['open_time'] ?? null;
            if ($candidate instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($candidate);
            }
            if (\is_numeric($candidate)) {
                $timestamp = (int) $candidate;
                if ($timestamp > 9999999999) {
                    $timestamp = (int) round($timestamp / 1000);
                }

                return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        return null;
    }

    private function lastClosedKlineOpenTime(\DateTimeInterface $at, TimeframeEnum $timeframe): \DateTimeImmutable
    {
        $atTs = $this->toUtcImmutable($at)->getTimestamp();
        $step = $timeframe->getStepInSeconds();
        $currentOpenTs = intdiv($atTs, $step) * $step;

        return (new \DateTimeImmutable('@' . ($currentOpenTs - $step)))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    private function nowUtc(): \DateTimeImmutable
    {
        return $this->toUtcImmutable($this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function toUtcImmutable(\DateTimeInterface $dateTime): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($dateTime)->setTimezone(new \DateTimeZone('UTC'));
    }
}
