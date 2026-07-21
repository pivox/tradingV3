<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Runtime;

use App\Trading\Paper\Runtime\DoctrinePaperDatabaseInspector;
use App\Trading\Paper\Runtime\PaperDatabaseInspection;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Version\MigrationStatusCalculator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrinePaperDatabaseInspector::class)]
#[CoversClass(PaperDatabaseInspection::class)]
final class DoctrinePaperDatabaseInspectorTest extends TestCase
{
    public function testItInspectsConnectedDatabaseAndPendingMigrationsWithoutReadingDsn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT current_database()')
            ->willReturn('feature_paper_test');
        $connection->expects(self::never())->method('getParams');

        $statusCalculator = $this->createMock(MigrationStatusCalculator::class);
        $statusCalculator->expects(self::once())
            ->method('getNewMigrations')
            ->willReturn(new AvailableMigrationsList([
                $this->availableMigration('Version202607190001'),
                $this->availableMigration('Version202607190002'),
            ]));
        $dependencyFactory = $this->createMock(DependencyFactory::class);
        $dependencyFactory->expects(self::once())
            ->method('getMigrationStatusCalculator')
            ->willReturn($statusCalculator);

        $inspection = (new DoctrinePaperDatabaseInspector($connection, $dependencyFactory))->inspect();

        self::assertInstanceOf(PaperDatabaseInspection::class, $inspection);
        self::assertSame('feature_paper_test', $inspection->databaseName);
        self::assertSame(2, $inspection->pendingMigrations);
    }

    public function testInspectionFailureDoesNotExposeConnectionDetails(): void
    {
        $dsn = 'postgresql://paper_user:secret@database/trading_app';
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT current_database()')
            ->willThrowException(new \RuntimeException($dsn));
        $connection->expects(self::never())->method('getParams');
        $dependencyFactory = $this->createMock(DependencyFactory::class);
        $dependencyFactory->expects(self::never())->method('getMigrationStatusCalculator');

        try {
            (new DoctrinePaperDatabaseInspector($connection, $dependencyFactory))->inspect();
            self::fail('A failed database inspection must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_database_inspection_failed', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($dsn, $exception->getMessage());
        }
    }

    private function availableMigration(string $version): AvailableMigration
    {
        return new AvailableMigration(
            new Version($version),
            $this->createStub(AbstractMigration::class),
        );
    }
}
