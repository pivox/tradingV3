<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue 302 Lot 2B: persist execution environment on canonical order intents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS environment VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS dry_run BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS dry_run');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS environment');
    }
}
