<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add unique index on entry_zone_live (symbol, side, config_profile).
 */
final class Version20251205101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure entry_zone_live has a unique combination of symbol/side/config_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE UNIQUE INDEX ux_entry_zone_live_symbol_side_profile
    ON entry_zone_live(symbol, side, config_profile);
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS ux_entry_zone_live_symbol_side_profile;');
    }
}
