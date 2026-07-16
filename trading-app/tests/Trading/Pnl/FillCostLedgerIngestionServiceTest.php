<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\OrderIntent;
use App\Entity\TradeLineage;
use App\Entity\FillCostLedgerEntry;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\ExchangeFillReceived;
use App\Repository\FillCostLedgerEntryRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Pnl\FillCostLedgerIngestionConflict;
use App\Trading\Pnl\FillCostLedgerIngestionResult;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(FillCostLedgerIngestionService::class)]
#[CoversClass(FillCostLedgerEntry::class)]
final class FillCostLedgerIngestionServiceTest extends KernelTestCase
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
        $classes = [
            $this->em->getClassMetadata(OrderIntent::class),
            $this->em->getClassMetadata(TradeLineage::class),
            $this->em->getClassMetadata(FillCostLedgerEntry::class),
        ];
        $tool->dropSchema($classes);
        $tool->createSchema($classes);

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
            (new SchemaTool($this->em))->dropSchema([
                $this->em->getClassMetadata(OrderIntent::class),
                $this->em->getClassMetadata(TradeLineage::class),
                $this->em->getClassMetadata(FillCostLedgerEntry::class),
            ]);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testPersistsFakeEntryFillWithExactLineageAndKnownUsdtFee(): void
    {
        $this->persistLineage('itd-ledger-1', 'cid-ledger-1', 'EX-LEDGER-1', 'ipos-ledger-1');

        $result = $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-LEDGER-1',
            clientOrderId: 'cid-ledger-1',
            fillId: 'fake-fill-entry-maker',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            quantity: 2.5,
            price: 100.0,
            fee: 0.125,
            feeCurrency: 'USDT',
            metadata: [
                'liquidity_role' => 'maker',
                'source' => 'fake_exchange_ws',
                'spread_cost_usdt' => 0.0,
                'slippage_cost_usdt' => 0.0125,
                'cost_model_version' => 'fixed_adverse_slippage_bps_v1',
                'spread_model_version' => 'top_of_book_embedded_spread_v1',
            ],
        )));

        self::assertTrue($result->inserted);
        self::assertFalse($result->replayed);
        self::assertNull($result->conflictReason);

        $entries = $this->ledger->findByInternalTradeId('itd-ledger-1');
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('itd-ledger-1', $entry->getInternalTradeId());
        self::assertSame('ipos-ledger-1', $entry->getInternalPositionId());
        self::assertSame('fake', $entry->getExchange());
        self::assertSame('perpetual', $entry->getMarketType());
        self::assertSame('BTCUSDT', $entry->getSymbol());
        self::assertSame('BUY', $entry->getSide());
        self::assertSame('entry', $entry->getFillRole());
        self::assertSame('maker', $entry->getLiquidityRole());
        self::assertSame('250.000000000000', $entry->getNotional());
        self::assertSame('0.125000000000', $entry->getFeeAmount());
        self::assertSame('USDT', $entry->getFeeCurrency());
        self::assertSame('0.125000000000', $entry->getFeeUsdt());
        self::assertSame('0.000000000000', $entry->getSpreadCostUsdt());
        self::assertSame('0.012500000000', $entry->getSlippageCostUsdt());
        self::assertSame([], $entry->getQualityFlags());
        self::assertSame([
            'source' => 'fake_exchange_ws',
            'event_type' => 'exchange.fill.received',
            'exchange_fill_id' => 'fake-fill-entry-maker',
            'exchange_order_id' => 'EX-LEDGER-1',
            'client_order_id' => 'cid-ledger-1',
        ], $entry->getRawReference());
    }

    public function testReplayOfSameExchangeFillIsIdempotent(): void
    {
        $this->persistLineage('itd-ledger-replay', 'cid-replay', 'EX-REPLAY', null);
        $event = new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-REPLAY',
            clientOrderId: 'cid-replay',
            fillId: 'fake-fill-replay',
        ));

        $first = $this->service->ingestExchangeFill($event);
        $second = $this->service->ingestExchangeFill($event);

        self::assertTrue($first->inserted);
        self::assertTrue($second->replayed);
        self::assertFalse($second->inserted);
        self::assertSame(1, $this->ledger->count([]));
    }

    public function testReplayIgnoresMutableProjectionSourceAndLateLineageEnrichment(): void
    {
        $event = new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-LATE-LINEAGE',
            clientOrderId: 'cid-late-lineage',
            fillId: 'fake-fill-late-lineage',
            metadata: ['source' => 'rest_reconciliation'],
        ));
        $this->service->ingestExchangeFill($event);

        $this->persistLineage('itd-late-lineage', 'cid-late-lineage', 'EX-LATE-LINEAGE', null);
        $replay = $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-LATE-LINEAGE',
            clientOrderId: 'cid-late-lineage',
            fillId: 'fake-fill-late-lineage',
            metadata: ['source' => 'fake_exchange_ws'],
        )));

        self::assertTrue($replay->replayed);
        self::assertFalse($replay->inserted);
        self::assertSame(1, $this->ledger->count([]));
        $entry = $this->ledger->findOneByIdempotencyKey('fake:perpetual:exchange_fill:fake-fill-late-lineage');
        self::assertInstanceOf(FillCostLedgerEntry::class, $entry);
        self::assertNull($entry->getInternalTradeId());
        self::assertContains('missing_lineage', $entry->getQualityFlags());
    }

    public function testLineageFallsBackToExchangeOrderIdWhenClientOrderIdIsUnknown(): void
    {
        $this->persistLineage('itd-ledger-fallback', 'cid-ledger-fallback-real', 'EX-FALLBACK-LINEAGE', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-FALLBACK-LINEAGE',
            clientOrderId: 'stale-client-id',
            fillId: 'fill-fallback-lineage',
        )));

        $entry = $this->ledger->findOneByIdempotencyKey('fake:perpetual:exchange_fill:fill-fallback-lineage');
        self::assertInstanceOf(FillCostLedgerEntry::class, $entry);
        self::assertSame('itd-ledger-fallback', $entry->getInternalTradeId());
        self::assertSame([], $entry->getQualityFlags());
    }

    public function testReplayWithDifferentResolvedLineageIsRejectedAsConflict(): void
    {
        $this->persistLineage('itd-ledger-lineage-a', 'cid-lineage-a', 'EX-LINEAGE-CONFLICT', null);
        $this->persistLineage('itd-ledger-lineage-b', 'cid-lineage-b', 'EX-LINEAGE-OTHER', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-LINEAGE-CONFLICT',
            clientOrderId: 'cid-lineage-a',
            fillId: 'fill-lineage-conflict',
        )));

        $this->expectException(FillCostLedgerIngestionConflict::class);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-LINEAGE-CONFLICT',
            clientOrderId: 'cid-lineage-a',
            fillId: 'fill-lineage-conflict',
            metadata: ['internal_trade_id' => 'itd-ledger-lineage-b'],
        )));
    }

    public function testConcurrentDuplicateInsertIsReturnedAsReplayWhenStoredPayloadMatches(): void
    {
        $existing = null;

        $repository = $this->createMock(FillCostLedgerEntryRepository::class);
        $repository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('fake:perpetual:exchange_fill:fill-concurrent')
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$existing): void {
                $existing = new FillCostLedgerEntry(
                    idempotencyKey: $entry->getIdempotencyKey(),
                    payloadHash: $entry->getPayloadHash(),
                    exchange: $entry->getExchange(),
                    marketType: $entry->getMarketType(),
                    symbol: $entry->getSymbol(),
                    fillId: $entry->getFillId(),
                    fillRole: $entry->getFillRole(),
                    occurredAt: $entry->getOccurredAt(),
                    source: $entry->getSource(),
                    sourceVersion: $entry->getSourceVersion(),
                );

                throw new UniqueConstraintViolationException(new class('duplicate') extends \Exception implements \Doctrine\DBAL\Driver\Exception {
                    public function getSQLState(): ?string
                    {
                        return '23505';
                    }
                }, null);
            });
        $repository->expects(self::once())
            ->method('resetManagerAndFindOneByIdempotencyKey')
            ->with('fake:perpetual:exchange_fill:fill-concurrent')
            ->willReturnCallback(static function () use (&$existing): ?FillCostLedgerEntry {
                return $existing;
            });

        /** @var TradeLineageRepository $lineages */
        $lineages = $this->em->getRepository(TradeLineage::class);
        $service = new FillCostLedgerIngestionService(
            $repository,
            new TradeLineageManager($lineages, $this->em, new NullLogger()),
        );

        $event = new ExchangeFillReceived($this->fill(fillId: 'fill-concurrent'));
        $expected = $service->ingestExchangeFill($event);

        self::assertInstanceOf(FillCostLedgerIngestionResult::class, $expected);
        self::assertTrue($expected->replayed);
        self::assertFalse($expected->inserted);
    }

    public function testSameExchangeFillIdWithDifferentPayloadIsRejectedAsConflict(): void
    {
        $this->persistLineage('itd-ledger-conflict', 'cid-conflict', 'EX-CONFLICT', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-CONFLICT',
            clientOrderId: 'cid-conflict',
            fillId: 'fake-fill-conflict',
            price: 100.0,
        )));

        $this->expectException(FillCostLedgerIngestionConflict::class);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-CONFLICT',
            clientOrderId: 'cid-conflict',
            fillId: 'fake-fill-conflict',
            price: 101.0,
        )));
    }

    public function testSameExchangeFillIdWithDifferentSlippageIsRejectedAsConflict(): void
    {
        $this->persistLineage('itd-ledger-cost-conflict', 'cid-cost-conflict', 'EX-COST-CONFLICT', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-COST-CONFLICT',
            clientOrderId: 'cid-cost-conflict',
            fillId: 'fake-fill-cost-conflict',
            metadata: ['slippage_cost_usdt' => 0.05],
        )));

        $this->expectException(FillCostLedgerIngestionConflict::class);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-COST-CONFLICT',
            clientOrderId: 'cid-cost-conflict',
            fillId: 'fake-fill-cost-conflict',
            metadata: ['slippage_cost_usdt' => 0.06],
        )));
    }

    public function testInvalidExplicitCostsRemainNullAndAreFlagged(): void
    {
        $this->persistLineage('itd-ledger-invalid-costs', 'cid-invalid-costs', 'EX-INVALID-COSTS', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-INVALID-COSTS',
            clientOrderId: 'cid-invalid-costs',
            fillId: 'fake-fill-invalid-costs',
            metadata: [
                'spread_cost_usdt' => -0.01,
                'slippage_cost_usdt' => 'not-a-number',
            ],
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-invalid-costs')[0];
        self::assertNull($entry->getSpreadCostUsdt());
        self::assertNull($entry->getSlippageCostUsdt());
        self::assertContains('spread_cost_invalid', $entry->getQualityFlags());
        self::assertContains('slippage_cost_invalid', $entry->getQualityFlags());
    }

    public function testVenueScopedExchangeFillIdsCanBeReusedAcrossExchanges(): void
    {
        $this->persistLineage('itd-fake-shared', 'cid-fake-shared', 'EX-SHARED-FILL', null, exchange: Exchange::FAKE);
        $this->persistLineage('itd-okx-shared', 'cid-okx-shared', 'OKX-ORDER-SHARED', null, exchange: Exchange::OKX);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchange: Exchange::FAKE,
            exchangeOrderId: 'EX-SHARED-FILL',
            clientOrderId: 'cid-fake-shared',
            fillId: 'venue-reused-fill-id',
        )));
        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchange: Exchange::OKX,
            exchangeOrderId: 'OKX-ORDER-SHARED',
            clientOrderId: 'cid-okx-shared',
            fillId: 'venue-reused-fill-id',
        )));

        self::assertCount(1, $this->ledger->findByInternalTradeId('itd-fake-shared'));
        self::assertCount(1, $this->ledger->findByInternalTradeId('itd-okx-shared'));
    }

    public function testMissingLineageIsPersistedWithQualityFlagWithoutSymbolTimeFallback(): void
    {
        $result = $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-NO-LINEAGE',
            clientOrderId: 'cid-no-lineage',
            fillId: 'fake-fill-no-lineage',
        )));

        self::assertTrue($result->inserted);
        $entry = $this->ledger->findOneByIdempotencyKey('fake:perpetual:exchange_fill:fake-fill-no-lineage');
        self::assertInstanceOf(FillCostLedgerEntry::class, $entry);
        self::assertNull($entry->getInternalTradeId());
        self::assertContains('missing_lineage', $entry->getQualityFlags());
    }

    public function testPartialFillsCreateSeparateLedgerRowsForSameTrade(): void
    {
        $this->persistLineage('itd-ledger-partial', 'cid-partial', 'EX-PARTIAL', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-PARTIAL',
            clientOrderId: 'cid-partial',
            fillId: 'fill-partial-1',
            quantity: 0.4,
            price: 100.0,
        )));
        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-PARTIAL',
            clientOrderId: 'cid-partial',
            fillId: 'fill-partial-2',
            quantity: 0.6,
            price: 101.0,
        )));

        $entries = $this->ledger->findByInternalTradeId('itd-ledger-partial');
        self::assertCount(2, $entries);
        self::assertSame(['0.400000000000', '0.600000000000'], array_map(
            static fn (FillCostLedgerEntry $entry): ?string => $entry->getQuantity(),
            $entries,
        ));
    }

    public function testExitTakerFillIsClassifiedWithoutChangingCosts(): void
    {
        $this->persistLineage('itd-ledger-exit', 'cid-exit', 'EX-EXIT', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-EXIT',
            clientOrderId: 'cid-exit',
            fillId: 'fill-exit-taker',
            side: ExchangeOrderSide::SELL,
            positionSide: ExchangePositionSide::LONG,
            quantity: 1.0,
            price: 110.0,
            fee: 0.055,
            feeCurrency: 'USDT',
            metadata: ['liquidity_role' => 'taker'],
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-exit')[0];
        self::assertSame('exit', $entry->getFillRole());
        self::assertSame('taker', $entry->getLiquidityRole());
        self::assertSame('0.055000000000', $entry->getFeeUsdt());
    }

    public function testLedgerCanBeReadAfterEntityManagerClear(): void
    {
        $this->persistLineage('itd-ledger-reread', 'cid-reread', 'EX-REREAD', null);
        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-REREAD',
            clientOrderId: 'cid-reread',
            fillId: 'fill-reread',
        )));

        $this->em->clear();

        /** @var FillCostLedgerEntryRepository $freshRepository */
        $freshRepository = $this->em->getRepository(FillCostLedgerEntry::class);
        $entries = $freshRepository->findByInternalTradeId('itd-ledger-reread');
        self::assertCount(1, $entries);
        self::assertSame('fill-reread', $entries[0]->getFillId());
    }

    public function testOutOfOrderEventsAreStoredAndReadDeterministicallyByOccurrenceTime(): void
    {
        $this->persistLineage('itd-ledger-ordered', 'cid-ordered', 'EX-ORDERED', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-ORDERED',
            clientOrderId: 'cid-ordered',
            fillId: 'fill-late',
            metadata: ['filled_at_override' => 'ignored'],
            filledAt: new \DateTimeImmutable('2026-01-01 00:02:00 UTC'),
        )));
        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-ORDERED',
            clientOrderId: 'cid-ordered',
            fillId: 'fill-early',
            filledAt: new \DateTimeImmutable('2026-01-01 00:01:00 UTC'),
        )));

        $entries = $this->ledger->findByInternalTradeId('itd-ledger-ordered');
        self::assertSame(['fill-early', 'fill-late'], array_map(
            static fn (FillCostLedgerEntry $entry): string => $entry->getFillId(),
            $entries,
        ));
    }

    public function testWrongVenueDoesNotAttachLineageWithSameClientOrderId(): void
    {
        $this->persistLineage('itd-ledger-wrong-venue', 'cid-same-venue-test', 'EX-FAKE-VENUE', null, exchange: Exchange::FAKE);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchange: Exchange::OKX,
            exchangeOrderId: 'OKX-ORDER-VENUE',
            clientOrderId: 'cid-same-venue-test',
            fillId: 'okx-fill-wrong-venue',
        )));

        $entry = $this->ledger->findOneByIdempotencyKey('okx:perpetual:exchange_fill:okx-fill-wrong-venue');
        self::assertInstanceOf(FillCostLedgerEntry::class, $entry);
        self::assertNull($entry->getInternalTradeId());
        self::assertContains('missing_lineage', $entry->getQualityFlags());
    }

    public function testKnownNonUsdtFeeRequiresExplicitConversionRate(): void
    {
        $this->persistLineage('itd-ledger-bnb-fee', 'cid-bnb-fee', 'EX-BNB-FEE', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-BNB-FEE',
            clientOrderId: 'cid-bnb-fee',
            fillId: 'fill-bnb-fee',
            fee: 0.01,
            feeCurrency: 'BNB',
            metadata: ['fee_conversion' => ['currency' => 'BNB', 'usdt_rate' => 600.0]],
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-bnb-fee')[0];
        self::assertSame('BNB', $entry->getFeeCurrency());
        self::assertSame('6.000000000000', $entry->getFeeUsdt());
        self::assertSame([], $entry->getQualityFlags());
    }

    public function testProviderSignedUsdtFeeIsNormalizedAsPositiveCost(): void
    {
        $this->persistLineage('itd-ledger-negative-usdt-fee', 'cid-negative-usdt-fee', 'EX-NEG-USDT-FEE', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-NEG-USDT-FEE',
            clientOrderId: 'cid-negative-usdt-fee',
            fillId: 'fill-negative-usdt-fee',
            fee: -0.02,
            feeCurrency: 'USDT',
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-negative-usdt-fee')[0];
        self::assertSame('-0.020000000000', $entry->getFeeAmount());
        self::assertSame('USDT', $entry->getFeeCurrency());
        self::assertSame('0.020000000000', $entry->getFeeUsdt());
        self::assertSame([], $entry->getQualityFlags());
    }

    public function testProviderSignedNonUsdtFeeIsNormalizedAfterExplicitConversion(): void
    {
        $this->persistLineage('itd-ledger-negative-bnb-fee', 'cid-negative-bnb-fee', 'EX-NEG-BNB-FEE', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-NEG-BNB-FEE',
            clientOrderId: 'cid-negative-bnb-fee',
            fillId: 'fill-negative-bnb-fee',
            fee: -0.01,
            feeCurrency: 'BNB',
            metadata: ['fee_conversion' => ['currency' => 'BNB', 'usdt_rate' => 600.0]],
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-negative-bnb-fee')[0];
        self::assertSame('-0.010000000000', $entry->getFeeAmount());
        self::assertSame('BNB', $entry->getFeeCurrency());
        self::assertSame('6.000000000000', $entry->getFeeUsdt());
        self::assertSame([], $entry->getQualityFlags());
    }

    public function testExplicitZeroFeeIsKnownEvenWhenCurrencyIsMissing(): void
    {
        $this->persistLineage('itd-ledger-zero-fee', 'cid-zero-fee', 'EX-ZERO-FEE', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-ZERO-FEE',
            clientOrderId: 'cid-zero-fee',
            fillId: 'fill-zero-fee',
            fee: 0.0,
            feeCurrency: null,
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-zero-fee')[0];
        self::assertSame('0.000000000000', $entry->getFeeAmount());
        self::assertNull($entry->getFeeCurrency());
        self::assertSame('0.000000000000', $entry->getFeeUsdt());
        self::assertSame([], $entry->getQualityFlags());
    }

    public function testUnknownNonUsdtFeeDoesNotBecomeZero(): void
    {
        $this->persistLineage('itd-ledger-unknown-fee', 'cid-unknown-fee', 'EX-UNKNOWN-FEE', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-UNKNOWN-FEE',
            clientOrderId: 'cid-unknown-fee',
            fillId: 'fill-unknown-fee',
            fee: 0.01,
            feeCurrency: 'BNB',
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-unknown-fee')[0];
        self::assertSame('0.010000000000', $entry->getFeeAmount());
        self::assertSame('BNB', $entry->getFeeCurrency());
        self::assertNull($entry->getFeeUsdt());
        self::assertContains('fee_conversion_missing', $entry->getQualityFlags());
    }

    public function testInvalidNonUsdtFeeConversionRateIsRejected(): void
    {
        $this->persistLineage('itd-ledger-invalid-fee-rate', 'cid-invalid-fee-rate', 'EX-INVALID-FEE-RATE', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-INVALID-FEE-RATE',
            clientOrderId: 'cid-invalid-fee-rate',
            fillId: 'fill-invalid-fee-rate',
            fee: 0.01,
            feeCurrency: 'BNB',
            metadata: ['fee_conversion' => ['currency' => 'BNB', 'usdt_rate' => 0.0]],
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-invalid-fee-rate')[0];
        self::assertSame('0.010000000000', $entry->getFeeAmount());
        self::assertSame('BNB', $entry->getFeeCurrency());
        self::assertNull($entry->getFeeUsdt());
        self::assertContains('fee_conversion_invalid', $entry->getQualityFlags());
    }

    public function testAbsentFeeRemainsNullAndIsFlagged(): void
    {
        $this->persistLineage('itd-ledger-no-fee', 'cid-no-fee', 'EX-NO-FEE', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-NO-FEE',
            clientOrderId: 'cid-no-fee',
            fillId: 'fill-no-fee',
            fee: null,
            feeCurrency: null,
        )));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-no-fee')[0];
        self::assertNull($entry->getFeeAmount());
        self::assertNull($entry->getFeeCurrency());
        self::assertNull($entry->getFeeUsdt());
        self::assertContains('fee_missing', $entry->getQualityFlags());
    }

    public function testFundingAdjustmentsCanBePersistedWithPositiveAndNegativeSigns(): void
    {
        $this->persistLineage('itd-ledger-funding', 'cid-funding', 'EX-FUNDING', null);

        $positive = $this->service->ingestFundingAdjustment(
            internalTradeId: 'itd-ledger-funding',
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            fundingUsdt: 1.25,
            occurredAt: new \DateTimeImmutable('2026-01-01 00:10:00 UTC'),
            source: 'fake_fixture',
            sourceVersion: 'v1',
            rawReference: ['funding_event_id' => 'funding-positive'],
        );
        $negative = $this->service->ingestFundingAdjustment(
            internalTradeId: 'itd-ledger-funding',
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            fundingUsdt: -0.75,
            occurredAt: new \DateTimeImmutable('2026-01-01 00:11:00 UTC'),
            source: 'fake_fixture',
            sourceVersion: 'v1',
            rawReference: ['funding_event_id' => 'funding-negative'],
        );

        self::assertTrue($positive->inserted);
        self::assertTrue($negative->inserted);
        $entries = $this->ledger->findByInternalTradeId('itd-ledger-funding');
        self::assertSame(['1.250000000000', '-0.750000000000'], array_map(
            static fn (FillCostLedgerEntry $entry): ?string => $entry->getFundingUsdt(),
            $entries,
        ));
    }

    public function testRawReferenceIsRedacted(): void
    {
        $this->persistLineage('itd-ledger-redacted', 'cid-redacted', 'EX-REDACTED', null);

        $this->service->ingestExchangeFill(new ExchangeFillReceived($this->fill(
            exchangeOrderId: 'EX-REDACTED',
            clientOrderId: 'cid-redacted',
            fillId: 'fill-redacted',
            metadata: [
                'source' => 'fake_exchange_ws',
                'api_key' => 'SECRET',
                'token' => 'SECRET',
                'nested' => ['secret' => 'SECRET'],
            ],
        ), [
            'secret' => 'SECRET',
            'api_key' => 'SECRET',
        ]));

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-redacted')[0];
        $encoded = json_encode($entry->getRawReference(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SECRET', $encoded);
        self::assertArrayNotHasKey('api_key', $entry->getRawReference());
        self::assertArrayNotHasKey('token', $entry->getRawReference());
    }

    public function testFundingRawReferenceRedactsCommonSecretKeyVariants(): void
    {
        $this->persistLineage('itd-ledger-funding-redacted', 'cid-funding-redacted', 'EX-FUNDING-REDACTED', null);

        $this->service->ingestFundingAdjustment(
            internalTradeId: 'itd-ledger-funding-redacted',
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            fundingUsdt: 0.25,
            occurredAt: new \DateTimeImmutable('2026-01-01 00:12:00 UTC'),
            source: 'fake_fixture',
            sourceVersion: 'v1',
            rawReference: [
                'funding_event_id' => 'funding-redacted',
                'access_token' => 'SECRET',
                'secret_key' => 'SECRET',
                'nested' => [
                    'refreshToken' => 'SECRET',
                    'public_reference' => 'visible',
                ],
            ],
        );

        $entry = $this->ledger->findByInternalTradeId('itd-ledger-funding-redacted')[0];
        $encoded = json_encode($entry->getRawReference(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SECRET', $encoded);
        self::assertSame('visible', $entry->getRawReference()['nested']['public_reference'] ?? null);
        self::assertArrayNotHasKey('access_token', $entry->getRawReference());
        self::assertArrayNotHasKey('secret_key', $entry->getRawReference());
    }

    private function persistLineage(
        string $internalTradeId,
        string $clientOrderId,
        string $exchangeOrderId,
        ?string $internalPositionId,
        Exchange $exchange = Exchange::FAKE,
    ): void {
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
            ->setInternalTradeId($internalTradeId)
            ->setInternalPositionId($internalPositionId);

        $lineage = (new TradeLineage($internalTradeId, $clientOrderId, 'BTCUSDT'))
            ->setOrderIntent($intent)
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSide('LONG')
            ->setOrigin('orchestrator')
            ->setExchangeOrderId($exchangeOrderId)
            ->setInternalPositionId($internalPositionId);

        $this->em->persist($intent);
        $this->em->persist($lineage);
        $this->em->flush();
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function fill(
        Exchange $exchange = Exchange::FAKE,
        string $exchangeOrderId = 'EX-FILL',
        ?string $clientOrderId = 'cid-fill',
        ?string $fillId = 'fill-id',
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
        float $quantity = 1.0,
        float $price = 100.0,
        ?float $fee = 0.05,
        ?string $feeCurrency = 'USDT',
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
            positionSide: $positionSide,
            quantity: $quantity,
            price: $price,
            fee: $fee,
            feeCurrency: $feeCurrency,
            filledAt: $filledAt ?? new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
            metadata: $metadata,
        );
    }
}
