<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251104110755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Les tables order_plan et order_lifecycle existent déjà
        // Réparer les séquences si elles ont été supprimées
        $this->addSql("DO \$\$
            BEGIN
                -- Réparer order_plan si la séquence n'existe pas
                IF NOT EXISTS (SELECT 1 FROM pg_sequences WHERE sequencename = 'order_plan_id_seq') THEN
                    CREATE SEQUENCE order_plan_id_seq;
                    SELECT setval('order_plan_id_seq', COALESCE((SELECT MAX(id) FROM order_plan), 1), true);
                    ALTER TABLE order_plan ALTER id SET DEFAULT nextval('order_plan_id_seq'::regclass);
                END IF;
                
                -- Réparer order_lifecycle si la séquence n'existe pas
                IF NOT EXISTS (SELECT 1 FROM pg_sequences WHERE sequencename = 'order_lifecycle_id_seq') THEN
                    CREATE SEQUENCE order_lifecycle_id_seq;
                    SELECT setval('order_lifecycle_id_seq', COALESCE((SELECT MAX(id) FROM order_lifecycle), 1), true);
                    ALTER TABLE order_lifecycle ALTER id SET DEFAULT nextval('order_lifecycle_id_seq'::regclass);
                END IF;
            END \$\$;");
        // Ajouter FK order_plan_id dans exchange_order si elle n'existe pas
        $this->addSql('DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns 
                    WHERE table_name = \'exchange_order\' AND column_name = \'order_plan_id\'
                ) THEN
                    ALTER TABLE exchange_order ADD COLUMN order_plan_id BIGINT DEFAULT NULL;
                END IF;
            END $$;');
        $this->addSql('DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint 
                    WHERE conname = \'fk_eb1edfd01ee3abd0\'
                ) THEN
                    ALTER TABLE exchange_order 
                    ADD CONSTRAINT FK_EB1EDFD01EE3ABD0 
                    FOREIGN KEY (order_plan_id) REFERENCES order_plan (id) 
                    ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
                END IF;
            END $$;');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_EB1EDFD01EE3ABD0 ON exchange_order (order_plan_id)');
        // Corriger les index uniques de futures_order
        $this->addSql('DROP INDEX IF EXISTS idx_futures_order_client_order_id');
        $this->addSql('DROP INDEX IF EXISTS ux_futures_order_client');
        $this->addSql('DROP INDEX IF EXISTS ux_futures_order_order_id');
        $this->addSql('ALTER TABLE futures_order ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE futures_order ALTER raw_data DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_client ON futures_order (client_order_id)');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_order_id ON futures_order (order_id)');
        $this->addSql('DROP INDEX IF EXISTS ux_futures_order_trade_trade_id');
        $this->addSql('ALTER TABLE futures_order_trade ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE futures_order_trade ALTER raw_data DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_trade_trade_id ON futures_order_trade (trade_id)');
        $this->addSql('ALTER INDEX idx_futures_order_trade_futures_order_id RENAME TO IDX_CA3FF36556721FA4');
        $this->addSql('DROP INDEX IF EXISTS ux_futures_plan_order_client');
        $this->addSql('DROP INDEX IF EXISTS ux_futures_plan_order_order_id');
        $this->addSql('ALTER TABLE futures_plan_order ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE futures_plan_order ALTER raw_data DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_plan_order_client ON futures_plan_order (client_order_id)');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_plan_order_order_id ON futures_plan_order (order_id)');
        $this->addSql('ALTER INDEX idx_futures_plan_order_futures_order_id RENAME TO IDX_C570D88F56721FA4');
        $this->addSql('ALTER TABLE klines ALTER inserted_at SET DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE klines ALTER updated_at SET DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_audit ALTER created_at SET DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_lock ALTER acquired_at TYPE TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_lock ALTER expires_at TYPE TIMESTAMP');
        $this->addSql('COMMENT ON COLUMN mtf_lock.acquired_at IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('COMMENT ON COLUMN mtf_lock.expires_at IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('ALTER TABLE mtf_state ALTER k4h_time TYPE TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_state ALTER k1h_time TYPE TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_state ALTER k15m_time TYPE TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at TYPE TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at SET DEFAULT now()');
        $this->addSql('ALTER TABLE mtf_state ALTER k5m_time TYPE TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_state ALTER k1m_time TYPE TIMESTAMP');
        $this->addSql('COMMENT ON COLUMN mtf_state.k4h_time IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('COMMENT ON COLUMN mtf_state.k1h_time IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('COMMENT ON COLUMN mtf_state.k15m_time IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('COMMENT ON COLUMN mtf_state.updated_at IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('COMMENT ON COLUMN mtf_state.k5m_time IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('COMMENT ON COLUMN mtf_state.k1m_time IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at TYPE TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at SET DEFAULT now()');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at TYPE TIMESTAMP');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at SET DEFAULT now()');
        $this->addSql('ALTER TABLE mtf_switch ALTER expires_at TYPE TIMESTAMP');
        $this->addSql('COMMENT ON COLUMN mtf_switch.created_at IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('COMMENT ON COLUMN mtf_switch.updated_at IS \'(DC2Type:postgres_timestamp)\'');
        $this->addSql('COMMENT ON COLUMN mtf_switch.expires_at IS \'(DC2Type:postgres_timestamp)\'');
        // Supprimer l'index partiel order_intent_order_id s'il existe
        $this->addSql('DROP INDEX IF EXISTS idx_order_intent_order_id');
        $this->addSql('ALTER TABLE order_intent ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE order_intent ALTER quantization DROP DEFAULT');
        $this->addSql('ALTER TABLE order_intent ALTER status DROP DEFAULT');
        // Ajouter FK order_plan_id dans order_intent si elle n'existe pas
        $this->addSql('DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint 
                    WHERE conname = \'fk_7de3ebc41ee3abd0\'
                ) THEN
                    ALTER TABLE order_intent 
                    ADD CONSTRAINT FK_7DE3EBC41EE3ABD0 
                    FOREIGN KEY (order_plan_id) REFERENCES order_plan (id) 
                    ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
                END IF;
            END $$;');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_7DE3EBC41EE3ABD0 ON order_intent (order_plan_id)');
        $this->addSql('ALTER INDEX ux_order_intent_client_order_id RENAME TO UNIQ_7DE3EBC4A3795DFD');
        $this->addSql('ALTER TABLE order_protection ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE signals ALTER inserted_at SET DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE signals ALTER updated_at SET DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE exchange_order DROP CONSTRAINT FK_EB1EDFD01EE3ABD0');
        $this->addSql('ALTER TABLE order_intent DROP CONSTRAINT FK_7DE3EBC41EE3ABD0');
        $this->addSql('DROP TABLE IF EXISTS order_lifecycle');
        $this->addSql('DROP TABLE IF EXISTS order_plan');
        $this->addSql('ALTER TABLE klines ALTER inserted_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE klines ALTER updated_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE mtf_state ALTER k4h_time TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k1h_time TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k15m_time TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k5m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER k1m_time TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at SET DEFAULT \'now()\'');
        $this->addSql('COMMENT ON COLUMN mtf_state.k4h_time IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_state.k1h_time IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_state.k15m_time IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_state.k5m_time IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_state.k1m_time IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_state.updated_at IS NULL');
        $this->addSql('DROP INDEX IDX_7DE3EBC41EE3ABD0');
        $this->addSql('CREATE SEQUENCE order_intent_id_seq');
        $this->addSql('SELECT setval(\'order_intent_id_seq\', (SELECT MAX(id) FROM order_intent))');
        $this->addSql('ALTER TABLE order_intent ALTER id SET DEFAULT nextval(\'order_intent_id_seq\')');
        $this->addSql('ALTER TABLE order_intent ALTER status SET DEFAULT \'DRAFT\'');
        $this->addSql('CREATE INDEX idx_order_intent_order_id ON order_intent (order_id) WHERE (order_id IS NOT NULL)');
        $this->addSql('ALTER INDEX uniq_7de3ebc4a3795dfd RENAME TO ux_order_intent_client_order_id');
        $this->addSql('ALTER TABLE mtf_audit ALTER created_at SET DEFAULT \'now()\'');
        $this->addSql('DROP INDEX ux_futures_plan_order_order_id');
        $this->addSql('DROP INDEX ux_futures_plan_order_client');
        $this->addSql('CREATE SEQUENCE futures_plan_order_id_seq');
        $this->addSql('SELECT setval(\'futures_plan_order_id_seq\', (SELECT MAX(id) FROM futures_plan_order))');
        $this->addSql('ALTER TABLE futures_plan_order ALTER id SET DEFAULT nextval(\'futures_plan_order_id_seq\')');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_plan_order_order_id ON futures_plan_order (order_id) WHERE (order_id IS NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_plan_order_client ON futures_plan_order (client_order_id) WHERE (client_order_id IS NOT NULL)');
        $this->addSql('ALTER INDEX idx_c570d88f56721fa4 RENAME TO idx_futures_plan_order_futures_order_id');
        $this->addSql('ALTER TABLE mtf_lock ALTER acquired_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_lock ALTER expires_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('COMMENT ON COLUMN mtf_lock.acquired_at IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_lock.expires_at IS NULL');
        $this->addSql('DROP INDEX ux_futures_order_trade_trade_id');
        $this->addSql('CREATE SEQUENCE futures_order_trade_id_seq');
        $this->addSql('SELECT setval(\'futures_order_trade_id_seq\', (SELECT MAX(id) FROM futures_order_trade))');
        $this->addSql('ALTER TABLE futures_order_trade ALTER id SET DEFAULT nextval(\'futures_order_trade_id_seq\')');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_trade_trade_id ON futures_order_trade (trade_id) WHERE (trade_id IS NOT NULL)');
        $this->addSql('ALTER INDEX idx_ca3ff36556721fa4 RENAME TO idx_futures_order_trade_futures_order_id');
        $this->addSql('CREATE SEQUENCE order_protection_id_seq');
        $this->addSql('SELECT setval(\'order_protection_id_seq\', (SELECT MAX(id) FROM order_protection))');
        $this->addSql('ALTER TABLE order_protection ALTER id SET DEFAULT nextval(\'order_protection_id_seq\')');
        $this->addSql('DROP INDEX ux_futures_order_order_id');
        $this->addSql('DROP INDEX ux_futures_order_client');
        $this->addSql('CREATE SEQUENCE futures_order_id_seq');
        $this->addSql('SELECT setval(\'futures_order_id_seq\', (SELECT MAX(id) FROM futures_order))');
        $this->addSql('ALTER TABLE futures_order ALTER id SET DEFAULT nextval(\'futures_order_id_seq\')');
        $this->addSql('CREATE INDEX idx_futures_order_client_order_id ON futures_order (client_order_id)');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_order_id ON futures_order (order_id) WHERE (order_id IS NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX ux_futures_order_client ON futures_order (client_order_id) WHERE (client_order_id IS NOT NULL)');
        $this->addSql('DROP INDEX IDX_EB1EDFD01EE3ABD0');
        $this->addSql('ALTER TABLE exchange_order DROP order_plan_id');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE mtf_switch ALTER expires_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('COMMENT ON COLUMN mtf_switch.created_at IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_switch.updated_at IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_switch.expires_at IS NULL');
        $this->addSql('ALTER TABLE signals ALTER inserted_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE signals ALTER updated_at SET DEFAULT \'now()\'');
    }
}
