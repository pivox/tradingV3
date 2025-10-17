<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251011133130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create table blacklisted_contract';
    }

    public function up(Schema $schema): void
    {
        // Create sequence and table for BlacklistedContract
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS blacklisted_contract_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE IF NOT EXISTS blacklisted_contract (id INT NOT NULL, symbol VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql("ALTER TABLE blacklisted_contract ALTER COLUMN id SET DEFAULT nextval('blacklisted_contract_id_seq')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE IF EXISTS blacklisted_contract ALTER COLUMN id DROP DEFAULT");
        $this->addSql('DROP SEQUENCE IF EXISTS blacklisted_contract_id_seq CASCADE');
        $this->addSql('DROP TABLE IF EXISTS blacklisted_contract');
    }
}
