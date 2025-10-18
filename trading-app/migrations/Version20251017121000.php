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
        $this->addSql('CREATE TABLE IF NOT EXISTS indicators_intraday (
  symbol VARCHAR(64) NOT NULL,
  timeframe VARCHAR(8) NOT NULL,
  ts TIMESTAMP WITHOUT TIME ZONE NOT NULL,
  atr NUMERIC(24,12) DEFAULT NULL,
  atr_raw NUMERIC(24,12) DEFAULT NULL,
  rsi NUMERIC(10,4) DEFAULT NULL,
  vwap NUMERIC(24,12) DEFAULT NULL,
  volume_ratio NUMERIC(20,8) DEFAULT NULL,
  PRIMARY KEY (symbol, timeframe, ts)
)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_indic_sym_tf_ts ON indicators_intraday (symbol, timeframe, ts)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS indicators_intraday');
    }
}
