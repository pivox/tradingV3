<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251016135224 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hot_kline ALTER last_update SET DEFAULT \'now()\'');
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
        $this->addSql('ALTER TABLE hot_kline ALTER last_update SET DEFAULT \'2025-10-16 13:52:03.335258+00\'');
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
    }
}
