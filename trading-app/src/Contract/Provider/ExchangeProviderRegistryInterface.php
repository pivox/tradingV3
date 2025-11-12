<?php

declare(strict_types=1);

namespace App\Contract\Provider;

use App\Provider\Context\ExchangeContext;
use App\Provider\Registry\ExchangeProviderBundle;

interface ExchangeProviderRegistryInterface
{
    public function get(?ExchangeContext $context = null): ExchangeProviderBundle;

    public function getDefaultContext(): ExchangeContext;

    /**
     * @return ExchangeProviderBundle[]
     */
    public function all(): array;
}

