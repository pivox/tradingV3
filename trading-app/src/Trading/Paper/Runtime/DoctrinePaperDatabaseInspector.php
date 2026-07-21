<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;

final readonly class DoctrinePaperDatabaseInspector implements PaperDatabaseInspectorInterface
{
    public function __construct(
        private Connection $connection,
        private DependencyFactory $dependencyFactory,
    ) {
    }

    public function inspect(): PaperDatabaseInspection
    {
        try {
            $name = $this->connection->fetchOne('SELECT current_database()');
            $pending = count($this->dependencyFactory
                ->getMigrationStatusCalculator()
                ->getNewMigrations()
                ->getItems());
        } catch (\Throwable) {
            throw new \RuntimeException('paper_database_inspection_failed');
        }

        return new PaperDatabaseInspection(
            databaseName: is_string($name) ? $name : '',
            pendingMigrations: $pending,
        );
    }
}
