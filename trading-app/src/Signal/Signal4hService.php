<?php

namespace App\Signal;

use App\Entity\Contract;
use App\Entity\Kline;
use App\Config\TradingParameters;
use App\Indicator\Condition\ConditionRegistry;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;

final class Signal4hService extends AbstractSignal
{
    public function __construct(
        LoggerInterface $validationLogger,
        TradingParameters $configuration,
        ConditionRegistry $conditionRegistry,
        IndicatorContextBuilder $contextBuilder,
        private readonly LoggerInterface $signalsLogger,
        protected MtfSwitchRepository $mtfSwitchRepository
    ) { parent::__construct($validationLogger, $configuration, $conditionRegistry, $contextBuilder, mtfSwitchRepository: $mtfSwitchRepository); }

    public function supportsTimeframe(string $tf): bool { return $tf === '4h'; }

    /** @param Kline[] $klines */
    public function evaluate(Contract $contract, array $klines, array $config): array
    {
        $tf = '4h';
        $cfg = $this->configuration->getConfig();

        $tfBlock = $cfg[self::VALIDATION_KEY]['timeframe'][$tf] ?? [];

        $longNames  = $tfBlock['long']  ?? [];
        $shortNames = $tfBlock['short'] ?? [];

        $context = $this->buildIndicatorContext($tf, $klines, $contract);

        $longResults  = $this->conditionRegistry->evaluate($context, $longNames);
        $shortResults = $this->conditionRegistry->evaluate($context, $shortNames);

        $longPass  = $longNames  === [] ? false : $this->allPassed($longResults, $longNames);
        $shortPass = $shortNames === [] ? false : $this->allPassed($shortResults, $shortNames);

        $signal = 'NONE';
        if ($longPass && !$shortPass) { $signal = 'LONG'; }
        elseif ($shortPass && !$longPass) { $signal = 'SHORT'; }
        elseif ($longPass && $shortPass) { $signal = 'LONG'; /* tie-break simple */ }

        $out = [
            'timeframe' => $tf,
            'signal' => $signal,
            'consistent_side' => $signal,
            'conditions_long' => $longResults,
            'conditions_short' => $shortResults,
            'timestamp' => time(),
            'indicator_context' => $context,
        ];
        $this->signalsLogger->info('signals.tick', ['tf'=>$tf,'signal'=>$signal]);
        return $out;
    }

    private function allPassed(array $results, array $expectedOrder): bool
    {
        foreach ($expectedOrder as $name) {
            if (!isset($results[$name]) || ($results[$name]['passed'] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }
}
