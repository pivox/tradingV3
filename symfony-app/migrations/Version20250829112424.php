<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250829112424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contract_pipeline (id BIGINT AUTO_INCREMENT NOT NULL, contract_symbol VARCHAR(255) NOT NULL, current_timeframe VARCHAR(10) NOT NULL, retries INT DEFAULT 0 NOT NULL, max_retries INT NOT NULL, last_attempt_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(20) DEFAULT \'pending\' NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_pipeline_contract (contract_symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE positions (id BIGINT AUTO_INCREMENT NOT NULL, contract_symbol VARCHAR(255) NOT NULL, exchange VARCHAR(32) NOT NULL, side VARCHAR(5) NOT NULL, status VARCHAR(16) NOT NULL, amount_usdt NUMERIC(18, 8) NOT NULL, entry_price NUMERIC(18, 8) DEFAULT NULL, qty_contract NUMERIC(28, 12) DEFAULT NULL, leverage NUMERIC(6, 2) DEFAULT NULL, external_order_id VARCHAR(128) DEFAULT NULL, opened_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', stop_loss NUMERIC(18, 8) DEFAULT NULL, take_profit NUMERIC(18, 8) DEFAULT NULL, pnl_usdt NUMERIC(18, 8) DEFAULT NULL, meta JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_D69FE57CD088D3B4 (contract_symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contract_pipeline ADD CONSTRAINT FK_E84BC226D088D3B4 FOREIGN KEY (contract_symbol) REFERENCES contract (symbol) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE positions ADD CONSTRAINT FK_D69FE57CD088D3B4 FOREIGN KEY (contract_symbol) REFERENCES contract (symbol) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract_pipeline DROP FOREIGN KEY FK_E84BC226D088D3B4');
        $this->addSql('ALTER TABLE positions DROP FOREIGN KEY FK_D69FE57CD088D3B4');
        $this->addSql('DROP TABLE contract_pipeline');
        $this->addSql('DROP TABLE positions');
    }
}
