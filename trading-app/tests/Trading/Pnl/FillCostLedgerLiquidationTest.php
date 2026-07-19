<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\FillCostLedgerEntry;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Fake\FakeLiquidationPolicy;
use App\Repository\FillCostLedgerEntryRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FillCostLedgerIngestionService::class)]
final class FillCostLedgerLiquidationTest extends TestCase
{
    public function testKnownLiquidationFeeIsPersistedAndReplayIsExactOnce(): void
    {
        [$service, $stored] = $this->service();
        $event = $this->event([
            'liquidation_fee_usdt' => 110.0,
            'liquidation_fee_currency' => 'USDT',
            'liquidation_fee_model_version' => FakeLiquidationPolicy::FEE_MODEL_VERSION,
        ]);

        $first = $service->ingestExchangeFill($event);
        $second = $service->ingestExchangeFill($event);
        $entry = $stored();

        self::assertTrue($first->inserted);
        self::assertTrue($second->replayed);
        self::assertFalse($second->inserted);
        self::assertInstanceOf(FillCostLedgerEntry::class, $entry);
        self::assertSame('110.000000000000', $entry->getLiquidationFeeUsdt());
        self::assertSame('11.000000000000', $entry->getFeeUsdt());
        self::assertSame(['missing_lineage'], $entry->getQualityFlags());
    }

    public function testInvalidLiquidationFeeRemainsNullWithQualityFlag(): void
    {
        [$service, $stored] = $this->service();

        $service->ingestExchangeFill($this->event([
            'liquidation_fee_usdt' => -1,
            'liquidation_fee_currency' => 'USDT',
            'liquidation_fee_model_version' => FakeLiquidationPolicy::FEE_MODEL_VERSION,
        ], fillId: 'fake-fill-liquidation-invalid'));
        $entry = $stored();

        self::assertInstanceOf(FillCostLedgerEntry::class, $entry);
        self::assertNull($entry->getLiquidationFeeUsdt());
        self::assertContains('liquidation_fee_invalid', $entry->getQualityFlags());
    }

    /**
     * @return array{FillCostLedgerIngestionService,\Closure():?FillCostLedgerEntry}
     */
    private function service(): array
    {
        $persisted = null;
        $ledger = $this->createMock(FillCostLedgerEntryRepository::class);
        $ledger->method('findOneByIdempotencyKey')
            ->willReturnCallback(static function () use (&$persisted): ?FillCostLedgerEntry {
                return $persisted;
            });
        $ledger->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$persisted): void {
                $persisted = $entry;
            });
        $lineageRepository = (new \ReflectionClass(TradeLineageRepository::class))->newInstanceWithoutConstructor();
        $lineage = new TradeLineageManager(
            $lineageRepository,
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        return [
            new FillCostLedgerIngestionService($ledger, $lineage),
            static function () use (&$persisted): ?FillCostLedgerEntry {
                return $persisted;
            },
        ];
    }

    /** @param array<string,mixed> $liquidationMetadata */
    private function event(
        array $liquidationMetadata,
        string $fillId = 'fake-fill-liquidation-known',
    ): ExchangeFillReceived {
        return new ExchangeFillReceived(new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: '',
            clientOrderId: null,
            fillId: $fillId,
            side: ExchangeOrderSide::SELL,
            positionSide: ExchangePositionSide::LONG,
            quantity: 1.0,
            price: 22000.0,
            fee: 11.0,
            feeCurrency: 'USDT',
            filledAt: new \DateTimeImmutable('2026-07-19T10:00:00+00:00'),
            metadata: [
                'source' => 'fake_exchange_ws',
                'pnl_source' => 'fake_paper_fill_ledger_v1',
                'liquidity_role' => 'taker',
                'spread_cost_usdt' => 0.0,
                'slippage_cost_usdt' => 11.0,
            ] + $liquidationMetadata,
        ));
    }
}
