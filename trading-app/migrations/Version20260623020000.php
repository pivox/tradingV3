<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DATA-001: add explicit persistent lineage context columns and indexes';
    }

    public function up(Schema $schema): void
    {
        $lineageColumns = [
            'internal_trade_id VARCHAR(96) DEFAULT NULL',
            'internal_position_id VARCHAR(96) DEFAULT NULL',
            'correlation_run_id VARCHAR(96) DEFAULT NULL',
            'orchestration_run_id VARCHAR(255) DEFAULT NULL',
            'orchestration_set_id VARCHAR(96) DEFAULT NULL',
            'orchestration_dashboard_id VARCHAR(96) DEFAULT NULL',
            "origin VARCHAR(24) DEFAULT 'legacy' NOT NULL",
            'replay_of_run_id VARCHAR(255) DEFAULT NULL',
            'replay_of_correlation_id VARCHAR(96) DEFAULT NULL',
            'attempt_number INT DEFAULT 1 NOT NULL',
            'config_hash VARCHAR(128) DEFAULT NULL',
        ];

        foreach ($lineageColumns as $definition) {
            $this->addSql(sprintf('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS %s', $definition));
        }

        $this->addSql('ALTER TABLE trade_lineage ADD COLUMN IF NOT EXISTS internal_position_id VARCHAR(96) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD COLUMN IF NOT EXISTS replay_of_run_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD COLUMN IF NOT EXISTS replay_of_correlation_id VARCHAR(96) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD COLUMN IF NOT EXISTS attempt_number INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE trade_lineage ADD COLUMN IF NOT EXISTS config_hash VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_lineage ALTER COLUMN orchestration_run_id TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE trade_lineage ALTER COLUMN replay_of_run_id TYPE VARCHAR(255)');
        $this->addSql("ALTER TABLE trade_lineage ALTER COLUMN origin SET DEFAULT 'legacy'");

        foreach ($lineageColumns as $definition) {
            $this->addSql(sprintf('ALTER TABLE trade_lifecycle_event ADD COLUMN IF NOT EXISTS %s', $definition));
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_intent_lineage_run_set ON order_intent (orchestration_run_id, orchestration_set_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_intent_lineage_internal_trade ON order_intent (internal_trade_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_intent_lineage_origin ON order_intent (origin, attempt_number)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_intent_lineage_replay ON order_intent (replay_of_run_id, replay_of_correlation_id)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lineage_internal_position ON trade_lineage (internal_position_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lineage_origin_attempt ON trade_lineage (origin, attempt_number)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lineage_replay ON trade_lineage (replay_of_run_id, replay_of_correlation_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_trade_lineage_config_hash ON trade_lineage (config_hash)');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_lineage_run_set ON trade_lifecycle_event (orchestration_run_id, orchestration_set_id, event_type, happened_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_lineage_internal_trade ON trade_lifecycle_event (internal_trade_id, event_type, happened_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_lineage_position ON trade_lifecycle_event (position_id, exchange, market_type, event_type, happened_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_lineage_origin ON trade_lifecycle_event (origin, attempt_number, event_type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_lineage_replay ON trade_lifecycle_event (replay_of_run_id, replay_of_correlation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_tle_lineage_replay');
        $this->addSql('DROP INDEX IF EXISTS idx_tle_lineage_origin');
        $this->addSql('DROP INDEX IF EXISTS idx_tle_lineage_position');
        $this->addSql('DROP INDEX IF EXISTS idx_tle_lineage_internal_trade');
        $this->addSql('DROP INDEX IF EXISTS idx_tle_lineage_run_set');

        $this->addSql('DROP INDEX IF EXISTS idx_trade_lineage_config_hash');
        $this->addSql('DROP INDEX IF EXISTS idx_trade_lineage_replay');
        $this->addSql('DROP INDEX IF EXISTS idx_trade_lineage_origin_attempt');
        $this->addSql('DROP INDEX IF EXISTS idx_trade_lineage_internal_position');

        $this->addSql('DROP INDEX IF EXISTS idx_order_intent_lineage_replay');
        $this->addSql('DROP INDEX IF EXISTS idx_order_intent_lineage_origin');
        $this->addSql('DROP INDEX IF EXISTS idx_order_intent_lineage_internal_trade');
        $this->addSql('DROP INDEX IF EXISTS idx_order_intent_lineage_run_set');

        foreach (['config_hash', 'attempt_number', 'replay_of_correlation_id', 'replay_of_run_id', 'origin', 'orchestration_dashboard_id', 'orchestration_set_id', 'orchestration_run_id', 'correlation_run_id', 'internal_position_id'] as $column) {
            $this->addSql(sprintf('ALTER TABLE trade_lifecycle_event DROP COLUMN IF EXISTS %s', $column));
            $this->addSql(sprintf('ALTER TABLE order_intent DROP COLUMN IF EXISTS %s', $column));
        }

        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS internal_trade_id');
        $this->addSql('ALTER TABLE trade_lineage DROP COLUMN IF EXISTS config_hash');
        $this->addSql('ALTER TABLE trade_lineage DROP COLUMN IF EXISTS attempt_number');
        $this->addSql('ALTER TABLE trade_lineage DROP COLUMN IF EXISTS replay_of_correlation_id');
        $this->addSql('ALTER TABLE trade_lineage DROP COLUMN IF EXISTS replay_of_run_id');
        $this->addSql('ALTER TABLE trade_lineage DROP COLUMN IF EXISTS internal_position_id');
        $this->addSql("ALTER TABLE trade_lineage ALTER COLUMN origin SET DEFAULT 'runtime'");
    }
}
