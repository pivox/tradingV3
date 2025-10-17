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
        $this->addSql("CREATE TABLE IF NOT EXISTS entry_zones (
  symbol VARCHAR(64) NOT NULL,
  side VARCHAR(8) NOT NULL,
  timeframe VARCHAR(8) NOT NULL,
  ts DATETIME NOT NULL,
  zone_low DECIMAL(24,12) NOT NULL,
  zone_high DECIMAL(24,12) NOT NULL,
  is_valid_entry TINYINT(1) NOT NULL,
  cancel_after DATETIME NOT NULL,
  suggested_leverage DECIMAL(10,4) DEFAULT NULL,
  suggested_stop DECIMAL(24,12) DEFAULT NULL,
  evidence JSON DEFAULT NULL,
  PRIMARY KEY (symbol, timeframe, ts, side)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;");

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_entry_zones_sym_tf_ts ON entry_zones (symbol, timeframe, ts)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS entry_zones');
    }
}
