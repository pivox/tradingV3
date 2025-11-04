<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Creates OrderIntent and OrderProtection tables
 */
final class Version20250115000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create OrderIntent and OrderProtection tables for local order draft tracking';
    }

    public function up(Schema $schema): void
    {
        // Vérifier si la table order_plan existe
        $orderPlanExists = false;
        try {
            $result = $this->connection->executeQuery("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = 'order_plan'
                )
            ")->fetchOne();
            $orderPlanExists = (bool) $result;
        } catch (\Throwable $e) {
            // Si la vérification échoue, on continue sans FK
            $orderPlanExists = false;
        }

        // ============================================================
        // Table: order_intent
        // ============================================================
        $createTableSql = '
            CREATE TABLE order_intent (
                id BIGSERIAL PRIMARY KEY,
                symbol VARCHAR(50) NOT NULL,
                side INTEGER NOT NULL,
                type VARCHAR(20) NOT NULL,
                open_type VARCHAR(20) NOT NULL,
                leverage INTEGER DEFAULT NULL,
                position_mode VARCHAR(20) NOT NULL,
                price NUMERIC(24, 12) DEFAULT NULL,
                size INTEGER NOT NULL,
                client_order_id VARCHAR(80) NOT NULL,
                preset_mode VARCHAR(30) NOT NULL,
                quantization JSONB NOT NULL DEFAULT \'{}\',
                status VARCHAR(30) NOT NULL DEFAULT \'DRAFT\',
                raw_inputs JSONB DEFAULT NULL,
                validation_errors JSONB DEFAULT NULL,
                order_id VARCHAR(80) DEFAULT NULL,
                failure_reason VARCHAR(500) DEFAULT NULL,
                order_plan_id BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL';

        // Ajouter la FK seulement si order_plan existe
        if ($orderPlanExists) {
            $createTableSql .= ',
                CONSTRAINT fk_order_intent_order_plan FOREIGN KEY (order_plan_id) REFERENCES order_plan(id) ON DELETE SET NULL';
        }

        $createTableSql .= '
            )';

        $this->addSql($createTableSql);
        $this->addSql('CREATE UNIQUE INDEX ux_order_intent_client_order_id ON order_intent (client_order_id)');
        $this->addSql('CREATE INDEX idx_order_intent_symbol ON order_intent (symbol)');
        $this->addSql('CREATE INDEX idx_order_intent_status ON order_intent (status)');
        $this->addSql('CREATE INDEX idx_order_intent_client_order_id ON order_intent (client_order_id)');
        $this->addSql('CREATE INDEX idx_order_intent_order_id ON order_intent (order_id) WHERE order_id IS NOT NULL');
        // Créer l'index seulement si order_plan existe
        if ($orderPlanExists) {
            $this->addSql('CREATE INDEX idx_order_intent_order_plan_id ON order_intent (order_plan_id)');
        }
        $this->addSql("COMMENT ON COLUMN order_intent.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN order_intent.updated_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN order_intent.sent_at IS '(DC2Type:datetimetz_immutable)'");

        // ============================================================
        // Table: order_protection
        // ============================================================
        $this->addSql('
            CREATE TABLE order_protection (
                id BIGSERIAL PRIMARY KEY,
                order_intent_id BIGINT NOT NULL,
                type VARCHAR(20) NOT NULL,
                price NUMERIC(24, 12) NOT NULL,
                size INTEGER DEFAULT NULL,
                price_type INTEGER DEFAULT NULL,
                order_id VARCHAR(80) DEFAULT NULL,
                client_order_id VARCHAR(80) DEFAULT NULL,
                metadata JSONB DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_order_protection_order_intent FOREIGN KEY (order_intent_id) REFERENCES order_intent(id) ON DELETE CASCADE
            )
        ');
        $this->addSql('CREATE INDEX idx_order_protection_order_intent ON order_protection (order_intent_id)');
        $this->addSql('CREATE INDEX idx_order_protection_type ON order_protection (type)');
        $this->addSql("COMMENT ON COLUMN order_protection.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN order_protection.updated_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS order_protection');
        $this->addSql('DROP TABLE IF EXISTS order_intent');
    }
}

