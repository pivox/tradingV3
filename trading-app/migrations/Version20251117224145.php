<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251117224145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE exchange_order_id_seq CASCADE');
        $this->addSql('CREATE SEQUENCE futures_transaction_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE futures_transaction (id BIGINT NOT NULL, position_id BIGINT DEFAULT NULL, trade_id BIGINT DEFAULT NULL, symbol VARCHAR(50) NOT NULL, flow_type INT NOT NULL, amount VARCHAR(64) NOT NULL, currency VARCHAR(16) NOT NULL, happened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, raw_data JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_458CC12FDD842E46 ON futures_transaction (position_id)');
        $this->addSql('CREATE INDEX IDX_458CC12FC2D9760 ON futures_transaction (trade_id)');
        $this->addSql('CREATE INDEX idx_futures_tx_symbol ON futures_transaction (symbol)');
        $this->addSql('CREATE INDEX idx_futures_tx_flow_type ON futures_transaction (flow_type)');
        $this->addSql('CREATE INDEX idx_futures_tx_happened_at ON futures_transaction (happened_at)');
        $this->addSql('COMMENT ON COLUMN futures_transaction.happened_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN futures_transaction.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN futures_transaction.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE futures_transaction ADD CONSTRAINT FK_458CC12FDD842E46 FOREIGN KEY (position_id) REFERENCES positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE futures_transaction ADD CONSTRAINT FK_458CC12FC2D9760 FOREIGN KEY (trade_id) REFERENCES futures_order_trade (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE mtf_run ALTER started_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_run ALTER started_at DROP DEFAULT');
        $this->addSql('ALTER TABLE mtf_run ALTER finished_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('COMMENT ON COLUMN mtf_run.started_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN mtf_run.finished_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER category TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER operation TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER recorded_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER recorded_at DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN mtf_run_metric.recorded_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER validation_mode_used TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER trade_entry_mode_used TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER created_at DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN mtf_run_symbol.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at DROP DEFAULT');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at DROP DEFAULT');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at DROP DEFAULT');
        $this->addSql('DROP INDEX idx_trade_lifecycle_event_type_happened_at');
        $this->addSql('DROP INDEX idx_trade_lifecycle_exchange_account');
        $this->addSql('DROP INDEX idx_trade_lifecycle_symbol_happened_at');
        $this->addSql('DROP INDEX uniq_trade_lifecycle_event_dedup');
        $this->addSql('ALTER TABLE trade_lifecycle_event ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE trade_lifecycle_event ALTER happened_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE trade_lifecycle_event ALTER happened_at DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN trade_lifecycle_event.happened_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql("DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = current_schema()
                      AND table_name = 'trade_zone_events'
                ) THEN
                    EXECUTE 'ALTER TABLE trade_zone_events ALTER id DROP DEFAULT';
                    EXECUTE 'ALTER TABLE trade_zone_events ALTER happened_at TYPE TIMESTAMP(0) WITH TIME ZONE';
                    EXECUTE 'ALTER TABLE trade_zone_events ALTER happened_at DROP DEFAULT';
                    EXECUTE 'ALTER TABLE trade_zone_events ALTER mtf_context DROP DEFAULT';
                    EXECUTE 'ALTER TABLE trade_zone_events ALTER category DROP DEFAULT';
                    EXECUTE 'COMMENT ON COLUMN trade_zone_events.happened_at IS ''(DC2Type:datetimetz_immutable)''';
                END IF;
            END;
        $$;");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE futures_transaction_id_seq CASCADE');
        $this->addSql('CREATE SEQUENCE exchange_order_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('ALTER TABLE futures_transaction DROP CONSTRAINT FK_458CC12FDD842E46');
        $this->addSql('ALTER TABLE futures_transaction DROP CONSTRAINT FK_458CC12FC2D9760');
        $this->addSql('DROP TABLE futures_transaction');
        $this->addSql('CREATE SEQUENCE trade_zone_events_id_seq');
        $this->addSql('SELECT setval(\'trade_zone_events_id_seq\', (SELECT MAX(id) FROM trade_zone_events))');
        $this->addSql('ALTER TABLE trade_zone_events ALTER id SET DEFAULT nextval(\'trade_zone_events_id_seq\')');
        $this->addSql('ALTER TABLE trade_zone_events ALTER happened_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE trade_zone_events ALTER happened_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE trade_zone_events ALTER mtf_context SET DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE trade_zone_events ALTER category SET DEFAULT \'close_to_threshold\'');
        $this->addSql('COMMENT ON COLUMN trade_zone_events.happened_at IS NULL');
        $this->addSql('CREATE SEQUENCE mtf_run_metric_id_seq');
        $this->addSql('SELECT setval(\'mtf_run_metric_id_seq\', (SELECT MAX(id) FROM mtf_run_metric))');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER id SET DEFAULT nextval(\'mtf_run_metric_id_seq\')');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER category TYPE TEXT');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER operation TYPE TEXT');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER recorded_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_run_metric ALTER recorded_at SET DEFAULT \'now()\'');
        $this->addSql('COMMENT ON COLUMN mtf_run_metric.recorded_at IS NULL');
        $this->addSql('ALTER TABLE mtf_switch ALTER created_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE mtf_switch ALTER updated_at SET DEFAULT \'now()\'');
        $this->addSql('CREATE SEQUENCE mtf_run_symbol_id_seq');
        $this->addSql('SELECT setval(\'mtf_run_symbol_id_seq\', (SELECT MAX(id) FROM mtf_run_symbol))');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER id SET DEFAULT nextval(\'mtf_run_symbol_id_seq\')');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER validation_mode_used TYPE TEXT');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER trade_entry_mode_used TYPE TEXT');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_run_symbol ALTER created_at SET DEFAULT \'now()\'');
        $this->addSql('COMMENT ON COLUMN mtf_run_symbol.created_at IS NULL');
        $this->addSql('CREATE SEQUENCE trade_lifecycle_event_id_seq');
        $this->addSql('SELECT setval(\'trade_lifecycle_event_id_seq\', (SELECT MAX(id) FROM trade_lifecycle_event))');
        $this->addSql('ALTER TABLE trade_lifecycle_event ALTER id SET DEFAULT nextval(\'trade_lifecycle_event_id_seq\')');
        $this->addSql('ALTER TABLE trade_lifecycle_event ALTER happened_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE trade_lifecycle_event ALTER happened_at SET DEFAULT \'now()\'');
        $this->addSql('COMMENT ON COLUMN trade_lifecycle_event.happened_at IS NULL');
        $this->addSql('CREATE INDEX idx_trade_lifecycle_event_type_happened_at ON trade_lifecycle_event (event_type, happened_at)');
        $this->addSql('CREATE INDEX idx_trade_lifecycle_exchange_account ON trade_lifecycle_event (exchange, account_id, happened_at)');
        $this->addSql('CREATE INDEX idx_trade_lifecycle_symbol_happened_at ON trade_lifecycle_event (symbol, happened_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trade_lifecycle_event_dedup ON trade_lifecycle_event (exchange, account_id, run_id, symbol, event_type, order_id, happened_at)');
        $this->addSql('ALTER TABLE mtf_state ALTER updated_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE mtf_run ALTER started_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE mtf_run ALTER started_at SET DEFAULT \'now()\'');
        $this->addSql('ALTER TABLE mtf_run ALTER finished_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('COMMENT ON COLUMN mtf_run.started_at IS NULL');
        $this->addSql('COMMENT ON COLUMN mtf_run.finished_at IS NULL');
    }
}
