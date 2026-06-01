<?php

declare(strict_types=1);

namespace App\Front\ViewModel;

final readonly class RiskSummaryView
{
    public int $openPositionCount;
    public int $openOrderCount;
    public int $openPlanOrderCount;
    public int $activeLockCount;
    public int $staleLockCount;
    public int $criticalAlertCount;

    /**
     * @param list<array<string, mixed>> $positions
     * @param list<array<string, mixed>> $orders
     * @param list<array<string, mixed>> $planOrders
     * @param list<array<string, mixed>> $locks
     * @param list<FrontAlert> $alerts
     */
    public function __construct(
        public array $positions,
        public array $orders,
        public array $planOrders,
        public array $locks,
        public array $alerts,
    ) {
        $this->openPositionCount = count($positions);
        $this->openOrderCount = count($orders);
        $this->openPlanOrderCount = count($planOrders);
        $this->activeLockCount = count($locks);
        $this->staleLockCount = count(array_filter($locks, static fn (array $lock): bool => (bool) ($lock['is_stale'] ?? false)));
        $this->criticalAlertCount = count(array_filter($alerts, static fn (FrontAlert $alert): bool => $alert->severity === 'critical'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'open_position_count' => $this->openPositionCount,
            'open_order_count' => $this->openOrderCount,
            'open_plan_order_count' => $this->openPlanOrderCount,
            'active_lock_count' => $this->activeLockCount,
            'stale_lock_count' => $this->staleLockCount,
            'critical_alert_count' => $this->criticalAlertCount,
            'positions' => $this->positions,
            'orders' => $this->orders,
            'plan_orders' => $this->planOrders,
            'locks' => $this->locks,
            'alerts' => array_map(static fn (FrontAlert $alert): array => $alert->toArray(), $this->alerts),
        ];
    }
}
