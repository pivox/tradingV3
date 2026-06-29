<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Fake;

final class FakeExecutionScenarioFixtures
{
    public static function orderAccepted(): FakeExecutionScenario
    {
        return new FakeExecutionScenario(
            name: 'order_accepted',
            orderOutcome: 'accepted',
            fillRatio: 0.0,
            protectionOutcome: 'not_requested',
        );
    }

    public static function orderRejected(): FakeExecutionScenario
    {
        return new FakeExecutionScenario(
            name: 'order_rejected',
            orderOutcome: 'rejected',
            fillRatio: 0.0,
            protectionOutcome: 'not_requested',
            rejectReason: 'fake_exchange_rejected_order',
        );
    }

    public static function fullFillStopAttachSuccess(): FakeExecutionScenario
    {
        return new FakeExecutionScenario(
            name: 'full_fill_stop_attach_success',
            orderOutcome: 'accepted',
            fillRatio: 1.0,
            protectionOutcome: 'attached',
        );
    }

    public static function fullFillStopAttachFailure(): FakeExecutionScenario
    {
        return new FakeExecutionScenario(
            name: 'full_fill_stop_attach_failure',
            orderOutcome: 'accepted',
            fillRatio: 1.0,
            protectionOutcome: 'failed',
            qualityFlags: ['protection_attach_failed'],
            failSafeAction: 'cancel_or_reduce_only_close_required',
        );
    }

    public static function partialFillStopRejected(): FakeExecutionScenario
    {
        return new FakeExecutionScenario(
            name: 'partial_fill_stop_rejected',
            orderOutcome: 'accepted',
            fillRatio: 0.5,
            protectionOutcome: 'rejected',
            qualityFlags: ['partial_fill', 'partial_stop_rejected'],
            failSafeAction: 'cancel_or_reduce_only_close_required',
        );
    }

    public static function cancelAcceptedOrder(): FakeExecutionScenario
    {
        return new FakeExecutionScenario(
            name: 'cancel_accepted_order',
            orderOutcome: 'cancelled',
            fillRatio: 0.0,
            protectionOutcome: 'not_requested',
        );
    }
}
