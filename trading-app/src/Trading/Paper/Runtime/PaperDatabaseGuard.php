<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

final class PaperDatabaseGuard
{
    /**
     * Environments whose database already passed in this process. The check (database name
     * and pending Doctrine migrations, which introspects the schema) runs once per process,
     * not once per replayed event.
     *
     * @var array<string, true>
     */
    private array $ready = [];

    public function __construct(private readonly PaperDatabaseInspectorInterface $inspector)
    {
    }

    public function assertReady(string $environment): void
    {
        if (isset($this->ready[$environment])) {
            return;
        }
        $inspection = $this->inspector->inspect();
        $allowed = $inspection->databaseName === 'trading_paper'
            || ($environment === 'test' && str_ends_with($inspection->databaseName, '_paper_test'));

        if (!$allowed) {
            throw new \LogicException('paper_database_not_allowlisted');
        }

        if ($inspection->pendingMigrations !== 0) {
            throw new \LogicException('paper_database_migrations_pending');
        }
        $this->ready[$environment] = true;
    }
}
