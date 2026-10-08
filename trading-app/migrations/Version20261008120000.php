<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change exchange column default from bitmart to okx (existing rows untouched)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contracts ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE futures_order ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE order_protection ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE futures_transaction ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE order_intent ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE futures_plan_order ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE indicator_snapshots ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE futures_order_trade ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE mtf_state ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE trade_lineage ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE positions ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE klines ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE trade_lifecycle_event ALTER COLUMN exchange SET DEFAULT 'okx'");
        $this->addSql("ALTER TABLE trade_zone_events ALTER COLUMN exchange SET DEFAULT 'okx'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contracts ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE futures_order ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE order_protection ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE futures_transaction ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE order_intent ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE futures_plan_order ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE indicator_snapshots ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE futures_order_trade ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE mtf_state ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE trade_lineage ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE positions ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE klines ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE trade_lifecycle_event ALTER COLUMN exchange SET DEFAULT 'bitmart'");
        $this->addSql("ALTER TABLE trade_zone_events ALTER COLUMN exchange SET DEFAULT 'bitmart'");
    }
}
