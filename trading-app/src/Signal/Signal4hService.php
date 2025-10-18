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

        $longDefinition  = $tfBlock['long']  ?? [];
        $shortDefinition = $tfBlock['short'] ?? [];
        if (!\is_array($longDefinition)) { $longDefinition = []; }
        if (!\is_array($shortDefinition)) { $shortDefinition = []; }

        $context = $this->buildIndicatorContext($tf, $klines, $contract);

        $composite = $this->evaluateCompositeSides($context, $longDefinition, $shortDefinition);
        $longResults = $composite['long_results'];
        $shortResults = $composite['short_results'];
        $longEvaluation = $composite['long_evaluation'];
        $shortEvaluation = $composite['short_evaluation'];

        $longPass  = $longDefinition  === [] ? false : ($longEvaluation['passed'] ?? false);
        $shortPass = $shortDefinition === [] ? false : ($shortEvaluation['passed'] ?? false);

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
            'requirements_long' => $longEvaluation['requirements'] ?? [],
            'requirements_short' => $shortEvaluation['requirements'] ?? [],
            'timestamp' => time(),
            'indicator_context' => $context,
        ];
        $this->signalsLogger->info('signals.tick', ['tf'=>$tf,'signal'=>$signal]);
        return $out;
    }
}
