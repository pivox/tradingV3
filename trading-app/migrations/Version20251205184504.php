<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251205184504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status column to entry_zone_live table';
    }

    public function up(Schema $schema): void
    {
        // Add status column to entry_zone_live table
        $this->addSql('ALTER TABLE entry_zone_live ADD status VARCHAR(20) DEFAULT \'waiting\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove status column from entry_zone_live table
        $this->addSql('ALTER TABLE entry_zone_live DROP status');
    }
}
