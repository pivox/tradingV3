<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251118000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trade_zone_events table to persist skipped_out_of_zone details for analysis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trade_zone_events (
            id BIGSERIAL PRIMARY KEY,
            symbol VARCHAR(50) NOT NULL,
            happened_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            reason VARCHAR(50) NOT NULL,
            decision_key VARCHAR(100) DEFAULT NULL,
            timeframe VARCHAR(10) DEFAULT NULL,
            config_profile VARCHAR(50) DEFAULT NULL,
            zone_min DOUBLE PRECISION NOT NULL,
            zone_max DOUBLE PRECISION NOT NULL,
            candidate_price DOUBLE PRECISION NOT NULL,
            zone_dev_pct DOUBLE PRECISION NOT NULL,
            zone_max_dev_pct DOUBLE PRECISION NOT NULL,
            atr_pct DOUBLE PRECISION DEFAULT NULL,
            spread_bps DOUBLE PRECISION DEFAULT NULL,
            volume_ratio DOUBLE PRECISION DEFAULT NULL,
            vwap_distance_pct DOUBLE PRECISION DEFAULT NULL,
            entry_zone_width_pct DOUBLE PRECISION DEFAULT NULL,
            mtf_context JSONB NOT NULL DEFAULT \'{}\',
            mtf_level VARCHAR(10) DEFAULT NULL,
            proposed_zone_max_pct DOUBLE PRECISION DEFAULT NULL,
            category VARCHAR(50) NOT NULL DEFAULT \'close_to_threshold\'
        )');
        $this->addSql('CREATE INDEX idx_zone_symbol ON trade_zone_events(symbol)');
        $this->addSql('CREATE INDEX idx_zone_reason ON trade_zone_events(reason)');
        $this->addSql('CREATE INDEX idx_zone_happened_at ON trade_zone_events(happened_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS trade_zone_events');
    }
}
