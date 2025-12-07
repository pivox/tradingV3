<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create entry_zone_live table to store live entry-zone windows.
 */
final class Version20251205095500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create entry_zone_live table (symbol/side/config/timing metadata).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS entry_zone_live (
    id BIGSERIAL PRIMARY KEY,
    symbol VARCHAR(50) NOT NULL,
    side VARCHAR(8) NOT NULL,
    price_min NUMERIC(30, 15) NOT NULL,
    price_max NUMERIC(30, 15) NOT NULL,
    atr_pct_1m NUMERIC(18, 8),
    vwap NUMERIC(30, 15),
    volume_ratio NUMERIC(18, 8),
    config_profile VARCHAR(64) NOT NULL,
    config_version VARCHAR(16) NOT NULL,
    valid_from TIMESTAMPTZ NOT NULL,
    valid_until TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS entry_zone_live;');
    }
}
