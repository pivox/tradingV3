<?php
declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Mapper;

use App\Provider\Context\ExchangeContext;
use App\TradeEntry\OrderPlan\OrderPlanModel as LegacyOrderPlanModel;
use App\TradeEntry\Types\Side;
use App\TradingCore\Execution\Service\ClientOrderIdFactory;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;
use App\TradingCore\SlTp\Dto\ProtectionPlan;

final readonly class LegacyOrderPlanMapper
{
    public function __construct(
        private OrderPlanValidator $validator = new OrderPlanValidator(),
        private ClientOrderIdFactory $clientOrderIdFactory = new ClientOrderIdFactory(),
    ) {
    }

    public function fromLegacy(
        LegacyOrderPlanModel $legacy,
        string $profile,
        ?string $decisionKey,
        ?ProtectionPlan $protectionPlan,
    ): OrderPlan {
        $context = ExchangeContext::resolve($legacy->exchangeContext);
        $idempotencyKey = $decisionKey !== null && trim($decisionKey) !== '' ? $decisionKey : null;

        $plan = new OrderPlan(
            symbol: $legacy->symbol,
            profile: $profile,
            exchange: $context->exchange->value,
            marketType: $context->marketType->value,
            side: $legacy->side === Side::Long ? 'long' : 'short',
            orderType: $legacy->orderType,
            marginMode: $legacy->openType,
            timeInForce: $this->timeInForce($legacy),
            entryPrice: $legacy->entry,
            quantity: (float) $legacy->size,
            leverage: $legacy->leverage,
            protectionPlan: $protectionPlan,
            clientOrderId: $idempotencyKey !== null ? $this->clientOrderIdFactory->fromIdempotencyKey($idempotencyKey) : null,
            idempotencyKey: $idempotencyKey,
            decisionKey: $idempotencyKey,
            pricePrecision: $legacy->pricePrecision,
            contractSize: $legacy->contractSize,
            metadata: [
                'source' => 'legacy_order_plan_model',
                'legacy_order_mode' => $legacy->orderMode,
                'legacy_stop' => $legacy->stop,
                'legacy_take_profit' => $legacy->takeProfit,
                'legacy_stop_atr' => $legacy->stopAtr,
                'legacy_stop_risk' => $legacy->stopRisk,
                'legacy_stop_pivot' => $legacy->stopPivot,
                'legacy_stop_final_source' => $legacy->stopFinalSource,
                'entry_zone_low' => $legacy->entryZoneLow,
                'entry_zone_high' => $legacy->entryZoneHigh,
                'zone_expires_at' => $legacy->zoneExpiresAt?->format(\DateTimeInterface::ATOM),
                'entry_zone_meta' => $legacy->entryZoneMeta,
            ],
        );

        return $plan->withValidation($this->validator->validate($plan));
    }

    private function timeInForce(LegacyOrderPlanModel $legacy): string
    {
        if ($legacy->orderType === 'market') {
            return 'ioc';
        }

        return match ($legacy->orderMode) {
            2 => 'fok',
            3 => 'ioc',
            4 => 'post_only',
            default => 'gtc',
        };
    }
}
