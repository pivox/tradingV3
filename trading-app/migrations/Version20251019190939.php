<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251019190939 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contracts ADD index_price NUMERIC(24, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD index_name VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD contract_size NUMERIC(24, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD min_leverage NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD max_leverage NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD price_precision VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD vol_precision VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD max_volume NUMERIC(28, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD market_max_volume NUMERIC(28, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD min_volume NUMERIC(28, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD funding_rate NUMERIC(18, 8) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD expected_funding_rate NUMERIC(18, 8) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD open_interest NUMERIC(28, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD open_interest_value NUMERIC(28, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD high_24h NUMERIC(24, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD low_24h NUMERIC(24, 12) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD change_24h NUMERIC(18, 8) DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD funding_interval_hours INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contracts ADD delist_time BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE contracts DROP index_price');
        $this->addSql('ALTER TABLE contracts DROP index_name');
        $this->addSql('ALTER TABLE contracts DROP contract_size');
        $this->addSql('ALTER TABLE contracts DROP min_leverage');
        $this->addSql('ALTER TABLE contracts DROP max_leverage');
        $this->addSql('ALTER TABLE contracts DROP price_precision');
        $this->addSql('ALTER TABLE contracts DROP vol_precision');
        $this->addSql('ALTER TABLE contracts DROP max_volume');
        $this->addSql('ALTER TABLE contracts DROP market_max_volume');
        $this->addSql('ALTER TABLE contracts DROP min_volume');
        $this->addSql('ALTER TABLE contracts DROP funding_rate');
        $this->addSql('ALTER TABLE contracts DROP expected_funding_rate');
        $this->addSql('ALTER TABLE contracts DROP open_interest');
        $this->addSql('ALTER TABLE contracts DROP open_interest_value');
        $this->addSql('ALTER TABLE contracts DROP high_24h');
        $this->addSql('ALTER TABLE contracts DROP low_24h');
        $this->addSql('ALTER TABLE contracts DROP change_24h');
        $this->addSql('ALTER TABLE contracts DROP funding_interval_hours');
        $this->addSql('ALTER TABLE contracts DROP delist_time');
    }
}
