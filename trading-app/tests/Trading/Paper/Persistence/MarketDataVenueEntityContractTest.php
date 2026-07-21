<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use App\Entity\FillCostLedgerEntry;
use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Entity\TradeZoneEvent;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MarketDataVenueEntityContractTest extends TestCase
{
    public function testLegacyConstructorsDefaultMarketDataVenueToNullWithoutChangingExchange(): void
    {
        foreach ($this->entities() as $entity) {
            self::assertTrue(method_exists($entity, 'getMarketDataVenue'));
            self::assertNull($entity->getMarketDataVenue());
            self::assertSame('bitmart', $entity->getExchange());
        }
    }

    public function testSetterAcceptsEnumNormalizedStringsAndNullAndReturnsSelf(): void
    {
        foreach ($this->entities() as $entity) {
            self::assertTrue(method_exists($entity, 'setMarketDataVenue'));

            self::assertSame($entity, $entity->setMarketDataVenue(PaperMarketDataVenue::OKX));
            self::assertSame('okx', $entity->getMarketDataVenue());

            self::assertSame($entity, $entity->setMarketDataVenue('  HyPeRlIqUiD  '));
            self::assertSame('hyperliquid', $entity->getMarketDataVenue());

            self::assertSame($entity, $entity->setMarketDataVenue(' OKX '));
            self::assertSame('okx', $entity->getMarketDataVenue());

            self::assertSame($entity, $entity->setMarketDataVenue(null));
            self::assertNull($entity->getMarketDataVenue());
            self::assertSame('bitmart', $entity->getExchange());
        }
    }

    public function testSetterRejectsBlankAndUnsupportedValuesWithStableError(): void
    {
        foreach (['', '   ', 'bitmart', 'coinbase'] as $invalidVenue) {
            foreach ($this->entities() as $entity) {
                self::assertTrue(method_exists($entity, 'setMarketDataVenue'));

                try {
                    $entity->setMarketDataVenue($invalidVenue);
                    self::fail(sprintf('%s accepted invalid market-data venue %s.', $entity::class, var_export($invalidVenue, true)));
                } catch (\InvalidArgumentException $exception) {
                    self::assertSame('market_data_venue_invalid', $exception->getMessage());
                }
            }
        }
    }

    public function testDoctrineMappingUsesNullableVarchar32Column(): void
    {
        foreach ($this->entityClasses() as $entityClass) {
            self::assertTrue(property_exists($entityClass, 'marketDataVenue'), $entityClass);
            $property = new \ReflectionProperty($entityClass, 'marketDataVenue');
            $attributes = $property->getAttributes(Column::class);

            self::assertCount(1, $attributes, $entityClass);
            $column = $attributes[0]->newInstance();
            self::assertSame('market_data_venue', $column->name, $entityClass);
            self::assertSame(Types::STRING, $column->type, $entityClass);
            self::assertSame(32, $column->length, $entityClass);
            self::assertTrue($column->nullable, $entityClass);
        }
    }

    /** @return list<OrderIntent|TradeLineage|TradeLifecycleEvent|FillCostLedgerEntry|TradeZoneEvent> */
    private function entities(): array
    {
        $occurredAt = new \DateTimeImmutable('2026-07-19 12:00:00+00');

        return [
            new OrderIntent(),
            new TradeLineage('trade-1', 'client-1', 'BTCUSDT'),
            new TradeLifecycleEvent('BTCUSDT', 'order_submitted', $occurredAt),
            new FillCostLedgerEntry(
                'fill-cost-1',
                str_repeat('a', 64),
                'bitmart',
                'perpetual',
                'BTCUSDT',
                'fill-1',
                'entry',
                $occurredAt,
                'paper',
                'v1',
            ),
            new TradeZoneEvent('BTCUSDT', 'inside_zone', 99.0, 101.0, 100.0, 0.01, 0.02, $occurredAt),
        ];
    }

    /** @return list<class-string> */
    private function entityClasses(): array
    {
        return [
            OrderIntent::class,
            TradeLineage::class,
            TradeLifecycleEvent::class,
            FillCostLedgerEntry::class,
            TradeZoneEvent::class,
        ];
    }
}
