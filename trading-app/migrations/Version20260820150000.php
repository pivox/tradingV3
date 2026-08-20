<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable redacted effective trading configuration history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE effective_trading_config_snapshot (
    snapshot_hash VARCHAR(71) PRIMARY KEY,
    config_hash VARCHAR(71) NOT NULL,
    condition_catalog_hash VARCHAR(71) DEFAULT NULL,
    schema_version VARCHAR(32) NOT NULL,
    resolver_version VARCHAR(32) NOT NULL,
    mode_id VARCHAR(64) NOT NULL,
    mode_version VARCHAR(32) NOT NULL,
    setup_id VARCHAR(128) NOT NULL,
    setup_version VARCHAR(32) NOT NULL,
    exchange VARCHAR(32) NOT NULL,
    environment VARCHAR(32) NOT NULL,
    side VARCHAR(8) NOT NULL,
    execution_capability VARCHAR(32) DEFAULT NULL,
    validation_status VARCHAR(16) NOT NULL,
    redacted_snapshot JSONB NOT NULL,
    redacted_content_checksum CHAR(64) NOT NULL,
    created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    CONSTRAINT chk_effective_config_snapshot_hash CHECK (snapshot_hash ~ '^sha256:[a-f0-9]{64}$'),
    CONSTRAINT chk_effective_config_config_hash CHECK (config_hash ~ '^sha256:[a-f0-9]{64}$'),
    CONSTRAINT chk_effective_config_catalog_hash CHECK (condition_catalog_hash IS NULL OR condition_catalog_hash ~ '^sha256:[a-f0-9]{64}$'),
    CONSTRAINT chk_effective_config_checksum CHECK (redacted_content_checksum ~ '^[a-f0-9]{64}$'),
    CONSTRAINT chk_effective_config_side CHECK (side IN ('long', 'short')),
    CONSTRAINT chk_effective_config_validation CHECK (validation_status IN ('valid')),
    CONSTRAINT chk_effective_config_document CHECK (jsonb_typeof(redacted_snapshot) = 'object')
)
SQL);
        $this->addSql('CREATE INDEX idx_effective_config_snapshot_config_hash ON effective_trading_config_snapshot (config_hash, created_at, snapshot_hash)');
        $this->addSql('CREATE INDEX idx_effective_config_snapshot_identity ON effective_trading_config_snapshot (mode_id, mode_version, setup_id, setup_version, exchange, environment, side, execution_capability)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION effective_trading_config_snapshot_reject_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'effective_trading_config_snapshot_append_only' USING ERRCODE = 'P0001';
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER trg_effective_trading_config_snapshot_append_only BEFORE UPDATE OR DELETE ON effective_trading_config_snapshot FOR EACH ROW EXECUTE FUNCTION effective_trading_config_snapshot_reject_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_effective_trading_config_snapshot_append_only ON effective_trading_config_snapshot');
        $this->addSql('DROP FUNCTION IF EXISTS effective_trading_config_snapshot_reject_mutation()');
        $this->addSql('DROP TABLE IF EXISTS effective_trading_config_snapshot');
    }
}
