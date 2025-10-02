<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250928231303 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract_pipeline ADD from_kline_id INT DEFAULT NULL, ADD to_kline_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contract_pipeline ADD CONSTRAINT FK_E84BC2267E5B2922 FOREIGN KEY (from_kline_id) REFERENCES kline (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE contract_pipeline ADD CONSTRAINT FK_E84BC226F5ED5716 FOREIGN KEY (to_kline_id) REFERENCES kline (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E84BC2267E5B2922 ON contract_pipeline (from_kline_id)');
        $this->addSql('CREATE INDEX IDX_E84BC226F5ED5716 ON contract_pipeline (to_kline_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract_pipeline DROP FOREIGN KEY FK_E84BC2267E5B2922');
        $this->addSql('ALTER TABLE contract_pipeline DROP FOREIGN KEY FK_E84BC226F5ED5716');
        $this->addSql('DROP INDEX IDX_E84BC2267E5B2922 ON contract_pipeline');
        $this->addSql('DROP INDEX IDX_E84BC226F5ED5716 ON contract_pipeline');
        $this->addSql('ALTER TABLE contract_pipeline DROP from_kline_id, DROP to_kline_id');
    }
}
