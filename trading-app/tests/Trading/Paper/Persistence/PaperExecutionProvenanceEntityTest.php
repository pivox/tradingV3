<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Persistence;

use App\Entity\FillCostLedgerEntry;
use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Entity\TradeZoneEvent;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PaperExecutionProvenanceEntityTest extends TestCase
{
    /** @var array<string, array{string, int}> */
    private const PROPERTIES = [
        'paperNetwork' => ['paper_network', 16],
        'paperExecutionCellId' => ['paper_execution_cell_id', 71],
        'configurationSnapshotId' => ['configuration_snapshot_id', 71],
        'paperEligibility' => ['paper_eligibility', 32],
    ];

    public function testLegacyEntitiesKeepNullablePaperProvenance(): void
    {
        foreach ($this->entities() as $entity) {
            self::assertNull($entity->getPaperNetwork());
            self::assertNull($entity->getPaperExecutionCellId());
            self::assertNull($entity->getConfigurationSnapshotId());
            self::assertNull($entity->getPaperEligibility());
        }
    }

    public function testPaperProvenanceSettersAreStrictAndFluent(): void
    {
        foreach ($this->entities() as $entity) {
            self::assertSame($entity, $entity->setPaperNetwork('testnet'));
            self::assertSame($entity, $entity->setPaperExecutionCellId('sha256:' . str_repeat('a', 64)));
            self::assertSame($entity, $entity->setConfigurationSnapshotId('sha256:' . str_repeat('b', 64)));
            self::assertSame($entity, $entity->setPaperEligibility('reference_only'));

            self::assertSame('testnet', $entity->getPaperNetwork());
            self::assertSame('sha256:' . str_repeat('a', 64), $entity->getPaperExecutionCellId());
            self::assertSame('sha256:' . str_repeat('b', 64), $entity->getConfigurationSnapshotId());
            self::assertSame('reference_only', $entity->getPaperEligibility());

            self::assertSame($entity, $entity->setPaperEligibility('baseline_eligible'));
            self::assertSame('baseline_eligible', $entity->getPaperEligibility());

            self::assertSame($entity, $entity->setPaperNetwork(null));
            self::assertSame($entity, $entity->setPaperExecutionCellId(null));
            self::assertSame($entity, $entity->setConfigurationSnapshotId(null));
            self::assertSame($entity, $entity->setPaperEligibility(null));
        }
    }

    public function testPaperProvenanceSettersRejectAliasesAndUnsupportedValues(): void
    {
        foreach ($this->entities() as $entity) {
            $this->assertRejected(fn () => $entity->setPaperNetwork('TESTNET'), 'paper_network_invalid');
            $this->assertRejected(fn () => $entity->setPaperNetwork('legacy_unknown'), 'paper_network_invalid');
            $this->assertRejected(fn () => $entity->setPaperExecutionCellId('latest'), 'paper_execution_cell_id_invalid');
            $this->assertRejected(fn () => $entity->setConfigurationSnapshotId('sha256:' . str_repeat('A', 64)), 'configuration_snapshot_id_invalid');
            $this->assertRejected(fn () => $entity->setPaperEligibility('baseline'), 'paper_eligibility_invalid');
        }
    }

    public function testDoctrineMappingsAreNullableVarchars(): void
    {
        foreach ($this->entityClasses() as $entityClass) {
            foreach (self::PROPERTIES as $propertyName => [$columnName, $length]) {
                self::assertTrue(property_exists($entityClass, $propertyName), $entityClass . '::' . $propertyName);
                $attributes = (new \ReflectionProperty($entityClass, $propertyName))->getAttributes(Column::class);
                self::assertCount(1, $attributes, $entityClass . '::' . $propertyName);
                $column = $attributes[0]->newInstance();
                self::assertSame($columnName, $column->name);
                self::assertSame(Types::STRING, $column->type);
                self::assertSame($length, $column->length);
                self::assertTrue($column->nullable);
            }
        }
    }

    private function assertRejected(callable $operation, string $reason): void
    {
        try {
            $operation();
            self::fail('Invalid Paper provenance was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /** @return list<OrderIntent|TradeLineage|TradeLifecycleEvent|FillCostLedgerEntry|TradeZoneEvent> */
    private function entities(): array
    {
        $at = new \DateTimeImmutable('2026-08-01 12:00:00+00');

        return [
            new OrderIntent(),
            new TradeLineage('trade-1', 'client-1', 'BTCUSDT'),
            new TradeLifecycleEvent('BTCUSDT', 'order_submitted', $at),
            new FillCostLedgerEntry('cost-1', str_repeat('a', 64), 'bitmart', 'perpetual', 'BTCUSDT', 'fill-1', 'entry', $at, 'paper', 'v1'),
            new TradeZoneEvent('BTCUSDT', 'inside_zone', 99.0, 101.0, 100.0, 0.01, 0.02, $at),
        ];
    }

    /** @return list<class-string> */
    private function entityClasses(): array
    {
        return [OrderIntent::class, TradeLineage::class, TradeLifecycleEvent::class, FillCostLedgerEntry::class, TradeZoneEvent::class];
    }
}
