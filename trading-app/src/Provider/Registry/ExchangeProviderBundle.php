<?php

declare(strict_types=1);

namespace App\Provider\Registry;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Provider\Context\ExchangeContext;

/**
 * Aggregates all provider dependencies for a given exchange context.
 */
final readonly class ExchangeProviderBundle
{
    public function __construct(
        private ExchangeContext $context,
        private KlineProviderInterface $klineProvider,
        private ContractProviderInterface $contractProvider,
        private OrderProviderInterface $orderProvider,
        private AccountProviderInterface $accountProvider,
        private SystemProviderInterface $systemProvider,
    ) {
    }

    public function context(): ExchangeContext
    {
        return $this->context;
    }

    public function kline(): KlineProviderInterface
    {
        return $this->klineProvider;
    }

    public function contract(): ContractProviderInterface
    {
        return $this->contractProvider;
    }

    public function order(): OrderProviderInterface
    {
        return $this->orderProvider;
    }

    public function account(): AccountProviderInterface
    {
        return $this->accountProvider;
    }

    public function system(): SystemProviderInterface
    {
        return $this->systemProvider;
    }
}

