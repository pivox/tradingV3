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
        $this->addSql('ALTER TABLE indicator_snapshots ADD market_data_venue VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE indicator_snapshots ADD CONSTRAINT chk_indicator_snapshots_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'))");
        $this->addSql('CREATE INDEX idx_ind_snap_paper_market_identity_time ON indicator_snapshots (exchange, market_type, market_data_venue, symbol, timeframe, kline_time DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ind_snap_paper_market_identity_time');
        $this->addSql('ALTER TABLE indicator_snapshots DROP CONSTRAINT chk_indicator_snapshots_market_data_venue');
        $this->addSql('ALTER TABLE indicator_snapshots DROP COLUMN market_data_venue');
    }
}
