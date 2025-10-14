<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250115000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du champ expiresAt à la table mtf_switch pour gérer la désactivation temporaire des symboles';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mtf_switch ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN mtf_switch.expires_at IS \'Date d\'\'expiration de la desactivation temporaire\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mtf_switch DROP expires_at');
    }
}
