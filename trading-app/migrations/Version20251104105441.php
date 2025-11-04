<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251104105441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE hot_kline');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hot_kline (symbol VARCHAR(50) NOT NULL, timeframe VARCHAR(10) NOT NULL, open_time TIMESTAMP(0) WITH TIME ZONE NOT NULL, ohlc JSON NOT NULL, is_closed BOOLEAN DEFAULT false NOT NULL, last_update TIMESTAMP(0) WITH TIME ZONE DEFAULT \'2025-11-02 18:02:23.3291+00\' NOT NULL, PRIMARY KEY(symbol, timeframe, open_time))');
        $this->addSql('CREATE INDEX idx_hot_kline_last_update ON hot_kline (last_update)');
        $this->addSql('CREATE INDEX idx_hot_kline_symbol_tf ON hot_kline (symbol, timeframe)');
        $this->addSql('CREATE UNIQUE INDEX uniq_hot_kline_pk ON hot_kline (symbol, timeframe, open_time)');
        $this->addSql('COMMENT ON COLUMN hot_kline.open_time IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN hot_kline.last_update IS \'(DC2Type:datetimetz_immutable)\'');
    }
}
