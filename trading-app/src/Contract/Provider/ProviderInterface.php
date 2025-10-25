<?php

declare(strict_types=1);

namespace App\Contract\Provider;

/**
 * Interface de base pour tous les providers
 */
interface ProviderInterface
{
    /**
     * Vérifie la santé du provider
     */
    public function healthCheck(): bool;

    /**
     * Retourne le nom du provider
     */
    public function getProviderName(): string;

    public function getProvider(): MainProviderInterface;
    public function getOrderProvider(): OrderProviderInterface;
    public function getKlineProvider(): KlineProviderInterface;
    public function getContractProvider(): ContractProviderInterface;
    public function getAccountProvider(): AccountProviderInterface;
}
