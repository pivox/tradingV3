<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\FillCostLedgerEntry;
use App\Exchange\Dto\ExchangeFundingDto;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\ExchangeFundingReceived;
use App\Repository\FillCostLedgerEntryRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Pnl\FillQuantityAggregationService;
use App\Trading\Pnl\FillCostLedgerIngestionConflict;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use App\Trading\Pnl\NetPnlCertificationService;
use App\Trading\Pnl\TradeCosts;
use App\Trading\Pnl\TradeFill;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FillCostLedgerIngestionService::class)]
#[CoversClass(FillCostLedgerEntry::class)]
final class FillCostLedgerFundingIngestionTest extends TestCase
{
    public function testPersistsSignedKnownCurrencyAndReplaysExactDeadlineOnce(): void
    {
        $saved = null;
        $keys = [];
        $repository = $this->createMock(FillCostLedgerEntryRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findOneByIdempotencyKey')
            ->willReturnCallback(static function (string $key) use (&$saved, &$keys): ?FillCostLedgerEntry {
                $keys[] = $key;

                return $saved;
            });
        $repository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$saved): void {
                $saved = $entry;
            });

        $service = new FillCostLedgerIngestionService($repository, $this->lineageManager());
        $event = $this->event(amount: '-2.000000000000', amountUsdt: '-2.000000000000');
        $first = $service->ingestFunding($event);
        $replay = $service->ingestFunding($event);

        self::assertTrue($first->inserted);
        self::assertTrue($replay->replayed);
        self::assertSame($keys[0], $keys[1]);
        self::assertStringStartsWith('fake:perpetual:funding:', $keys[0]);
        self::assertInstanceOf(FillCostLedgerEntry::class, $saved);
        self::assertSame('funding', $saved->getFillRole());
        self::assertSame('-2.000000000000', $saved->getFundingUsdt());
        self::assertSame('itd-funding-ledger', $saved->getInternalTradeId());
        self::assertSame('fake-position-long', $saved->getPositionId());
        self::assertNull($saved->getExchangeFillId());
        self::assertNull($saved->getPrice());
        self::assertNull($saved->getQuantity());

