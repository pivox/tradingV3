<?php

declare(strict_types=1);

namespace App\Provider;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Provider\Context\ExchangeContext;
use App\Provider\Registry\ExchangeProviderBundle;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Service central pour accéder à tous les providers.
 *
 * Cette façade s'appuie désormais sur un registre d'exchanges pour
 * faciliter l'ajout futur de nouveaux exchanges et marchés (spot / perp).
 */
#[AsAlias(id: MainProviderInterface::class)]
final readonly class MainProvider implements MainProviderInterface
{
    public function __construct(
        private ExchangeProviderRegistryInterface $registry,
        private ?ExchangeContext $context = null,
    ) {
    }

    public function getSystemTimeMs(): SystemProviderInterface
    {
        return $this->bundle()->system();
    }
    public function getKlineProvider(): KlineProviderInterface
    {
        return $this->bundle()->kline();
    }

    public function getContractProvider(): ContractProviderInterface
    {
        return $this->bundle()->contract();
    }

    public function getOrderProvider(): OrderProviderInterface
    {
        return $this->bundle()->order();
    }

    public function getAccountProvider(): AccountProviderInterface
    {
        return $this->bundle()->account();
    }

    /**
     * Vérifie la santé de tous les providers.
     */
    public function healthCheck(): bool
    {
        $bundle = $this->bundle();
        $healthStatus = [
            'kline' => $bundle->kline()->healthCheck(),
            'contract' => $bundle->contract()->healthCheck(),
            // 'account' => $bundle->account()->healthCheck(),
        ];

        return !in_array(false, $healthStatus, true);
    }

    public function getProviderName(): string
    {
        return 'MainProvider';
    }

    public function getDetailedHealthCheck(): array
    {
        $bundle = $this->bundle();

        return [
            'kline' => $bundle->kline()->healthCheck(),
            'contract' => $bundle->contract()->healthCheck(),
            'account' => $bundle->account()->healthCheck(),
        ];
    }

    public function getSystemProvider(): SystemProviderInterface
    {
        return $this->bundle()->system();
    }

    public function forContext(?ExchangeContext $context = null): self
    {
        $context ??= $this->registry->getDefaultContext();

        if ($this->context?->equals($context)) {
            return $this;
        }

        return new self($this->registry, $context);
    }

    private function bundle(): ExchangeProviderBundle
    {
        return $this->registry->get($this->context);
    }
}
