<?php

namespace App\Signal;

use App\Config\TradingParameters;
use App\Contract\Indicator\IndicatorMainProviderInterface;
use App\Contract\Signal\SignalServiceInterface;
use App\Entity\Contract;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;

abstract class AbstractSignal implements SignalServiceInterface
{
    public function __construct(
        protected readonly LoggerInterface $validationLogger,
        protected readonly TradingParameters $configuration,
        protected readonly IndicatorMainProviderInterface $indicatorMain,
        protected MtfSwitchRepository $mtfSwitchRepository,
    )
    {
    }

    protected function buildIndicatorContext(string $tf, array $klines, Contract $contract): array
    {
        // Extraire OHLCV depuis les entités Kline
        $engine = $this->indicatorMain->getEngine();
        return $engine->buildContext(
            $contract->getSymbol(),
            $tf,
            $klines,
            []
        );
    }

    protected function evaluateTimeframe(string $tf, array $context): array
    {
        return $this->indicatorMain->getEngine()->evaluateYaml($tf, $context);
    }

    abstract public function evaluate(Contract $contract, array $klines, array $config): array;

    abstract public function supportsTimeframe(string $tf): bool;
}
