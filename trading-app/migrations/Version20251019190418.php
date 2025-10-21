<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251019190418 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE indicators_intraday');
        $this->addSql('DROP TABLE entry_zones');
        $this->addSql('ALTER TABLE hot_kline ALTER last_update SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE klines ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE mtf_lock ALTER acquired_at TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_lock ALTER expires_at TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_state ALTER k4h_time TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_state ALTER k1h_time TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_state ALTER k15m_time TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_state ALTER k5m_time TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_state ALTER k1m_time TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at TYPE TIMESTAMPTZ');
        $this->addSql('ALTER TABLE mtf_switch ALTER expires_at TYPE TIMESTAMPTZ');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE TABLE indicators_intraday (symbol VARCHAR(64) NOT NULL, timeframe VARCHAR(8) NOT NULL, ts TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, atr NUMERIC(24, 12) DEFAULT NULL, atr_raw NUMERIC(24, 12) DEFAULT NULL, rsi NUMERIC(10, 4) DEFAULT NULL, vwap NUMERIC(24, 12) DEFAULT NULL, volume_ratio NUMERIC(20, 8) DEFAULT NULL, PRIMARY KEY(symbol, timeframe, ts))');
        $this->addSql('CREATE INDEX idx_indic_sym_tf_ts ON indicators_intraday (symbol, timeframe, ts)');
        $this->addSql('CREATE TABLE entry_zones (symbol VARCHAR(64) NOT NULL, side VARCHAR(8) NOT NULL, timeframe VARCHAR(8) NOT NULL, ts TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, zone_low NUMERIC(24, 12) NOT NULL, zone_high NUMERIC(24, 12) NOT NULL, is_valid_entry BOOLEAN NOT NULL, cancel_after TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, suggested_leverage NUMERIC(10, 4) DEFAULT NULL, suggested_stop NUMERIC(24, 12) DEFAULT NULL, evidence JSONB DEFAULT NULL, PRIMARY KEY(symbol, timeframe, ts, side))');
        $this->addSql('CREATE INDEX idx_entry_zones_sym_tf_ts ON entry_zones (symbol, timeframe, ts)');
        $this->addSql('ALTER TABLE hot_kline ALTER last_update SET DEFAULT \'2025-10-17 22:58:42.446347+00\'');
        $this->addSql('ALTER TABLE mtf_state ALTER k4h_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k1h_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k15m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k5m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k1m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_lock ALTER acquired_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_lock ALTER expires_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER expires_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('CREATE SEQUENCE klines_id_seq');
        $this->addSql('SELECT setval(\'klines_id_seq\', (SELECT MAX(id) FROM klines))');
        $this->addSql('ALTER TABLE klines ALTER id SET DEFAULT nextval(\'klines_id_seq\')');
    }
}
