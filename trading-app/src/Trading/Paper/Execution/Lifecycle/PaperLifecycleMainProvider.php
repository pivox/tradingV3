<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Lifecycle;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Provider\Context\ExchangeContext;

/**
 * The only provider of the Paper instance of TradeLifecycleLoggerListener (#132 j): its MFE/MAE
 * klines come from the verified dataset of the replay, whatever the exchange context asked for.
 * Every other provider fails closed, so no Paper lifecycle evidence can reach a venue.
 */
final readonly class PaperLifecycleMainProvider implements MainProviderInterface
{
    public function __construct(private PaperDatasetCandleWindow $candles)
    {
    }

    public function getKlineProvider(): KlineProviderInterface
    {
        return $this->candles;
    }

    public function getContractProvider(): ContractProviderInterface
    {
        throw self::unsupported();
    }

    public function getOrderProvider(): ?OrderProviderInterface
    {
        throw self::unsupported();
    }

    public function getAccountProvider(): ?AccountProviderInterface
    {
        throw self::unsupported();
    }

    public function getSystemProvider(): SystemProviderInterface
    {
        throw self::unsupported();
    }

    public function forContext(?ExchangeContext $context = null): self
    {
        return $this;
    }

    private static function unsupported(): \LogicException
    {
        return new \LogicException('paper_lifecycle_provider_unsupported');
    }
}
