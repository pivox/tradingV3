<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\FillCostLedgerEntry;
use App\Entity\OrderIntent;
use App\Entity\TradeLineage;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\ExchangeFillReceived;
use App\Repository\FillCostLedgerEntryRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Pnl\FillCostLedgerIngestionConflict;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use App\Trading\Pnl\FillQuantityAggregationService;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #132 p: a fill sized in contracts (OKX fillSz, Fake contracts of its instrument's contract
 * size) is recorded in base-asset units, its USDT costs untouched; a missing or invalid contract
 * value is flagged, never scaled by 1; base-asset fills are recorded exactly as before.
 */
#[CoversClass(FillCostLedgerIngestionService::class)]
final class FillCostLedgerContractValueTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FillCostLedgerIngestionService $service;
    private FillCostLedgerEntryRepository $ledger;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($this->classes());
        $tool->createSchema($this->classes());

        /** @var FillCostLedgerEntryRepository $ledger */
        $ledger = $this->em->getRepository(FillCostLedgerEntry::class);
        /** @var TradeLineageRepository $lineages */
        $lineages = $this->em->getRepository(TradeLineage::class);
        $this->ledger = $ledger;
        $this->service = new FillCostLedgerIngestionService(
            $ledger,
            new TradeLineageManager($lineages, $this->em, new NullLogger()),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            (new SchemaTool($this->em))->dropSchema($this->classes());
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testAnOkxFillInContractsIsRecordedInBaseAssetUnitsWithItsCostsUntouched(): void
    {
        $this->persistLineage('itd-okx-contracts', 'cid-okx-contracts', 'OKX-ORDER-CONTRACTS', Exchange::OKX);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchange: Exchange::OKX,
            exchangeOrderId: 'OKX-ORDER-CONTRACTS',
            clientOrderId: 'cid-okx-contracts',
            fillId: 'okx-fill-contracts',
            quantity: 0.8,
            price: 30310.0,
            fee: 0.12124,
            metadata: [
                'quantity_unit' => 'contracts',
                'contract_value' => '0.01',
                'slippage_cost_usdt' => 0.12124,
                'spread_cost_usdt' => 0.0,
            ],
        )));

        $entry = $this->entry('okx:perpetual:exchange_fill:okx-fill-contracts');
        self::assertSame('30310.000000000000', $entry->getPrice());
        self::assertSame('0.008000000000', $entry->getQuantity());
        self::assertSame('242.480000000000', $entry->getNotional());
        // The venue fee and the modelled slippage are already USDT on the real notional.
        self::assertSame('0.121240000000', $entry->getFeeUsdt());
        self::assertSame('0.121240000000', $entry->getSlippageCostUsdt());
        self::assertSame('0.000000000000', $entry->getSpreadCostUsdt());
        self::assertSame([], $entry->getQualityFlags());
        self::assertSame('contracts', $entry->getRawReference()['quantity_unit'] ?? null);
        self::assertSame('0.800000000000', $entry->getRawReference()['contract_quantity'] ?? null);
        self::assertSame('0.01', $entry->getRawReference()['contract_value'] ?? null);
        self::assertViewInvariant($entry);
    }

    public function testARoundTripInContractsYieldsTheBaseAssetGrossAndNetPnl(): void
    {
        $this->persistLineage('itd-okx-round-trip', 'cid-okx-round-trip', 'OKX-ORDER-ENTRY', Exchange::FAKE);
        $contracts = ['quantity_unit' => 'contracts', 'contract_value' => '0.01'];

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'OKX-ORDER-ENTRY',
            clientOrderId: 'cid-okx-round-trip',
            fillId: 'fake-fill-round-trip-entry',
            quantity: 0.8,
            price: 30310.0,
            fee: 0.12124,
            metadata: $contracts + ['internal_trade_id' => 'itd-okx-round-trip', 'slippage_cost_usdt' => 0.0, 'spread_cost_usdt' => 0.0],
        )));
        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'OKX-ORDER-STOP',
            clientOrderId: null,
            fillId: 'fake-fill-round-trip-exit',
            side: ExchangeOrderSide::SELL,
            quantity: 0.8,
            price: 30104.7,
            fee: 0.1204188,
            metadata: $contracts + ['internal_trade_id' => 'itd-okx-round-trip', 'slippage_cost_usdt' => 0.1204188, 'spread_cost_usdt' => 0.0],
            filledAt: new \DateTimeImmutable('2026-01-01 00:05:00 UTC'),
        )));

        $gross = BigDecimal::zero();
        $costs = BigDecimal::zero();
        foreach ($this->ledger->findByInternalTradeId('itd-okx-round-trip') as $entry) {
            self::assertSame([], $entry->getQualityFlags());
            self::assertSame('0.008000000000', $entry->getQuantity());
            self::assertViewInvariant($entry);
            $notional = BigDecimal::of((string) $entry->getNotional());
            $gross = $entry->getFillRole() === 'exit' ? $gross->plus($notional) : $gross->minus($notional);
            $costs = $costs->plus((string) $entry->getFeeUsdt())
                ->plus((string) $entry->getSlippageCostUsdt())
                ->plus((string) $entry->getSpreadCostUsdt());
        }

        // position_trade_ledger_aggregate_v1: gross = exit notional - entry notional (long).
        self::assertSame('-1.642400000000', (string) $gross);
        self::assertSame('-2.004477600000', (string) $gross->minus($costs));

        // The Paper lifecycle reads the same base quantity: notional_usdt = 30310 x 0.008.
        $aggregate = (new FillQuantityAggregationService($this->ledger))
            ->aggregateByTradeVenue('itd-okx-round-trip', 'fake', 'perpetual');
        self::assertSame('complete', $aggregate->quantityStatus);
        self::assertEqualsWithDelta(0.008, $aggregate->entryQty, 1e-15);
        self::assertEqualsWithDelta(30310.0, $aggregate->entryVwap, 1e-9);
        self::assertEqualsWithDelta(30104.7, $aggregate->exitVwap, 1e-9);
        self::assertEqualsWithDelta(242.48, $aggregate->entryVwap * $aggregate->entryQty, 1e-9);
    }

    public function testBaseAssetFillsAreRecordedExactlyAsBefore(): void
    {
        $declarations = [
            'undeclared' => [],
            'base_asset' => ['quantity_unit' => 'base_asset'],
            'contracts of one' => ['quantity_unit' => 'contracts', 'contract_value' => '1'],
            'contracts of 1.000' => ['quantity_unit' => 'contracts', 'contract_value' => '1.000'],
        ];
        $recorded = [];
        foreach ($declarations as $label => $declaration) {
            $fillId = 'fake-fill-base-' . md5($label);
            $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
                fillId: $fillId,
                quantity: 0.00082,
                price: 30280.0,
                fee: 0.0124148,
                metadata: $declaration,
            )));
            $entry = $this->entry('fake:perpetual:exchange_fill:' . $fillId);
            $reference = $entry->getRawReference();
            unset($reference['exchange_fill_id']);
            $recorded[$label] = [
                $entry->getPrice(),
                $entry->getQuantity(),
                $entry->getNotional(),
                $entry->getFeeUsdt(),
                $entry->getQualityFlags(),
                $reference,
            ];
        }

        self::assertSame('0.000820000000', $recorded['undeclared'][1]);
        self::assertSame('24.829600000000', $recorded['undeclared'][2]);
        foreach ($recorded as $label => $values) {
            self::assertSame($recorded['undeclared'], $values, $label);
        }
    }

    public function testAFillInContractsWithoutAValidContractValueIsFlaggedNeverScaledByOne(): void
    {
        $cases = [
            'okx undeclared' => [Exchange::OKX, [], 'contract_value_missing'],
            'okx contracts without value' => [Exchange::OKX, ['quantity_unit' => 'contracts'], 'contract_value_missing'],
            'fake contracts without value' => [Exchange::FAKE, ['quantity_unit' => 'contracts'], 'contract_value_missing'],
            'null value' => [Exchange::FAKE, ['quantity_unit' => 'contracts', 'contract_value' => null], 'contract_value_missing'],
            'empty value' => [Exchange::FAKE, ['quantity_unit' => 'contracts', 'contract_value' => ''], 'contract_value_missing'],
            'zero' => [Exchange::FAKE, ['quantity_unit' => 'contracts', 'contract_value' => '0'], 'contract_value_invalid'],
            'negative' => [Exchange::OKX, ['quantity_unit' => 'contracts', 'contract_value' => '-0.01'], 'contract_value_invalid'],
            'not a number' => [Exchange::OKX, ['quantity_unit' => 'contracts', 'contract_value' => 'ctVal'], 'contract_value_invalid'],
            'array' => [Exchange::FAKE, ['quantity_unit' => 'contracts', 'contract_value' => ['0.01']], 'contract_value_invalid'],
            'not finite' => [Exchange::FAKE, ['quantity_unit' => 'contracts', 'contract_value' => NAN], 'contract_value_invalid'],
            'unknown unit' => [Exchange::FAKE, ['quantity_unit' => 'lots', 'contract_value' => '0.01'], 'quantity_unit_invalid'],
        ];
        foreach ($cases as $label => [$exchange, $declaration, $flag]) {
            $fillId = 'fill-unscaled-' . md5($label);
            $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
                exchange: $exchange,
                exchangeOrderId: 'ORDER-' . md5($label),
                fillId: $fillId,
                quantity: 0.8,
                price: 30310.0,
                metadata: $declaration,
            )));
            $entry = $this->entry($exchange->value . ':perpetual:exchange_fill:' . $fillId);

            self::assertContains($flag, $entry->getQualityFlags(), $label);
            self::assertSame('0.800000000000', $entry->getQuantity(), $label);
            self::assertSame('24248.000000000000', $entry->getNotional(), $label);
            self::assertArrayNotHasKey('contract_value', $entry->getRawReference(), $label);
        }
    }

    public function testAConvertedFillReplaysIdempotentlyAndAnotherContractValueConflicts(): void
    {
        $fill = fn (string $contractValue): ExchangeFillReceived => new ExchangeFillReceived($this->fill(
            fillId: 'fake-fill-replayed-contracts',
            quantity: 0.8,
            price: 30310.0,
            metadata: ['quantity_unit' => 'contracts', 'contract_value' => $contractValue],
        ));

        self::assertTrue($this->service->ingestExchangeFill($fill('0.01'))->inserted);
        $replay = $this->service->ingestExchangeFill($fill('0.010'));
        self::assertTrue($replay->replayed);
        self::assertSame('0.008000000000', $replay->entry->getQuantity());

        $this->expectException(FillCostLedgerIngestionConflict::class);
        $this->service->ingestExchangeFill($fill('0.1'));
    }

    private static function assertViewInvariant(FillCostLedgerEntry $entry): void
    {
        $price = BigDecimal::of((string) $entry->getPrice());
        $quantity = BigDecimal::of((string) $entry->getQuantity());
        $notional = BigDecimal::of((string) $entry->getNotional());
        $product = $price->multipliedBy($quantity);
        // position_trade_ledger_aggregate_v1 drops a fill whose notional is not price x quantity.
        self::assertTrue(
            $notional->minus($product)->abs()->isLessThanOrEqualTo(
                BigDecimal::of('0.00000001')->multipliedBy($notional->abs()->isGreaterThan($product->abs()) ? $notional->abs() : $product->abs()),
            ),
            'notional must stay price x quantity',
        );
    }

    private function entry(string $idempotencyKey): FillCostLedgerEntry
    {
        $entry = $this->ledger->findOneByIdempotencyKey($idempotencyKey);
        self::assertInstanceOf(FillCostLedgerEntry::class, $entry, $idempotencyKey);

        return $entry;
    }

    /** @return list<\Doctrine\ORM\Mapping\ClassMetadata<object>> */
    private function classes(): array
    {
        return [
            $this->em->getClassMetadata(OrderIntent::class),
            $this->em->getClassMetadata(TradeLineage::class),
            $this->em->getClassMetadata(FillCostLedgerEntry::class),
        ];
    }

    private function persistLineage(string $internalTradeId, string $clientOrderId, string $exchangeOrderId, Exchange $exchange): void
    {
        $intent = (new OrderIntent())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_HEDGE)
            ->setSize(1)
            ->setClientOrderId($clientOrderId)
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setStatus(OrderIntent::STATUS_SENT)
            ->setExchangeOrderId($exchangeOrderId)
            ->setInternalTradeId($internalTradeId);
        $lineage = (new TradeLineage($internalTradeId, $clientOrderId, 'BTCUSDT'))
            ->setOrderIntent($intent)
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSide('LONG')
            ->setOrigin('orchestrator')
            ->setExchangeOrderId($exchangeOrderId);

        $this->em->persist($intent);
        $this->em->persist($lineage);
        $this->em->flush();
    }

    /** @param array<string,mixed> $metadata */
    private function fill(
        Exchange $exchange = Exchange::FAKE,
        string $exchangeOrderId = 'EX-CONTRACT-FILL',
        ?string $clientOrderId = 'cid-contract-fill',
        string $fillId = 'fill-id',
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        float $quantity = 1.0,
        float $price = 100.0,
        float $fee = 0.05,
        array $metadata = [],
        ?\DateTimeImmutable $filledAt = null,
    ): ExchangeFillDto {
        return new ExchangeFillDto(
            exchange: $exchange,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $exchangeOrderId,
            clientOrderId: $clientOrderId,
            fillId: $fillId,
            side: $side,
            positionSide: ExchangePositionSide::LONG,
            quantity: $quantity,
            price: $price,
            fee: $fee,
            feeCurrency: 'USDT',
            filledAt: $filledAt ?? new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
            metadata: $metadata,
        );
    }
}
