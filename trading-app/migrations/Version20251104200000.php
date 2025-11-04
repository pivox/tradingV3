<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renomme candle_close_ts en candle_open_ts car le champ stocke en fait l'openTime, pas le closeTime
 */
final class Version20251104200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomme candle_close_ts en candle_open_ts (stocke openTime, pas closeTime)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mtf_audit RENAME COLUMN candle_close_ts TO candle_open_ts');
        $this->addSql("COMMENT ON COLUMN mtf_audit.candle_open_ts IS 'Heure d''ouverture de la bougie concernée(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mtf_audit RENAME COLUMN candle_open_ts TO candle_close_ts');
        $this->addSql("COMMENT ON COLUMN mtf_audit.candle_close_ts IS 'Heure de clôture de la bougie concernée(DC2Type:datetimetz_immutable)'");
    }
}

