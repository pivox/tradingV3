<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251017121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create entry_zones in trading-app schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS entry_zones (
  symbol VARCHAR(64) NOT NULL,
  side VARCHAR(8) NOT NULL,
  timeframe VARCHAR(8) NOT NULL,
  ts TIMESTAMP WITHOUT TIME ZONE NOT NULL,
  zone_low NUMERIC(24,12) NOT NULL,
  zone_high NUMERIC(24,12) NOT NULL,
  is_valid_entry BOOLEAN NOT NULL,
  cancel_after TIMESTAMP WITHOUT TIME ZONE NOT NULL,
  suggested_leverage NUMERIC(10,4) DEFAULT NULL,
  suggested_stop NUMERIC(24,12) DEFAULT NULL,
  evidence JSONB DEFAULT NULL,
  PRIMARY KEY (symbol, timeframe, ts, side)
)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_entry_zones_sym_tf_ts ON entry_zones (symbol, timeframe, ts)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS entry_zones');
    }
}
