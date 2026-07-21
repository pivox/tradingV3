<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the nullable paper market-data venue persistence contract.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_intent ADD market_data_venue VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE order_intent ADD CONSTRAINT chk_order_intent_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'))");
        $this->addSql('CREATE INDEX idx_order_intent_market_data_venue ON order_intent (market_data_venue, strategy_profile, created_at)');

        $this->addSql('ALTER TABLE trade_lineage ADD market_data_venue VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE trade_lineage ADD CONSTRAINT chk_trade_lineage_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'))");
        $this->addSql('CREATE INDEX idx_trade_lineage_market_data_venue ON trade_lineage (market_data_venue, profile, created_at)');

        $this->addSql('ALTER TABLE trade_lifecycle_event ADD market_data_venue VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE trade_lifecycle_event ADD CONSTRAINT chk_trade_lifecycle_event_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'))");
        $this->addSql('CREATE INDEX idx_trade_lifecycle_market_data_venue ON trade_lifecycle_event (market_data_venue, config_profile, happened_at, id)');

        $this->addSql('ALTER TABLE fill_cost_ledger ADD market_data_venue VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE fill_cost_ledger ADD CONSTRAINT chk_fill_cost_ledger_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'))");
        $this->addSql('CREATE INDEX idx_fill_cost_ledger_market_data_venue ON fill_cost_ledger (market_data_venue, internal_trade_id, occurred_at, id)');

        $this->addSql('ALTER TABLE trade_zone_events ADD market_data_venue VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE trade_zone_events ADD CONSTRAINT chk_trade_zone_events_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'))");
        $this->addSql('CREATE INDEX idx_trade_zone_market_data_venue ON trade_zone_events (market_data_venue, config_profile, happened_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_trade_zone_market_data_venue');
        $this->addSql('ALTER TABLE trade_zone_events DROP CONSTRAINT chk_trade_zone_events_market_data_venue');
        $this->addSql('DROP INDEX idx_fill_cost_ledger_market_data_venue');
        $this->addSql('ALTER TABLE fill_cost_ledger DROP CONSTRAINT chk_fill_cost_ledger_market_data_venue');
        $this->addSql('DROP INDEX idx_trade_lifecycle_market_data_venue');
        $this->addSql('ALTER TABLE trade_lifecycle_event DROP CONSTRAINT chk_trade_lifecycle_event_market_data_venue');
        $this->addSql('DROP INDEX idx_trade_lineage_market_data_venue');
        $this->addSql('ALTER TABLE trade_lineage DROP CONSTRAINT chk_trade_lineage_market_data_venue');
        $this->addSql('DROP INDEX idx_order_intent_market_data_venue');
        $this->addSql('ALTER TABLE order_intent DROP CONSTRAINT chk_order_intent_market_data_venue');

        $this->addSql('ALTER TABLE trade_zone_events DROP COLUMN market_data_venue');
        $this->addSql('ALTER TABLE fill_cost_ledger DROP COLUMN market_data_venue');
        $this->addSql('ALTER TABLE trade_lifecycle_event DROP COLUMN market_data_venue');
        $this->addSql('ALTER TABLE trade_lineage DROP COLUMN market_data_venue');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN market_data_venue');
    }
}
