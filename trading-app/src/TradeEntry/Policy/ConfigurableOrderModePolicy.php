<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\Config\{TradeEntryConfig, TradeEntryConfigProvider, TradeEntryModeContext};
use App\TradeEntry\OrderPlan\OrderPlanModel;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: OrderModePolicyInterface::class)]
final class ConfigurableOrderModePolicy implements OrderModePolicyInterface
{
    public function __construct(
        private readonly TradeEntryConfigProvider $configProvider,
        private readonly TradeEntryModeContext $modeContext,
        private readonly TradeEntryConfig $defaultConfig, // Fallback si mode non fourni
        private readonly MakerOnlyPolicy $makerOnlyPolicy,
        private readonly TakerOnlyPolicy $takerOnlyPolicy,
    ) {
    }

    public function enforce(OrderPlanModel $plan, ?string $mode = null): void
    {
        // Charger la config selon le mode (même mécanisme que validations.{mode}.yaml)
        $config = $this->getConfigForMode($mode);
        $defaultMode = (int) $config->getDefault('order_mode', 4);

        if ($defaultMode === 1) {
            $this->takerOnlyPolicy->enforce($plan);
            return;
        }

        $this->makerOnlyPolicy->enforce($plan);
    }

    /**
     * Charge la config selon le mode (même mécanisme que validations.{mode}.yaml)
     * @param string|null $mode Mode de configuration (ex: 'regular', 'scalping')
     * @return TradeEntryConfig
     */
    private function getConfigForMode(?string $mode): TradeEntryConfig
    {
        $resolvedMode = $this->modeContext->resolve($mode);

        try {
            return $this->configProvider->getConfigForMode($resolvedMode);
        } catch (\RuntimeException $e) {
            if ($resolvedMode !== $this->modeContext->resolve(null)) {
                try {
                    return $this->configProvider->getConfigForMode($this->modeContext->resolve(null));
                } catch (\RuntimeException) {
                    // ignore and fallback below
                }
            }

            return $this->defaultConfig;
        }
    }
}
