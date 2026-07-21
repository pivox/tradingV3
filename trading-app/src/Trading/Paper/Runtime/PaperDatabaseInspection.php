<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

final readonly class PaperDatabaseInspection
{
    public function __construct(
        #[\SensitiveParameter]
        public string $databaseName,
        public int $pendingMigrations,
    ) {
        if ($pendingMigrations < 0) {
            throw new \InvalidArgumentException('paper_database_pending_migrations_invalid');
        }
    }
}