        $aggregation = (new FillQuantityAggregationService())->aggregateEntries(
            [$saved],
            'itd-funding-ledger',
            'fake',
            'perpetual',
        );
        self::assertSame(-2.0, $aggregation->fundingUsdt);
        $net = (new NetPnlCertificationService())->certify(
            entryFills: [new TradeFill('entry', 'BUY', 1.0, 100.0, 0.01, 'USDT', 'maker', new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))],
            exitFills: [new TradeFill('exit', 'SELL', 1.0, 110.0, 0.01, 'USDT', 'taker', new \DateTimeImmutable('2026-01-01T09:00:00+00:00'))],
            costs: new TradeCosts(0.0, $aggregation->fundingUsdt, 0.0, 0.0, 0.0, 0.0),
            side: 'LONG',
            positionFullyClosed: true,
            lineageSufficient: true,
            identifierConflict: false,
        );
        self::assertTrue($net->certified);
        self::assertSame(7.98, $net->netPnlUsdt, 'The replayed debit must affect net PnL exactly once.');
    }

    public function testUnknownCurrencyPersistsUnknownUsdtInsteadOfZero(): void
    {
        $saved = null;
        $repository = $this->createMock(FillCostLedgerEntryRepository::class);
        $repository->method('findOneByIdempotencyKey')->willReturn(null);
        $repository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$saved): void {
                $saved = $entry;
            });

        (new FillCostLedgerIngestionService($repository, $this->lineageManager()))->ingestFunding(
            $this->event(amount: '-2.000000000000', currency: 'EUR', amountUsdt: null),
        );

        self::assertInstanceOf(FillCostLedgerEntry::class, $saved);
        self::assertNull($saved->getFundingUsdt());
        self::assertContains('funding_currency_not_normalized', $saved->getQualityFlags());
        self::assertSame('EUR', $saved->getRawReference()['currency'] ?? null);
        self::assertSame('-2.000000000000', $saved->getRawReference()['native_amount'] ?? null);
    }

    public function testOutOfOrderDeadlinesUseDifferentExactKeys(): void
    {
        /** @var array<string,FillCostLedgerEntry> $entries */
        $entries = [];
        $repository = $this->createMock(FillCostLedgerEntryRepository::class);
        $repository->method('findOneByIdempotencyKey')
            ->willReturnCallback(static function (string $key) use (&$entries): ?FillCostLedgerEntry {
                return $entries[$key] ?? null;
            });
        $repository->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$entries): void {
                $entries[$entry->getIdempotencyKey()] = $entry;
            });
        $service = new FillCostLedgerIngestionService($repository, $this->lineageManager());

        $service->ingestFunding($this->event(dueAt: new \DateTimeImmutable('2026-01-01T08:00:00+00:00')));
        $service->ingestFunding($this->event(dueAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00')));

        self::assertCount(2, $entries);
        self::assertSame(
            ['2026-01-01T08:00:00+00:00', '2026-01-01T00:00:00+00:00'],
            array_values(array_map(static fn (FillCostLedgerEntry $entry): string => $entry->getOccurredAt()->format(\DateTimeInterface::ATOM), $entries)),
        );
    }

    public function testSamePositionDeadlineAndModelWithDifferentAmountConflicts(): void
    {
        $saved = null;
        $repository = $this->createMock(FillCostLedgerEntryRepository::class);
        $repository->method('findOneByIdempotencyKey')
            ->willReturnCallback(static function () use (&$saved): ?FillCostLedgerEntry {
                return $saved;
            });
        $repository->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$saved): void {
                $saved = $entry;
            });
        $service = new FillCostLedgerIngestionService($repository, $this->lineageManager());
        $service->ingestFunding($this->event(amount: '-1.000000000000', amountUsdt: '-1.000000000000'));

        $this->expectException(FillCostLedgerIngestionConflict::class);
        $service->ingestFunding($this->event(amount: '-2.000000000000', amountUsdt: '-2.000000000000'));
    }

    public function testSameUnknownCurrencyIdentityWithDifferentNativeAmountConflicts(): void
    {
        $saved = null;
        $repository = $this->createMock(FillCostLedgerEntryRepository::class);
        $repository->method('findOneByIdempotencyKey')
            ->willReturnCallback(static function () use (&$saved): ?FillCostLedgerEntry {
                return $saved;
            });
        $repository->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$saved): void {
                $saved = $entry;
            });
        $service = new FillCostLedgerIngestionService($repository, $this->lineageManager());
        $service->ingestFunding($this->event(amount: '-1.000000000000', currency: 'EUR', amountUsdt: null));

        $this->expectException(FillCostLedgerIngestionConflict::class);
        $service->ingestFunding($this->event(amount: '-2.000000000000', currency: 'EUR', amountUsdt: null));
    }

    private function event(
        string $amount = '2.000000000000',
        string $currency = 'USDT',
        ?string $amountUsdt = '2.000000000000',
        ?\DateTimeImmutable $dueAt = null,
    ): ExchangeFundingReceived {
        $funding = new ExchangeFundingDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            positionSide: ExchangePositionSide::LONG,
            positionId: 'fake-position-long',
            internalTradeId: 'itd-funding-ledger',
            internalPositionId: 'ipos-funding-ledger',
            notional: '20000.000000000000',
            fundingRate: '0.0001',
            rateIntervalSeconds: 28800,
            appliedIntervalSeconds: 28800,
            amount: $amount,
            currency: $currency,
            amountUsdt: $amountUsdt,
            dueAt: $dueAt ?? new \DateTimeImmutable('2026-01-01T08:00:00+00:00'),
            source: 'fake_funding_model',
            modelVersion: 'fake-funding-notional-rate-interval-v1',
        );

        return new ExchangeFundingReceived($funding, [
            'funding_idempotency_key' => 'fake-state-key',
            'funding_payload_hash' => str_repeat('a', 64),
        ]);
    }

    private function lineageManager(): TradeLineageManager
    {
        $persister = $this->createStub(EntityPersister::class);
        $persister->method('load')->willReturn(null);
        $persister->method('loadAll')->willReturn([]);
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getEntityPersister')->willReturn($persister);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn(new ClassMetadata(\App\Entity\TradeLineage::class));
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new TradeLineageManager(
            new TradeLineageRepository($registry),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }
}
