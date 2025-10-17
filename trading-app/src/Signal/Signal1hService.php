<?php

namespace App\Signal;

use App\Entity\Contract;
use App\Entity\Kline;
use App\Config\TradingParameters;
use App\Indicator\Condition\ConditionRegistry;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;

final class Signal1hService extends AbstractSignal
{
    public function __construct(
        LoggerInterface $validationLogger,
        TradingParameters $configuration,
        ConditionRegistry $conditionRegistry,
        IndicatorContextBuilder $contextBuilder,
        private readonly LoggerInterface $signalsLogger,
        protected  MtfSwitchRepository $mtfSwitchRepository
    ) { parent::__construct($validationLogger, $configuration, $conditionRegistry, $contextBuilder, mtfSwitchRepository: $mtfSwitchRepository); }

    public function supportsTimeframe(string $tf): bool { return $tf === '1h'; }

    /** @param Kline[] $klines */
    public function evaluate(Contract $contract, array $klines, array $config): array
    {
        $tf = '1h';
        $cfg = $this->configuration->getConfig();
        $minBars = (int) ($cfg['timeframes'][$tf]['guards']['min_bars'] ?? 0);
        if (count($klines) < $minBars) {
            $duration =  (($minBars - count($klines)) ) . ' hours';
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
        $tfBlock = $cfg[self::VALIDATION_KEY]['timeframe'][$tf] ?? [];
        $longNames  = $tfBlock['long']  ?? [];
        $shortNames = $tfBlock['short'] ?? [];
        $context = $this->buildIndicatorContext($tf, $klines, $contract);
        $longResults  = $this->conditionRegistry->evaluate($context,$longNames);
        $shortResults = $this->conditionRegistry->evaluate($context,$shortNames);
        $longPass  = $longNames  === [] ? false : $this->allPassed($longResults,$longNames);
        $shortPass = $shortNames === [] ? false : $this->allPassed($shortResults,$shortNames);
        $signal='NONE';
        if ($longPass && !$shortPass) $signal='LONG';
        elseif ($shortPass && !$longPass) $signal='SHORT';
        elseif ($longPass && $shortPass) $signal='LONG';
        $out=[ 'timeframe'=>$tf,'signal'=>$signal,'conditions_long'=>$longResults,'conditions_short'=>$shortResults,'timestamp'=>time(),'indicator_context'=>$context ];
        $this->signalsLogger->info('signals.tick',['tf'=>$tf,'signal'=>$signal]);
        return $out;
    }

    private function allPassed(array $results, array $expected): bool
    {
        foreach ($expected as $n) {
            if (!isset($results[$n]) || ($results[$n]['passed']??false)!==true) return false;
        }
        return true;
    }
}
