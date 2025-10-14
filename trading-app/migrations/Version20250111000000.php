<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250111000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contracts table for BitMart contracts storage.';
    }

    public function up(Schema $schema): void
    {
        // Create contracts table
        $this->addSql('CREATE TABLE contracts (
            id BIGSERIAL PRIMARY KEY,
            symbol TEXT NOT NULL,
            name TEXT,
            product_type INTEGER,
            open_timestamp BIGINT,
            expire_timestamp BIGINT,
            settle_timestamp BIGINT,
            base_currency TEXT,
            quote_currency TEXT,
            last_price NUMERIC(24,12),
            volume_24h NUMERIC(28,12),
            turnover_24h NUMERIC(28,12),
            status TEXT,
            min_size NUMERIC(24,12),
            max_size NUMERIC(24,12),
            tick_size NUMERIC(24,12),
            multiplier NUMERIC(24,12),
            inserted_at TIMESTAMPTZ DEFAULT now(),
            updated_at TIMESTAMPTZ DEFAULT now()
        )');

        $this->addSql('CREATE UNIQUE INDEX ux_contracts_symbol ON contracts(symbol)');
        $this->addSql('CREATE INDEX idx_contracts_quote_currency ON contracts(quote_currency)');
        $this->addSql('CREATE INDEX idx_contracts_status ON contracts(status)');
        $this->addSql('CREATE INDEX idx_contracts_volume_24h ON contracts(volume_24h)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS contracts');
    }
}




