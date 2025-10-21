<?php

declare(strict_types=1);

namespace App\Signal;

use App\Entity\Contract;
use App\Entity\Kline;
use App\Config\TradingParameters;
use App\Indicator\ConditionLoader\TimeframeEvaluator;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;

final class Signal5mService extends AbstractSignal
{
    public function __construct(
        LoggerInterface $validationLogger,
        TradingParameters $configuration,
        TimeframeEvaluator $timeframeEvaluator,
        IndicatorContextBuilder $contextBuilder,
        private readonly LoggerInterface $signalsLogger,
        protected MtfSwitchRepository $mtfSwitchRepository
    ) {
        parent::__construct($validationLogger, $configuration, $timeframeEvaluator, $contextBuilder, mtfSwitchRepository: $mtfSwitchRepository);
    }

    public function supportsTimeframe(string $tf): bool
    {
        return $tf === '5m';
    }

    /**
     * @param Kline[] $klines
     */
    public function evaluate(Contract $contract, array $klines, array $config): array
    {
        $tf = '5m';
        $cfg = $this->configuration->getConfig();

        $minBars = (int) ($cfg['timeframes'][$tf]['guards']['min_bars'] ?? 0);
        if (count($klines) < $minBars) {

            $duration =  (($minBars - count($klines)) * 5 + 5) . ' minutes';
            $this->mtfSwitchRepository->turnOffSymbolForDuration($contract->getSymbol(), $duration);
            return [
                'timeframe' => $tf,
                'signal' => 'NONE',
                'status' => 'insufficient_data',
                'conditions_long' => [],
                'conditions_short' => [],
                'reason' => 'min_bars_not_met',
            ];
        }

        $context = $this->buildIndicatorContext($tf, $klines, $contract);

        $evaluation = $this->evaluateTimeframe($tf, $context);
        $longData = $evaluation['long'];
        $shortData = $evaluation['short'];

        $longPass = $evaluation['passed']['long'] ?? false;
        $shortPass = $evaluation['passed']['short'] ?? false;

        $signal = 'NONE';
        if ($longPass && !$shortPass) {
            $signal = 'LONG';
        } elseif ($shortPass && !$longPass) {
            $signal = 'SHORT';
        } elseif ($longPass && $shortPass) {
            $signal = 'LONG';
        }

        $this->signalsLogger->info('signals.tick', [
            'tf' => $tf,
            'signal' => $signal,
            'symbol' => $contract->getSymbol(),
        ]);

        return [
            'timeframe' => $tf,
            'signal' => $signal,
            'conditions_long' => $longData['conditions'],
            'conditions_short' => $shortData['conditions'],
            'requirements_long' => $longData['requirements'],
            'requirements_short' => $shortData['requirements'],
            'failed_conditions_long' => $longData['failed'],
            'failed_conditions_short' => $shortData['failed'],
            'timestamp' => time(),
            'indicator_context' => $context,
        ];
    }
}
