<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deterministic order intent idempotency fields and active decision key uniqueness.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS decision_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS strategy_profile VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS strategy_version VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS timeframe VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS candle_open_ts TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE order_intent ADD COLUMN IF NOT EXISTS exchange_order_id VARCHAR(80) DEFAULT NULL');
        $this->addSql('UPDATE order_intent SET exchange_order_id = order_id WHERE exchange_order_id IS NULL AND order_id IS NOT NULL');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_order_intent_exchange_market_decision_key ON order_intent (exchange, market_type, decision_key)');
        $this->addSql("CREATE UNIQUE INDEX IF NOT EXISTS ux_order_intent_exchange_market_active_decision_key ON order_intent (exchange, market_type, decision_key) WHERE decision_key IS NOT NULL AND status IN ('DRAFT','VALIDATED','READY_TO_SEND','SENT')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS ux_order_intent_exchange_market_active_decision_key');
        $this->addSql('DROP INDEX IF EXISTS idx_order_intent_exchange_market_decision_key');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS exchange_order_id');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS candle_open_ts');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS timeframe');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS strategy_version');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS strategy_profile');
        $this->addSql('ALTER TABLE order_intent DROP COLUMN IF EXISTS decision_key');
    }
}
