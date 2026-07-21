<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

final readonly class PaperDatabaseGuard
{
    public function __construct(private PaperDatabaseInspectorInterface $inspector)
    {
    }

    public function assertReady(string $environment): void
    {
        $inspection = $this->inspector->inspect();
        $allowed = $inspection->databaseName === 'trading_paper'
            || ($environment === 'test' && str_ends_with($inspection->databaseName, '_paper_test'));

        if (!$allowed) {
            throw new \LogicException('paper_database_not_allowlisted');
        }

        if ($inspection->pendingMigrations !== 0) {
            throw new \LogicException('paper_database_migrations_pending');
        }
    }
}
