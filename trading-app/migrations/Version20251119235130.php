<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119235130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Supprimer les anciens enregistrements avant d'ajouter la colonne NOT NULL
        $this->addSql('DELETE FROM indicator_snapshots');
        
        // Ajouter la colonne trace_id avec NOT NULL
        $this->addSql('ALTER TABLE indicator_snapshots ADD trace_id VARCHAR(50) NOT NULL');
        
        // Ajouter trace_id à mtf_audit (nullable, donc pas de problème)
        $this->addSql('ALTER TABLE mtf_audit ADD trace_id TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Supprimer les colonnes trace_id ajoutées
        $this->addSql('ALTER TABLE mtf_audit DROP trace_id');
        $this->addSql('ALTER TABLE indicator_snapshots DROP trace_id');
    }
}
