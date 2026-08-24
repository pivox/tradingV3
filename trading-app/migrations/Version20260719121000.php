<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the missing Paper market-data venue provenance to indicator snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE indicator_snapshots ADD COLUMN IF NOT EXISTS market_data_venue VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE indicator_snapshots ALTER COLUMN market_data_venue TYPE VARCHAR(32) USING market_data_venue::VARCHAR(32)');
        $this->addSql('ALTER TABLE indicator_snapshots ALTER COLUMN market_data_venue DROP NOT NULL');
        $this->addSql('ALTER TABLE indicator_snapshots DROP CONSTRAINT IF EXISTS chk_indicator_snapshots_market_data_venue');
        $this->addSql("ALTER TABLE indicator_snapshots ADD CONSTRAINT chk_indicator_snapshots_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'))");
        $this->addSql('DROP INDEX IF EXISTS ux_ind_snap_exchange_market_symbol_tf_time');
        $this->addSql('DROP INDEX IF EXISTS ux_ind_snap_exchange_market_venue_symbol_tf_time');
        $this->addSql('CREATE UNIQUE INDEX ux_ind_snap_exchange_market_symbol_tf_time ON indicator_snapshots (exchange, market_type, symbol, timeframe, kline_time) WHERE market_data_venue IS NULL');
        $this->addSql('CREATE UNIQUE INDEX ux_ind_snap_exchange_market_venue_symbol_tf_time ON indicator_snapshots (exchange, market_type, market_data_venue, symbol, timeframe, kline_time) WHERE market_data_venue IS NOT NULL');
        $this->addSql('DROP INDEX IF EXISTS idx_ind_snap_paper_market_identity_time');
        $this->addSql('CREATE INDEX idx_ind_snap_paper_market_identity_time ON indicator_snapshots (exchange, market_type, market_data_venue, symbol, timeframe, kline_time DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ind_snap_paper_market_identity_time');
        $this->addSql('DROP INDEX ux_ind_snap_exchange_market_venue_symbol_tf_time');
        $this->addSql('DROP INDEX ux_ind_snap_exchange_market_symbol_tf_time');
        $this->addSql('CREATE UNIQUE INDEX ux_ind_snap_exchange_market_symbol_tf_time ON indicator_snapshots (exchange, market_type, symbol, timeframe, kline_time)');
        $this->addSql('ALTER TABLE indicator_snapshots DROP CONSTRAINT chk_indicator_snapshots_market_data_venue');
        $this->addSql('ALTER TABLE indicator_snapshots DROP COLUMN market_data_venue');
    }
}
