<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808112000 extends AbstractMigration
{
    /** @var array<string,string> */
    private const COLUMNS = [
        'canonical_identity' => 'JSONB',
        'orchestration_run_id' => 'VARCHAR(255)',
        'correlation_run_id' => 'VARCHAR(96)',
        'orchestration_set_id' => 'VARCHAR(96)',
        'orchestration_dashboard_id' => 'VARCHAR(96)',
        'origin' => 'VARCHAR(24)',
        'replay_of_run_id' => 'VARCHAR(255)',
        'replay_of_correlation_id' => 'VARCHAR(96)',
        'attempt_number' => 'INT',
        'environment' => 'VARCHAR(32)',
        'dry_run' => 'BOOLEAN',
        'mode_id' => 'VARCHAR(64)',
        'mode_version' => 'VARCHAR(32)',
        'setup_id' => 'VARCHAR(160)',
        'setup_version' => 'VARCHAR(32)',
        'config_hash' => 'VARCHAR(128)',
        'condition_catalog_hash' => 'VARCHAR(128)',
        'canonical_side' => 'VARCHAR(8)',
        'decision_id' => 'VARCHAR(96)',
        'decision_key' => 'VARCHAR(160)',
        'intent_id' => 'VARCHAR(96)',
        'canonical_order_id' => 'VARCHAR(96)',
        'canonical_position_id' => 'VARCHAR(96)',
        'canonical_trade_id' => 'VARCHAR(96)',
        'canonical_exchange_fill_id' => 'VARCHAR(96)',
    ];

    public function getDescription(): string
    {
        return 'Issue 302 Lot 2B: canonical identity projection on recovered fills';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $column => $type) {
            $this->addSql(sprintf('ALTER TABLE futures_order_trade ADD COLUMN IF NOT EXISTS %s %s DEFAULT NULL', $column, $type));
        }
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_futures_order_trade_canonical_cell ON futures_order_trade (exchange, market_type, mode_id, setup_id, canonical_side)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_futures_order_trade_canonical_cell');
        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            $this->addSql(sprintf('ALTER TABLE futures_order_trade DROP COLUMN IF EXISTS %s', $column));
        }
    }
}
