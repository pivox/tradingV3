<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\TradeEntry\Dto\{TradeEntryRequest, PreflightReport};
use App\TradeEntry\Dto\EntryZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OrderPlanBox
{
    public function __construct(
        private readonly OrderPlanBuilder $builder,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function create(TradeEntryRequest $req, PreflightReport $pre, ?string $decisionKey = null, ?EntryZone $zone = null): OrderPlanModel
    {
        $this->positionsLogger->debug('order_plan_box.create', [
            'symbol' => $req->symbol,
            'decision_key' => $decisionKey,
            'has_zone' => $zone !== null,
            'reason' => 'delegate_to_plan_builder',
        ]);

        $plan = $this->builder->build($req, $pre, $decisionKey, $zone);

        $this->positionsLogger->debug('order_plan_box.created', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'entry' => $plan->entry,
            'size' => $plan->size,
            'reason' => 'order_plan_built',
        ]);

        return $plan;
    }
}
