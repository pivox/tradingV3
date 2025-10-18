<?php

namespace App\Signal;

use App\Entity\Contract;
use App\Config\TradingParameters;
use App\Indicator\Condition\CompositeConditionEvaluator;
use App\Indicator\Condition\ConditionRegistry;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Entity\Kline;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;

abstract class AbstractSignal implements SignalServiceInterface
{
    protected const VALIDATION_KEY = 'validation';

    private $validationTimeframes;
    public function __construct(
        protected readonly LoggerInterface $validationLogger,
        protected readonly TradingParameters $configuration,
        protected readonly ConditionRegistry $conditionRegistry,
        protected readonly IndicatorContextBuilder $contextBuilder,
        protected MtfSwitchRepository $mtfSwitchRepository,
    )
    {
    }

    protected function buildIndicatorContext(string $tf, array $klines, Contract $contract): array
    {
        // Extraire OHLCV depuis les entités Kline
        $closes = [];$highs=[];$lows=[];$volumes=[];
        foreach ($klines as $k) {
            if (!$k instanceof Kline) { 
                $this->validationLogger->error('[AbstractSignal] Non-Kline object found in klines array', [
                    'object_type' => get_class($k),
                    'timeframe' => $tf,
                    'symbol' => $contract->getSymbol()
                ]);
                continue; 
            }
            $closes[] = (float) $k->getClosePrice()->toScale(12)->__toString();
            $highs[]  = (float) $k->getHighPrice()->toScale(12)->__toString();
            $lows[]   = (float) $k->getLowPrice()->toScale(12)->__toString();
            $vol = $k->getVolume();
            $volumes[] = $vol ? (float) $vol->toScale(12)->__toString() : 0.0;
        }

        return $this->contextBuilder
            ->symbol($contract->getSymbol())
            ->timeframe($tf)
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes($volumes)
            ->build();
    }

    public function loadConditions(): array
    {
        $names = $this->conditionRegistry->names();
        $conditions = [];
        foreach ($names as $name) {
            if ($this->conditionRegistry->has($name)) {
                $conditions[] = $this->conditionRegistry->get($name);
            } else {
                $this->validationLogger->warning("Condition '$name' not found in registry.");
            }
        }
        return $conditions;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<int,mixed> $longDefinition
     * @param array<int,mixed> $shortDefinition
     * @return array{
     *     long_results: array<string,array>,
     *     short_results: array<string,array>,
     *     long_evaluation: array{passed:bool,requirements:array<int,array<string,mixed>>},
     *     short_evaluation: array{passed:bool,requirements:array<int,array<string,mixed>>}
     * }
     */
    protected function evaluateCompositeSides(array $context, array $longDefinition, array $shortDefinition): array
    {
        $longNames = CompositeConditionEvaluator::extractConditionNames($longDefinition);
        $shortNames = CompositeConditionEvaluator::extractConditionNames($shortDefinition);
        $allNames = array_values(array_unique(array_merge($longNames, $shortNames)));

        $allResults = $allNames === [] ? [] : $this->conditionRegistry->evaluate($context, $allNames);

        return [
            'long_results' => $this->filterResultsByNames($allResults, $longNames),
            'short_results' => $this->filterResultsByNames($allResults, $shortNames),
            'long_evaluation' => CompositeConditionEvaluator::evaluateRequirements($longDefinition, $allResults),
            'short_evaluation' => CompositeConditionEvaluator::evaluateRequirements($shortDefinition, $allResults),
        ];
    }

    /**
     * @param array<string,array> $results
     * @param string[] $names
     * @return array<string,array>
     */
    private function filterResultsByNames(array $results, array $names): array
    {
        if ($names === []) {
            return [];
        }

        return array_intersect_key($results, array_flip($names));
    }

    abstract public function evaluate(Contract $contract, array $klines, array $config): array;

    abstract public function supportsTimeframe(string $tf): bool;
}
