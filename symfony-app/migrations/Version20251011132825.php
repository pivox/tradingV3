<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251011132825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract_pipeline DROP FOREIGN KEY FK_E84BC2267E5B2922');
        $this->addSql('ALTER TABLE contract_pipeline DROP FOREIGN KEY FK_E84BC226D088D3B4');
        $this->addSql('ALTER TABLE contract_pipeline DROP FOREIGN KEY FK_E84BC226F5ED5716');
        $this->addSql('DROP TABLE contract_pipeline');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contract_pipeline (id BIGINT AUTO_INCREMENT NOT NULL, contract_symbol VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, from_kline_id INT DEFAULT NULL, to_kline_id INT DEFAULT NULL, current_timeframe VARCHAR(10) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, retries INT DEFAULT 0 NOT NULL, max_retries INT NOT NULL, last_attempt_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(20) CHARACTER SET utf8mb4 DEFAULT \'pending\' NOT NULL COLLATE `utf8mb4_unicode_ci`, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', signals JSON DEFAULT NULL, order_id VARCHAR(50) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, last_attempted1h_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_attempted15m_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_attempted5m_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_attempted1m_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_valid4h TINYINT(1) DEFAULT NULL, is_valid1h TINYINT(1) DEFAULT NULL, is_valid15m TINYINT(1) DEFAULT NULL, is_valid5m TINYINT(1) DEFAULT NULL, is_valid1m TINYINT(1) DEFAULT NULL, UNIQUE INDEX uniq_pipeline_contract (contract_symbol), INDEX IDX_E84BC2267E5B2922 (from_kline_id), INDEX IDX_E84BC226F5ED5716 (to_kline_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE contract_pipeline ADD CONSTRAINT FK_E84BC2267E5B2922 FOREIGN KEY (from_kline_id) REFERENCES kline (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('ALTER TABLE contract_pipeline ADD CONSTRAINT FK_E84BC226D088D3B4 FOREIGN KEY (contract_symbol) REFERENCES contract (symbol) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contract_pipeline ADD CONSTRAINT FK_E84BC226F5ED5716 FOREIGN KEY (to_kline_id) REFERENCES kline (id) ON UPDATE NO ACTION ON DELETE SET NULL');
    }
}
