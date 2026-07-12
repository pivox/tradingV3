<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleStatus;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationReasonCode;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidCompensationResult::class)]
final class HyperliquidCompensationResultTest extends TestCase
{
    public function testAcceptsExactTerminalOutcomeMatrices(): void
    {
        $rejected = $this->makeResult(
            outcome: 'entry_rejected',
            reasonCode: HyperliquidCompensationReasonCode::ENTRY_REJECTED,
            status: HyperliquidLifecycleStatus::REJECTED,
        );
        $canceled = $this->makeResult(
            outcome: 'entry_canceled',
            reasonCode: HyperliquidCompensationReasonCode::ENTRY_CANCELED,
            status: HyperliquidLifecycleStatus::CANCELED,
        );
        $closed = $this->makeResult(
            outcome: 'exposure_closed',
            reasonCode: HyperliquidCompensationReasonCode::EXPOSURE_CLOSED,
            status: HyperliquidLifecycleStatus::FILLED,
            proven: 1.0,
            closed: 1.0,
            closeOid: '99',
        );

        self::assertSame(0.0, $rejected->provenFilledQuantity);
        self::assertSame(0.0, $canceled->closedQuantity);
        self::assertSame(1.0, $closed->closedQuantity);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidMatrixProvider(): iterable
    {
        yield 'rejected with fill' => [['outcome' => 'entry_rejected', 'status' => HyperliquidLifecycleStatus::REJECTED, 'proven' => 0.001]];
        yield 'rejected wrong status' => [['outcome' => 'entry_rejected', 'status' => HyperliquidLifecycleStatus::CANCELED]];
        yield 'canceled with fill' => [['outcome' => 'entry_canceled', 'status' => HyperliquidLifecycleStatus::CANCELED, 'proven' => 0.001]];
        yield 'canceled wrong status' => [['outcome' => 'entry_canceled', 'status' => HyperliquidLifecycleStatus::OPEN]];
        yield 'closed zero' => [['outcome' => 'exposure_closed', 'status' => HyperliquidLifecycleStatus::FILLED, 'closeOid' => '99']];
        yield 'closed unequal' => [['outcome' => 'exposure_closed', 'status' => HyperliquidLifecycleStatus::FILLED, 'proven' => 1.0, 'closed' => 0.999, 'closeOid' => '99']];
        yield 'closed missing oid' => [['outcome' => 'exposure_closed', 'status' => HyperliquidLifecycleStatus::FILLED, 'proven' => 1.0, 'closed' => 1.0]];
        yield 'rejected missing entry oid' => [[
            'outcome' => 'entry_rejected',
            'reasonCode' => HyperliquidCompensationReasonCode::ENTRY_REJECTED,
            'status' => HyperliquidLifecycleStatus::REJECTED,
            'entryOid' => null,
        ]];
        yield 'canceled missing entry oid' => [[
            'outcome' => 'entry_canceled',
            'reasonCode' => HyperliquidCompensationReasonCode::ENTRY_CANCELED,
            'status' => HyperliquidLifecycleStatus::CANCELED,
            'entryOid' => null,
        ]];
        yield 'closed missing entry oid' => [[
            'outcome' => 'exposure_closed',
            'reasonCode' => HyperliquidCompensationReasonCode::EXPOSURE_CLOSED,
            'status' => HyperliquidLifecycleStatus::FILLED,
            'proven' => 1.0,
            'closed' => 1.0,
            'entryOid' => null,
            'closeOid' => '99',
        ]];
        yield 'unknown over expected' => [['outcome' => 'unknown_requires_resync', 'status' => HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC, 'proven' => 1.001]];
        yield 'noncanonical entry oid' => [['entryOid' => '00042']];
        yield 'noncanonical close oid' => [['closeOid' => 'oid-99']];
        yield 'contradictory duplicate oids' => [['entryOid' => '42', 'closeOid' => '42']];
        yield 'unrepresentable result quantity' => [['proven' => 0.0001]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidMatrixProvider')]
    public function testRejectsInvalidOutcomeStatusQuantityOrIdentifierMatrix(array $overrides): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeResult(...$overrides);
    }

    private function makeResult(
        string $outcome = 'unknown_requires_resync',
        HyperliquidCompensationReasonCode $reasonCode = HyperliquidCompensationReasonCode::ENTRY_RECONCILIATION_UNCONFIRMED,
        HyperliquidLifecycleStatus $status = HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
        float $expected = 1.0,
        float $proven = 0.0,
        float $closed = 0.0,
        ?string $entryOid = '42',
        ?string $closeOid = null,
    ): HyperliquidCompensationResult {
        return new HyperliquidCompensationResult(
            outcome: $outcome,
            reasonCode: $reasonCode,
            entryStatus: $status,
            expectedQuantity: $expected,
            provenFilledQuantity: $proven,
            closedQuantity: $closed,
            quantityPrecision: 3,
            quantityStep: '0.001',
            entryExchangeOrderId: $entryOid,
            closeExchangeOrderId: $closeOid,
            correlationId: 'corr-1',
        );
    }
}
