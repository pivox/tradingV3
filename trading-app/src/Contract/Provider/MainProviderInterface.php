<?php

declare(strict_types=1);

namespace App\Contract\Provider;

use App\Provider\Context\ExchangeContext;

/**
 * Interface pour les services de coordination des providers
 */
interface MainProviderInterface
{
    /**
     * Retourne le provider de klines
     */
    public function getKlineProvider(): KlineProviderInterface;

    /**
     * Retourne le provider de contrats
     */
    public function getContractProvider(): ContractProviderInterface;

    /**
     * Retourne le provider d'ordres
     */
    public function getOrderProvider(): OrderProviderInterface;

    /**
     * Retourne le provider de compte
     */
    public function getAccountProvider(): AccountProviderInterface;

    public function getSystemProvider(): SystemProviderInterface;

    public function forContext(?ExchangeContext $context = null): self;
}

