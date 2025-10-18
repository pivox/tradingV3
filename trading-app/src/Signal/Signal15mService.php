<?php

namespace App\Signal;

use App\Entity\Contract;
use App\Entity\Kline;
use App\Config\TradingParameters;
use App\Indicator\Condition\ConditionRegistry;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;

final class Signal15mService extends AbstractSignal
{
    public function __construct(
        LoggerInterface $validationLogger,
        TradingParameters $configuration,
        ConditionRegistry $conditionRegistry,
        IndicatorContextBuilder $contextBuilder,
        private readonly LoggerInterface $signalsLogger,
        protected MtfSwitchRepository $mtfSwitchRepository
    ) { parent::__construct($validationLogger, $configuration, $conditionRegistry, $contextBuilder, mtfSwitchRepository: $mtfSwitchRepository); }

    public function supportsTimeframe(string $tf): bool { return $tf === '15m'; }

    /** @param Kline[] $klines */
    public function evaluate(Contract $contract, array $klines, array $config): array
    {
        $tf = '15m';
        $cfg = $this->configuration->getConfig();
        $minBars = (int) ($cfg['timeframes'][$tf]['guards']['min_bars'] ?? 0);
        if (count($klines) < $minBars) {

            $duration =  (($minBars - count($klines)) * 15 + 15) . ' minutes';
            $this->mtfSwitchRepository->turnOffSymbolForDuration(symbol: $contract->getSymbol(), duration: $duration);
            return [
                'timeframe' => $tf,
                'signal' => 'NONE',
                'status' => 'insufficient_data',
                'conditions_long' => [],
                'conditions_short' => [],
                'reason' => 'min_bars_not_met',
            ];
        }

        $tfBlock = $cfg[self::VALIDATION_KEY]['timeframe'][$tf] ?? [];
        $longDefinition = $tfBlock['long'] ?? [];
        $shortDefinition = $tfBlock['short'] ?? [];

        if (!\is_array($longDefinition)) {
            $longDefinition = [];
        }
        if (!\is_array($shortDefinition)) {
            $shortDefinition = [];
        }

        $context = $this->buildIndicatorContext($tf, $klines, $contract);

        $composite = $this->evaluateCompositeSides($context, $longDefinition, $shortDefinition);
        $longResults = $composite['long_results'];
        $shortResults = $composite['short_results'];
        $longEvaluation = $composite['long_evaluation'];
        $shortEvaluation = $composite['short_evaluation'];

        $longPass = $longDefinition === [] ? false : ($longEvaluation['passed'] ?? false);
        $shortPass = $shortDefinition === [] ? false : ($shortEvaluation['passed'] ?? false);

        $signal = 'NONE';
        if ($longPass && !$shortPass) { $signal = 'LONG'; }
        elseif ($shortPass && !$longPass) { $signal = 'SHORT'; }
        elseif ($longPass && $shortPass) { $signal = 'LONG'; }

        $out = [
            'timeframe' => $tf,
            'signal' => $signal,
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
