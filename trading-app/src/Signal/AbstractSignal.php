<?php

namespace App\Signal;

use App\Entity\Contract;
use App\Config\TradingParameters;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Entity\Kline;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;
use App\Indicator\ConditionLoader\TimeframeEvaluator;

abstract class AbstractSignal implements SignalServiceInterface
{
    public function __construct(
        protected readonly LoggerInterface $validationLogger,
        protected readonly TradingParameters $configuration,
        protected readonly TimeframeEvaluator $timeframeEvaluator,
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

    protected function evaluateTimeframe(string $tf, array $context): array
    {
        return $this->timeframeEvaluator->evaluate($tf, $context);
    }

    abstract public function evaluate(Contract $contract, array $klines, array $config): array;

    abstract public function supportsTimeframe(string $tf): bool;
}
