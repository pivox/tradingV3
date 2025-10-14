<?php

namespace App\Signal;

use App\Entity\Contract;
use App\Config\TradingParameters;
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
            if (!$k instanceof Kline) { continue; }
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

    abstract public function evaluate(Contract $contract, array $klines, array $config): array;

    abstract public function supportsTimeframe(string $tf): bool;
}
