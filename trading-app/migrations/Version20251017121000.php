<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251017121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create indicators_intraday in trading-app schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS indicators_intraday (
  symbol VARCHAR(64) NOT NULL,
  timeframe VARCHAR(8) NOT NULL,
  ts DATETIME NOT NULL,
  atr DECIMAL(24,12) DEFAULT NULL,
  atr_raw DECIMAL(24,12) DEFAULT NULL,
  rsi DECIMAL(10,4) DEFAULT NULL,
  vwap DECIMAL(24,12) DEFAULT NULL,
  volume_ratio DECIMAL(20,8) DEFAULT NULL,
  PRIMARY KEY (symbol, timeframe, ts)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;");

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_indic_sym_tf_ts ON indicators_intraday (symbol, timeframe, ts)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS indicators_intraday');
    }
}
