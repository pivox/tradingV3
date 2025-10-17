<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create MTF system tables (mtf_switch, mtf_state)';
    }

    public function up(Schema $schema): void
    {
        // Table mtf_switch
        $this->addSql('CREATE TABLE mtf_switch (
            id BIGSERIAL PRIMARY KEY,
            switch_key VARCHAR(100) NOT NULL UNIQUE,
            is_on BOOLEAN NOT NULL DEFAULT TRUE,
            description TEXT,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        )');

        $this->addSql('CREATE INDEX idx_mtf_switch_key ON mtf_switch(switch_key)');

        // Table mtf_state
        $this->addSql('CREATE TABLE mtf_state (
            id BIGSERIAL PRIMARY KEY,
            symbol VARCHAR(50) NOT NULL UNIQUE,
            k4h_time TIMESTAMPTZ,
            k1h_time TIMESTAMPTZ,
            k15m_time TIMESTAMPTZ,
            sides JSONB NOT NULL DEFAULT \'{}\'::jsonb,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        )');

        $this->addSql('CREATE INDEX idx_mtf_state_symbol ON mtf_state(symbol)');

        // Insérer les kill switches par défaut
        $this->addSql("INSERT INTO mtf_switch (switch_key, description) VALUES 
            ('GLOBAL', 'Kill switch global pour tout le système MTF'),
            ('SYMBOL:BTCUSDT', 'Kill switch pour le symbole BTCUSDT'),
            ('SYMBOL:ETHUSDT', 'Kill switch pour le symbole ETHUSDT'),
            ('SYMBOL:ADAUSDT', 'Kill switch pour le symbole ADAUSDT'),
            ('SYMBOL:SOLUSDT', 'Kill switch pour le symbole SOLUSDT'),
            ('SYMBOL:DOTUSDT', 'Kill switch pour le symbole DOTUSDT')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mtf_state');
        $this->addSql('DROP TABLE mtf_switch');
    }
}
