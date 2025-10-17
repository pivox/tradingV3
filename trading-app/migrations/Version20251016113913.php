<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251016113913 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY(key_id))');
        $this->addSql('ALTER TABLE hot_kline ALTER open_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE hot_kline ALTER last_update TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE hot_kline ALTER last_update SET DEFAULT \'now()\'');
        $this->addSql('COMMENT ON COLUMN hot_kline.open_time IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN hot_kline.last_update IS \'(DC2Type:datetimetz_immutable)\'');
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
        $this->addSql('COMMENT ON COLUMN mtf_switch.expires_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP TABLE lock_keys');
        $this->addSql('ALTER TABLE mtf_state ALTER k4h_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k1h_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k15m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k5m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k1m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('CREATE SEQUENCE klines_id_seq');
        $this->addSql('SELECT setval(\'klines_id_seq\', (SELECT MAX(id) FROM klines))');
        $this->addSql('ALTER TABLE klines ALTER id SET DEFAULT nextval(\'klines_id_seq\')');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER expires_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN mtf_switch.expires_at IS \'Date d\'\'expiration de la desactivation temporaire\'');
        $this->addSql('ALTER TABLE hot_kline ALTER open_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE hot_kline ALTER last_update TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE hot_kline ALTER last_update SET DEFAULT \'2025-10-15 14:18:11.743767+00\'');
        $this->addSql('COMMENT ON COLUMN hot_kline.open_time IS NULL');
        $this->addSql('COMMENT ON COLUMN hot_kline.last_update IS NULL');
        $this->addSql('ALTER TABLE mtf_lock ALTER acquired_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_lock ALTER expires_at TYPE TIMESTAMP(0) WITH TIME ZONE');
    }
}
