<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\Config\TradeEntryConfig;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: OrderModePolicyInterface::class)]
final class ConfigurableOrderModePolicy implements OrderModePolicyInterface
{
    public function __construct(
        private readonly TradeEntryConfig $config,
        private readonly MakerOnlyPolicy $makerOnlyPolicy,
        private readonly TakerOnlyPolicy $takerOnlyPolicy,
    ) {
    }

    public function enforce(OrderPlanModel $plan): void
    {
        $defaultMode = (int) $this->config->getDefault('order_mode', 4);

        if ($defaultMode === 1) {
            $this->takerOnlyPolicy->enforce($plan);
            return;
        }

        $this->makerOnlyPolicy->enforce($plan);
    }
}
