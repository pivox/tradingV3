<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808113000 extends AbstractMigration
{
    /** @var array<string,string> */
    private const COLUMNS = [
        'canonical_identity' => 'JSONB', 'orchestration_run_id' => 'VARCHAR(255)',
        'correlation_run_id' => 'VARCHAR(96)', 'orchestration_set_id' => 'VARCHAR(96)',
        'orchestration_dashboard_id' => 'VARCHAR(96)', 'origin' => 'VARCHAR(24)',
        'replay_of_run_id' => 'VARCHAR(255)', 'replay_of_correlation_id' => 'VARCHAR(96)',
        'attempt_number' => 'INT', 'environment' => 'VARCHAR(32)', 'dry_run' => 'BOOLEAN',
        'mode_id' => 'VARCHAR(64)', 'mode_version' => 'VARCHAR(32)', 'setup_id' => 'VARCHAR(160)',
        'setup_version' => 'VARCHAR(32)', 'config_hash' => 'VARCHAR(128)',
        'condition_catalog_hash' => 'VARCHAR(128)', 'canonical_side' => 'VARCHAR(8)',
        'decision_id' => 'VARCHAR(96)', 'decision_key' => 'VARCHAR(160)', 'intent_id' => 'VARCHAR(96)',
        'canonical_order_id' => 'VARCHAR(96)', 'canonical_position_id' => 'VARCHAR(96)',
        'canonical_trade_id' => 'VARCHAR(96)', 'canonical_exchange_position_id' => 'VARCHAR(96)',
    ];

    public function getDescription(): string { return 'Issue 302 Lot 2B: immutable canonical position cycles'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE positions DROP CONSTRAINT IF EXISTS ux_positions_exchange_market_symbol_side');
        $this->addSql('ALTER TABLE positions ADD COLUMN IF NOT EXISTS opening_order_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE positions ADD COLUMN IF NOT EXISTS opening_fill_id BIGINT DEFAULT NULL');
        foreach (self::COLUMNS as $column => $type) {
            $this->addSql(sprintf('ALTER TABLE positions ADD COLUMN IF NOT EXISTS %s %s DEFAULT NULL', $column, $type));
        }
        $this->addSql('ALTER TABLE positions ADD CONSTRAINT fk_positions_opening_order FOREIGN KEY (opening_order_id) REFERENCES futures_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE positions ADD CONSTRAINT fk_positions_opening_fill FOREIGN KEY (opening_fill_id) REFERENCES futures_order_trade (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX ux_positions_exchange_market_position_cycle ON positions (exchange, market_type, canonical_exchange_position_id) WHERE canonical_exchange_position_id IS NOT NULL');
        $this->addSql("CREATE UNIQUE INDEX ux_positions_open_symbol_side ON positions (exchange, market_type, symbol, side) WHERE status = 'OPEN'");
        $this->addSql('CREATE INDEX idx_positions_opening_order ON positions (opening_order_id)');
        $this->addSql('CREATE INDEX idx_positions_opening_fill ON positions (opening_fill_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS ux_positions_exchange_market_position_cycle');
        $this->addSql('DROP INDEX IF EXISTS ux_positions_open_symbol_side');
        $this->addSql('DROP INDEX IF EXISTS idx_positions_opening_order');
        $this->addSql('DROP INDEX IF EXISTS idx_positions_opening_fill');
        $this->addSql('ALTER TABLE positions DROP CONSTRAINT IF EXISTS fk_positions_opening_order');
        $this->addSql('ALTER TABLE positions DROP CONSTRAINT IF EXISTS fk_positions_opening_fill');
        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            $this->addSql(sprintf('ALTER TABLE positions DROP COLUMN IF EXISTS %s', $column));
        }
        $this->addSql('ALTER TABLE positions DROP COLUMN IF EXISTS opening_order_id');
        $this->addSql('ALTER TABLE positions DROP COLUMN IF EXISTS opening_fill_id');
        $this->addSql('ALTER TABLE positions ADD CONSTRAINT ux_positions_exchange_market_symbol_side UNIQUE (exchange, market_type, symbol, side)');
    }
}
