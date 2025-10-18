<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251017223924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS positions_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE IF NOT EXISTS positions (id BIGINT NOT NULL, symbol VARCHAR(50) NOT NULL, side VARCHAR(10) NOT NULL, size NUMERIC(28, 12) DEFAULT NULL, avg_entry_price NUMERIC(24, 12) DEFAULT NULL, leverage INT DEFAULT NULL, unrealized_pnl NUMERIC(28, 12) DEFAULT NULL, status VARCHAR(16) DEFAULT \'OPEN\' NOT NULL, payload JSONB NOT NULL, inserted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_positions_symbol ON positions (symbol)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_positions_symbol_side ON positions (symbol, side)');
        $this->addSql('COMMENT ON COLUMN positions.inserted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN positions.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER TABLE contract_cooldown ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE contract_cooldown ALTER active_until TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE contract_cooldown ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE contract_cooldown ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('COMMENT ON COLUMN contract_cooldown.active_until IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN contract_cooldown.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN contract_cooldown.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER TABLE hot_kline ALTER last_update SET DEFAULT \'now()\'');
        $this->addSql('DROP INDEX IF EXISTS idx_ind_snap_source');
        $this->addSql('ALTER TABLE indicator_snapshots ALTER source TYPE VARCHAR(10)');
        $this->addSql('COMMENT ON COLUMN indicator_snapshots.source IS NULL');
        $this->addSql('ALTER TABLE klines ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE mtf_audit ADD COLUMN IF NOT EXISTS candle_close_ts TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mtf_audit ADD COLUMN IF NOT EXISTS severity SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('COMMENT ON COLUMN mtf_audit.candle_close_ts IS \'Heure de clôture de la bougie concernée(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN mtf_audit.severity IS \'Niveau de sévérité 0..n\'');
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
        $this->addSql('ALTER TABLE order_lifecycle ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE order_lifecycle ALTER last_event_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE order_lifecycle ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE order_lifecycle ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('COMMENT ON COLUMN order_lifecycle.last_event_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN order_lifecycle.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN order_lifecycle.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE positions_id_seq CASCADE');
        $this->addSql('DROP TABLE positions');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER expires_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_lock ALTER acquired_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_lock ALTER expires_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k4h_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k1h_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k15m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k5m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k1m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE indicator_snapshots ALTER source TYPE TEXT');
        $this->addSql('ALTER TABLE indicator_snapshots ALTER source TYPE TEXT');
        $this->addSql('COMMENT ON COLUMN indicator_snapshots.source IS \'Source of indicator calculation: PHP or SQL\'');
        $this->addSql('CREATE INDEX idx_ind_snap_source ON indicator_snapshots (source)');
        $this->addSql('ALTER TABLE mtf_audit DROP candle_close_ts');
        $this->addSql('ALTER TABLE mtf_audit DROP severity');
        $this->addSql('CREATE SEQUENCE klines_id_seq');
        $this->addSql('SELECT setval(\'klines_id_seq\', (SELECT MAX(id) FROM klines))');
        $this->addSql('ALTER TABLE klines ALTER id SET DEFAULT nextval(\'klines_id_seq\')');
        $this->addSql('CREATE SEQUENCE order_lifecycle_id_seq');
        $this->addSql('SELECT setval(\'order_lifecycle_id_seq\', (SELECT MAX(id) FROM order_lifecycle))');
        $this->addSql('ALTER TABLE order_lifecycle ALTER id SET DEFAULT nextval(\'order_lifecycle_id_seq\')');
        $this->addSql('ALTER TABLE order_lifecycle ALTER last_event_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE order_lifecycle ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE order_lifecycle ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('COMMENT ON COLUMN order_lifecycle.last_event_at IS NULL');
        $this->addSql('COMMENT ON COLUMN order_lifecycle.created_at IS NULL');
        $this->addSql('COMMENT ON COLUMN order_lifecycle.updated_at IS NULL');
        $this->addSql('CREATE SEQUENCE contract_cooldown_id_seq');
        $this->addSql('SELECT setval(\'contract_cooldown_id_seq\', (SELECT MAX(id) FROM contract_cooldown))');
        $this->addSql('ALTER TABLE contract_cooldown ALTER id SET DEFAULT nextval(\'contract_cooldown_id_seq\')');
        $this->addSql('ALTER TABLE contract_cooldown ALTER active_until TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE contract_cooldown ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE contract_cooldown ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('COMMENT ON COLUMN contract_cooldown.active_until IS NULL');
        $this->addSql('COMMENT ON COLUMN contract_cooldown.created_at IS NULL');
        $this->addSql('COMMENT ON COLUMN contract_cooldown.updated_at IS NULL');
        $this->addSql('ALTER TABLE hot_kline ALTER last_update SET DEFAULT \'2025-10-16 17:43:05.209126+00\'');
    }
}
