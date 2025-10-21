<?php

namespace App\Signal;

use App\Entity\Contract;
use App\Entity\Kline;
use App\Config\TradingParameters;
use App\Indicator\ConditionLoader\TimeframeEvaluator;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;

final class Signal4hService extends AbstractSignal
{
    public function __construct(
        LoggerInterface $validationLogger,
        TradingParameters $configuration,
        TimeframeEvaluator $timeframeEvaluator,
        IndicatorContextBuilder $contextBuilder,
        private readonly LoggerInterface $signalsLogger,
        protected MtfSwitchRepository $mtfSwitchRepository
    ) { parent::__construct($validationLogger, $configuration, $timeframeEvaluator, $contextBuilder, mtfSwitchRepository: $mtfSwitchRepository); }

    public function supportsTimeframe(string $tf): bool { return $tf === '4h'; }

    /** @param Kline[] $klines */
    public function evaluate(Contract $contract, array $klines, array $config): array
    {
        $tf = '4h';
        $context = $this->buildIndicatorContext($tf, $klines, $contract);

        $evaluation = $this->evaluateTimeframe($tf, $context);
        $longData = $evaluation['long'];
        $shortData = $evaluation['short'];

        $longPass  = $evaluation['passed']['long'] ?? false;
        $shortPass = $evaluation['passed']['short'] ?? false;

        $signal = 'NONE';
        if ($longPass && !$shortPass) { $signal = 'LONG'; }
        elseif ($shortPass && !$longPass) { $signal = 'SHORT'; }
        elseif ($longPass && $shortPass) { $signal = 'LONG'; /* tie-break simple */ }

        $out = [
            'timeframe' => $tf,
            'signal' => $signal,
            'consistent_side' => $signal,
            'conditions_long' => $longData['conditions'],
            'conditions_short' => $shortData['conditions'],
            'requirements_long' => $longData['requirements'],
            'requirements_short' => $shortData['requirements'],
            'failed_conditions_long' => $longData['failed'],
            'failed_conditions_short' => $shortData['failed'],
            'timestamp' => time(),
            'indicator_context' => $context,
        ];
        $this->signalsLogger->info('signals.tick', ['tf'=>$tf,'signal'=>$signal]);
        return $out;
    }
}
