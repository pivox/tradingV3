<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251021234146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE messenger_messages_failed_id_seq CASCADE');
        $this->addSql('CREATE SEQUENCE exchange_order_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE exchange_order (id BIGINT NOT NULL, order_plan_id BIGINT DEFAULT NULL, position_id BIGINT DEFAULT NULL, order_id VARCHAR(80) DEFAULT NULL, client_order_id VARCHAR(80) NOT NULL, parent_client_order_id VARCHAR(80) DEFAULT NULL, symbol VARCHAR(50) NOT NULL, kind VARCHAR(20) NOT NULL, status VARCHAR(24) NOT NULL, type VARCHAR(24) NOT NULL, side VARCHAR(24) NOT NULL, price NUMERIC(24, 12) DEFAULT NULL, size NUMERIC(28, 12) DEFAULT NULL, leverage INT DEFAULT NULL, submitted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, exchange_payload JSONB NOT NULL, metadata JSONB NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_EB1EDFD01EE3ABD0 ON exchange_order (order_plan_id)');
        $this->addSql('CREATE INDEX IDX_EB1EDFD0DD842E46 ON exchange_order (position_id)');
        $this->addSql('CREATE INDEX idx_exchange_order_symbol ON exchange_order (symbol)');
        $this->addSql('CREATE INDEX idx_exchange_order_kind ON exchange_order (kind)');
        $this->addSql('CREATE INDEX idx_exchange_order_status ON exchange_order (status)');
        $this->addSql('CREATE UNIQUE INDEX ux_exchange_order_client ON exchange_order (client_order_id)');
        $this->addSql('COMMENT ON COLUMN exchange_order.submitted_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN exchange_order.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN exchange_order.updated_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER TABLE exchange_order ADD CONSTRAINT FK_EB1EDFD01EE3ABD0 FOREIGN KEY (order_plan_id) REFERENCES order_plan (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE exchange_order ADD CONSTRAINT FK_EB1EDFD0DD842E46 FOREIGN KEY (position_id) REFERENCES positions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP TABLE messenger_messages_failed');
        $this->addSql('ALTER TABLE order_lifecycle ADD kind VARCHAR(24) DEFAULT \'ENTRY\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE exchange_order_id_seq CASCADE');
        $this->addSql('CREATE SEQUENCE messenger_messages_failed_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE messenger_messages_failed (id BIGSERIAL NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_804a86d916ba31db ON messenger_messages_failed (delivered_at)');
        $this->addSql('CREATE INDEX idx_804a86d9e3bd61ce ON messenger_messages_failed (available_at)');
        $this->addSql('CREATE INDEX idx_804a86d9fb7336f0 ON messenger_messages_failed (queue_name)');
        $this->addSql('COMMENT ON COLUMN messenger_messages_failed.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages_failed.available_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages_failed.delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE exchange_order DROP CONSTRAINT FK_EB1EDFD01EE3ABD0');
        $this->addSql('ALTER TABLE exchange_order DROP CONSTRAINT FK_EB1EDFD0DD842E46');
        $this->addSql('DROP TABLE exchange_order');
        $this->addSql('ALTER TABLE order_lifecycle DROP kind');
    }
}
