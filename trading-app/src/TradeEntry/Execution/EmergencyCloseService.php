<?php

declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EmergencyCloseService
{
    public function __construct(
        private readonly TradeEntryMetricsService $metrics,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {
    }

    public function closeUnprotectedPosition(
        ExchangeAdapterInterface $adapter,
        OrderPlanModel $plan,
        string $parentClientOrderId,
        ?string $decisionKey = null,
    ): EmergencyCloseResult {
        try {
            $position = $this->findPosition($adapter, $plan);
        } catch (\Throwable $e) {
            return $this->criticalLookupFailure($plan, $decisionKey, 'emergency_close_position_lookup_failed', $e);
        }

        if (!$position instanceof ExchangePositionDto) {
            $this->positionsLogger->info('protection.emergency_close.no_position', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'no_open_position_after_protection_failure',
            ]);

            return new EmergencyCloseResult(
                status: ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED,
                closed: true,
                critical: false,
                metadata: ['reason' => 'no_open_position_after_protection_failure'],
            );
        }

        $clientOrderId = $this->emergencyClientOrderId($parentClientOrderId);
        $this->positionsLogger->critical('protection.emergency_close.submit', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'quantity' => $position->size,
            'client_order_id' => $clientOrderId,
            'decision_key' => $decisionKey,
            'reason' => 'emergency_close_no_sl',
        ]);

        try {
            $result = $adapter->placeOrder(new PlaceOrderRequest(
                exchange: $adapter->exchange(),
                marketType: $adapter->marketType(),
                symbol: $plan->symbol,
                side: $this->exitOrderSide($plan->side),
                positionSide: $this->positionSide($plan->side),
                orderType: ExchangeOrderType::MARKET,
                timeInForce: ExchangeTimeInForce::IOC,
                quantity: $position->size,
                price: null,
                stopPrice: null,
                reduceOnly: true,
                postOnly: false,
                leverage: $plan->leverage,
                marginMode: $plan->openType,
                clientOrderId: $clientOrderId,
                metadata: [
                    'decision_key' => $decisionKey,
                    'reason' => 'emergency_close_no_sl',
                    'parent_client_order_id' => $parentClientOrderId,
                ],
            ));
        } catch (\Throwable $e) {
            $this->metrics->incr('critical_unprotected_position');
            $this->positionsLogger->critical('protection.emergency_close.exception', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'error' => $e->getMessage(),
                'reason' => 'critical_unprotected_position',
            ]);

            return new EmergencyCloseResult(
                status: ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION,
                closed: false,
                critical: true,
                metadata: ['reason' => 'emergency_close_exception', 'error' => $e->getMessage()],
            );
        }

        $this->metrics->incr('emergency_close');

        try {
            $positionStillOpen = $this->findPosition($adapter, $plan) instanceof ExchangePositionDto;
        } catch (\Throwable $e) {
            return $this->criticalLookupFailure($plan, $decisionKey, 'emergency_close_verify_position_failed', $e, $result->exchangeOrderId);
        }

        if ($positionStillOpen) {
            $this->metrics->incr('critical_unprotected_position');
            $this->positionsLogger->critical('protection.emergency_close.critical', [
                'symbol' => $plan->symbol,
                'exchange_order_id' => $result->exchangeOrderId,
                'status' => $result->status->value,
                'accepted' => $result->accepted,
                'position_still_open' => $positionStillOpen,
                'decision_key' => $decisionKey,
                'reason' => 'critical_unprotected_position',
            ]);

            return new EmergencyCloseResult(
                status: ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION,
                closed: false,
                critical: true,
                exchangeOrderId: $result->exchangeOrderId,
                metadata: [
                    'reason' => 'critical_unprotected_position',
                    'close_status' => $result->status->value,
                    'close_accepted' => $result->accepted,
                    'position_still_open' => $positionStillOpen,
                ],
            );
        }

        $this->positionsLogger->critical('protection.emergency_close.closed', [
            'symbol' => $plan->symbol,
            'exchange_order_id' => $result->exchangeOrderId,
            'decision_key' => $decisionKey,
            'reason' => 'emergency_close_no_sl',
        ]);

        return new EmergencyCloseResult(
            status: ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED,
            closed: true,
            critical: false,
            exchangeOrderId: $result->exchangeOrderId,
            metadata: [
                'reason' => 'emergency_close_no_sl',
                'close_status' => $result->status->value,
            ],
        );
    }

    private function findPosition(ExchangeAdapterInterface $adapter, OrderPlanModel $plan): ?ExchangePositionDto
    {
        $positionSide = $this->positionSide($plan->side);
        foreach ($adapter->getOpenPositions($plan->symbol) as $position) {
            if ($position->symbol === strtoupper($plan->symbol) && $position->side === $positionSide && $position->size > 0.0) {
                return $position;
            }
        }

        return null;
    }

    private function criticalLookupFailure(
        OrderPlanModel $plan,
        ?string $decisionKey,
        string $reason,
        \Throwable $e,
        ?string $exchangeOrderId = null,
    ): EmergencyCloseResult {
        $this->metrics->incr('critical_unprotected_position');
        $this->positionsLogger->critical('protection.emergency_close.position_lookup_failed', [
            'symbol' => $plan->symbol,
            'exchange_order_id' => $exchangeOrderId,
            'decision_key' => $decisionKey,
            'error' => $e->getMessage(),
            'reason' => 'critical_unprotected_position',
        ]);

        return new EmergencyCloseResult(
            status: ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION,
            closed: false,
            critical: true,
            exchangeOrderId: $exchangeOrderId,
            metadata: [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ],
        );
    }

    private function emergencyClientOrderId(string $parentClientOrderId): string
    {
        return substr($parentClientOrderId, 0, 44) . '-emergency-close';
    }

    private function positionSide(Side $side): ExchangePositionSide
    {
        return $side === Side::Long ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
    }

    private function exitOrderSide(Side $side): ExchangeOrderSide
    {
        return $side === Side::Long ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }
}
