<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Exchange\Adapter\BitmartLegacyOrderMapper;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TpSlAttacher
{
    private readonly BitmartLegacyOrderMapper $bitmartOrders;

    public function __construct(
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
        ?BitmartLegacyOrderMapper $bitmartOrders = null,
    ) {
        $this->bitmartOrders = $bitmartOrders ?? new BitmartLegacyOrderMapper();
    }

    /**
     * @return array<string,mixed>
     */
    public function presetInSubmitPayload(OrderPlanModel $plan, string $clientOrderId): array
    {
        $payload = $this->bitmartOrders->entrySubmitPayload($plan, $clientOrderId);

        $this->positionsLogger->debug('tp_sl_attacher.payload_ready', [
            'symbol' => $plan->symbol,
            'client_order_id' => $clientOrderId,
            'order_type' => $plan->orderType,
            'has_tp_sl' => $plan->orderType === 'limit',
            'reason' => 'attach_tp_sl_to_payload',
        ]);

        return $payload;
    }
}
