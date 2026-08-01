<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801120000 extends AbstractMigration
{
    /** @var list<string> */
    private const TRADE_TABLES = [
        'order_intent',
        'trade_lineage',
        'trade_lifecycle_event',
        'fill_cost_ledger',
        'trade_zone_events',
    ];

    public function getDescription(): string
    {
        return 'Add the isolated Paper execution journal and nullable durable trade provenance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE paper_configuration_snapshot (
    id VARCHAR(71) PRIMARY KEY,
    schema_version INT NOT NULL,
    canonical_json JSONB NOT NULL,
    content_checksum CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT chk_paper_configuration_snapshot_id CHECK (id ~ '^sha256:[a-f0-9]{64}$'),
    CONSTRAINT chk_paper_configuration_snapshot_checksum CHECK (content_checksum ~ '^[a-f0-9]{64}$')
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE paper_execution_cell (
    id VARCHAR(71) PRIMARY KEY,
    network VARCHAR(16) NOT NULL,
    market_data_venue VARCHAR(32) NOT NULL,
    configuration_snapshot_id VARCHAR(71) NOT NULL,
    strategy_profile VARCHAR(80) NOT NULL,
    run_id VARCHAR(96) NOT NULL,
    account_namespace VARCHAR(78) NOT NULL,
    eligibility VARCHAR(32) NOT NULL,
    terminal_state VARCHAR(32) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT fk_paper_execution_cell_snapshot FOREIGN KEY (configuration_snapshot_id) REFERENCES paper_configuration_snapshot (id),
    CONSTRAINT ux_paper_execution_cell_run_id UNIQUE (run_id),
    CONSTRAINT ux_paper_execution_cell_account_namespace UNIQUE (account_namespace),
    CONSTRAINT chk_paper_execution_cell_id CHECK (id ~ '^sha256:[a-f0-9]{64}$'),
    CONSTRAINT chk_paper_execution_cell_network CHECK (network IN ('mainnet', 'testnet')),
    CONSTRAINT chk_paper_execution_cell_venue CHECK (market_data_venue IN ('okx', 'hyperliquid')),
    CONSTRAINT chk_paper_execution_cell_network_venue CHECK (market_data_venue <> 'okx' OR network = 'mainnet'),
    CONSTRAINT chk_paper_execution_cell_eligibility CHECK (eligibility IN ('reference_only')),
    CONSTRAINT chk_paper_execution_cell_terminal_state CHECK (terminal_state IN ('active', 'completed', 'failed'))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE paper_execution_event (
    cell_id VARCHAR(71) NOT NULL,
    journal_ordinal BIGINT NOT NULL,
    event_type VARCHAR(48) NOT NULL,
    source_position BIGINT DEFAULT NULL,
    source_event_id CHAR(64) DEFAULT NULL,
    effect_key VARCHAR(71) DEFAULT NULL,
    payload JSONB NOT NULL,
    payload_checksum CHAR(64) NOT NULL,
    appended_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (cell_id, journal_ordinal),
    CONSTRAINT fk_paper_execution_event_cell FOREIGN KEY (cell_id) REFERENCES paper_execution_cell (id),
    CONSTRAINT chk_paper_execution_event_ordinal CHECK (journal_ordinal >= 1),
    CONSTRAINT chk_paper_execution_event_source_position CHECK (source_position IS NULL OR source_position >= 0),
    CONSTRAINT chk_paper_execution_event_source_id CHECK (source_event_id IS NULL OR source_event_id ~ '^[a-f0-9]{64}$'),
    CONSTRAINT chk_paper_execution_event_effect_key CHECK (effect_key IS NULL OR effect_key ~ '^sha256:[a-f0-9]{64}$'),
    CONSTRAINT chk_paper_execution_event_checksum CHECK (payload_checksum ~ '^[a-f0-9]{64}$')
)
SQL);
        $this->addSql('CREATE INDEX idx_paper_execution_event_source ON paper_execution_event (cell_id, source_position) WHERE source_position IS NOT NULL');
        $this->addSql('CREATE INDEX idx_paper_execution_event_effect ON paper_execution_event (cell_id, effect_key) WHERE effect_key IS NOT NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE paper_execution_checkpoint (
    cell_id VARCHAR(71) PRIMARY KEY,
    next_source_position BIGINT NOT NULL,
    journal_ordinal BIGINT NOT NULL,
    journal_checksum CHAR(64) NOT NULL,
    fake_event_cursor BIGINT NOT NULL,
    killed BOOLEAN NOT NULL,
    lock_version BIGINT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT fk_paper_execution_checkpoint_cell FOREIGN KEY (cell_id) REFERENCES paper_execution_cell (id),
    CONSTRAINT chk_paper_execution_checkpoint_position CHECK (next_source_position >= 0),
    CONSTRAINT chk_paper_execution_checkpoint_ordinal CHECK (journal_ordinal >= 0),
    CONSTRAINT chk_paper_execution_checkpoint_checksum CHECK (journal_checksum ~ '^[a-f0-9]{64}$'),
    CONSTRAINT chk_paper_execution_checkpoint_cursor CHECK (fake_event_cursor >= 0),
    CONSTRAINT chk_paper_execution_checkpoint_lock CHECK (lock_version >= 0)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION paper_execution_event_reject_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'paper_execution_event_append_only' USING ERRCODE = 'P0001';
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER trg_paper_execution_event_append_only BEFORE UPDATE OR DELETE ON paper_execution_event FOR EACH ROW EXECUTE FUNCTION paper_execution_event_reject_mutation()');

        foreach (self::TRADE_TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD paper_network VARCHAR(16) DEFAULT NULL', $table));
            $this->addSql(sprintf("ALTER TABLE %s ADD CONSTRAINT chk_%s_paper_network CHECK (paper_network IS NULL OR paper_network IN ('mainnet', 'testnet'))", $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD paper_execution_cell_id VARCHAR(71) DEFAULT NULL', $table));
            $this->addSql(sprintf("ALTER TABLE %s ADD CONSTRAINT chk_%s_paper_execution_cell_id CHECK (paper_execution_cell_id IS NULL OR paper_execution_cell_id ~ '^sha256:[a-f0-9]{64}$')", $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD configuration_snapshot_id VARCHAR(71) DEFAULT NULL', $table));
            $this->addSql(sprintf("ALTER TABLE %s ADD CONSTRAINT chk_%s_configuration_snapshot_id CHECK (configuration_snapshot_id IS NULL OR configuration_snapshot_id ~ '^sha256:[a-f0-9]{64}$')", $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD paper_eligibility VARCHAR(32) DEFAULT NULL', $table));
            $this->addSql(sprintf("ALTER TABLE %s ADD CONSTRAINT chk_%s_paper_eligibility CHECK (paper_eligibility IS NULL OR paper_eligibility IN ('reference_only'))", $table, $table));
            $this->addSql(sprintf('CREATE INDEX idx_%s_paper_execution ON %s (paper_execution_cell_id, paper_network, paper_eligibility, configuration_snapshot_id)', $table, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(self::TRADE_TABLES) as $table) {
            $this->addSql(sprintf('DROP INDEX idx_%s_paper_execution', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT chk_%s_paper_eligibility', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT chk_%s_configuration_snapshot_id', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT chk_%s_paper_execution_cell_id', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT chk_%s_paper_network', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN paper_eligibility', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN configuration_snapshot_id', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN paper_execution_cell_id', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN paper_network', $table));
        }

        $this->addSql('DROP TRIGGER trg_paper_execution_event_append_only ON paper_execution_event');
        $this->addSql('DROP FUNCTION paper_execution_event_reject_mutation()');
        $this->addSql('DROP TABLE paper_execution_checkpoint');
        $this->addSql('DROP TABLE paper_execution_event');
        $this->addSql('DROP TABLE paper_execution_cell');
        $this->addSql('DROP TABLE paper_configuration_snapshot');
    }
}
