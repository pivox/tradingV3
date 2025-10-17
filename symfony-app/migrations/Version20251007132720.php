<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251007132720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract_pipeline ADD is_valid4h TINYINT(1) DEFAULT NULL, ADD is_valid1h TINYINT(1) DEFAULT NULL, ADD is_valid15m TINYINT(1) DEFAULT NULL, ADD is_valid5m TINYINT(1) DEFAULT NULL, ADD is_valid1m TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract_pipeline DROP is_valid4h, DROP is_valid1h, DROP is_valid15m, DROP is_valid5m, DROP is_valid1m');
    }
}
