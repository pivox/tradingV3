<?php

declare(strict_types=1);

namespace App\Indicator\Provider;

use App\Config\IndicatorConfig;
use App\Contract\Indicator\IndicatorEngineInterface;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Indicator\Registry\ConditionRegistry as CompiledRegistry;
use App\Indicator\ConditionLoader\TimeframeEvaluator;
use App\Indicator\Core\AtrCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: IndicatorEngineInterface::class)]
final class IndicatorEngineProvider implements IndicatorEngineInterface
{
    public function __construct(
        private readonly IndicatorContextBuilder $contextBuilder,
        private readonly TimeframeEvaluator $timeframeEvaluator,
        private readonly CompiledRegistry $compiledRegistry,
        private readonly AtrCalculator $atrCalc,
        private readonly IndicatorConfig $indicatorConfig,
        #[Autowire(service: 'monolog.logger.indicators')] private readonly LoggerInterface $validationLogger,
    ) {}

    public function buildContext(string $symbol, string $timeframe, array $klines, array $options = []): array
    {
        $closes = $highs = $lows = $vols = [];
        $ohlc = [];

        foreach ($klines as $k) {
            // Support objects (DTO) and arrays
            $get = static function($row, string $field) {
                if (\is_array($row)) {
                    return $row[$field] ?? ($row[$field.'_float'] ?? null);
                }
                if (\is_object($row)) {
                    if (property_exists($row, $field)) return $row->$field;
                    $m = 'get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $field)));
                    if (method_exists($row, $m)) return $row->$m();
                }
                return null;
            };

            $close = $get($k, 'close');
            $high  = $get($k, 'high');
            $low   = $get($k, 'low');
            $vol   = $get($k, 'volume');

            // Brick\Math\BigDecimal aware
            $toFloat = static fn($v) => \is_object($v) && method_exists($v, 'toFloat') ? (float)$v->toFloat() : (is_numeric($v) ? (float)$v : null);

            $c = $toFloat($close); $h = $toFloat($high); $l = $toFloat($low); $v = $toFloat($vol);
            if ($c === null || $h === null || $l === null || $v === null) {
                // skip malformed
                continue;
            }
            $closes[] = $c; $highs[] = $h; $lows[] = $l; $vols[] = $v;
            $ohlc[] = ['high' => $h, 'low' => $l, 'close' => $c];
        }

        $builder = $this->contextBuilder
            ->withDefaults()
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes($vols)
            ->ohlc($ohlc);

        // Inject per-timeframe ATR volatility thresholds from indicator.yaml if available
        $minApplied = null; $maxApplied = null; $source = 'defaults';
        try {
            $atrPct = $this->indicatorConfig->getAtrPctThresholds();
            if (\is_array($atrPct) && isset($atrPct[$timeframe]) && \is_array($atrPct[$timeframe])) {
                $min = $atrPct[$timeframe]['min'] ?? null;
                $max = $atrPct[$timeframe]['max'] ?? null;
                if (\is_numeric($min)) { $builder->minAtrPct($minApplied = (float)$min); $source = 'indicator.yaml'; }
                if (\is_numeric($max)) { $builder->maxAtrPct($maxApplied = (float)$max); $source = 'indicator.yaml'; }
            }
        } catch (\Throwable) {
            // best effort; fallback to defaults from builder
        }

        // Log application des seuils ATR/close utilisés (qu'ils viennent du YAML ou des defaults du builder)
        if ($minApplied === null || $maxApplied === null) {
            // valeurs par défaut du builder
            $minApplied = 0.001; $maxApplied = 0.03; $source = 'builder.defaults';
        }
        $this->validationLogger->info('validation.atr_thresholds.applied', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'min_atr_pct' => $minApplied,
            'max_atr_pct' => $maxApplied,
            'source' => $source,
        ]);

        // optional overrides
        if (isset($options['entry_price'])) $builder->entryPrice((float)$options['entry_price']);
        if (isset($options['stop_loss'])) $builder->stopLoss((float)$options['stop_loss']);
        if (isset($options['atr_k'])) $builder->atrK((float)$options['atr_k']);

        return $builder->build();
    }

    public function evaluateYaml(string $timeframe, array $context): array
    {
        return $this->timeframeEvaluator->evaluate($timeframe, $context);
    }

    public function evaluateCompiled(string $timeframe, array $context, ?string $side = null): array
    {
        return $this->compiledRegistry->evaluateForTimeframe($timeframe, $context, $side);
    }

    public function computeAtr(array $highs, array $lows, array $closes, ?array $ohlc = null, int $period = 14): ?float
    {
        // Prefer OHLC when available
        if ($ohlc !== null) {
            try { return $this->atrCalc->compute($ohlc, $period); } catch (\Throwable) { return null; }
        }
        $n = min(\count($highs), \count($lows), \count($closes));
        if ($n <= 0) return null;
        $tmp = [];
        for ($i = 0; $i < $n; $i++) { $tmp[] = ['high' => (float)$highs[$i], 'low' => (float)$lows[$i], 'close' => (float)$closes[$i]]; }
        try { return $this->atrCalc->compute($tmp, $period); } catch (\Throwable) { return null; }
    }

    public function evaluateAllConditions(array $context): array
    {
        return $this->compiledRegistry->evaluate($context);
    }

    public function evaluateConditions(array $context, array $names): array
    {
        return $this->compiledRegistry->evaluate($context, $names);
    }

    public function listConditionNames(): array
    {
        return $this->compiledRegistry->names();
    }
}
