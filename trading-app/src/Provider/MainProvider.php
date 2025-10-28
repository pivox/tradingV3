<?php

declare(strict_types=1);

namespace App\Provider;

use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Service central pour accéder à tous les providers
 */
#[AsAlias(id: MainProviderInterface::class)]
final readonly class MainProvider implements MainProviderInterface
{
    public function __construct(
        private KlineProviderInterface    $klineProvider,
        private ContractProviderInterface $contractProvider,
        private OrderProviderInterface    $orderProvider,
        private AccountProviderInterface  $accountProvider,
        private SystemProviderInterface $systemProvider
    ) {}

    public function getSystemTimeMs(): SystemProviderInterface
    {
        return $this->systemProvider;
    }
    public function getKlineProvider(): KlineProviderInterface
    {
        return $this->klineProvider;
    }

    public function getContractProvider(): ContractProviderInterface
    {
        return $this->contractProvider;
    }

    public function getOrderProvider(): OrderProviderInterface
    {
        return $this->orderProvider;
    }

    public function getAccountProvider(): AccountProviderInterface
    {
        return $this->accountProvider;
    }

    /**
     * Vérifie la santé de tous les providers
     */
    public function healthCheck(): bool
    {
        $healthStatus = [
            'kline' => $this->klineProvider->healthCheck(),
            'contract' => $this->contractProvider->healthCheck(),
            // 'account' => $this->accountProvider->healthCheck(),
        ];

        // Retourne true si tous les providers sont en bonne santé
        return !in_array(false, $healthStatus, true);
    }

    /**
     * Retourne le nom du provider service
     */
    public function getProviderName(): string
    {
        return 'MainProvider';
    }

    /**
     * Vérifie la santé de tous les providers avec détails
     */
    public function getDetailedHealthCheck(): array
    {
        return [
            'kline' => $this->klineProvider->healthCheck(),
            'contract' => $this->contractProvider->healthCheck(),
            'account' => $this->accountProvider->healthCheck(),
        ];
    }

    public function getSystemProvider(): SystemProviderInterface
    {
        return $this->systemProvider;
    }
}
