<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251005090307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_config (id INT AUTO_INCREMENT NOT NULL, config_key VARCHAR(50) NOT NULL, hc_margin_pct DOUBLE PRECISION NOT NULL, hc_risk_max_pct DOUBLE PRECISION NOT NULL, hc_rmultiple DOUBLE PRECISION NOT NULL, hc_expire_after_sec INT NOT NULL, scalp_margin_usdt DOUBLE PRECISION NOT NULL, scalp_risk_max_pct DOUBLE PRECISION NOT NULL, scalp_rmultiple DOUBLE PRECISION NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_B1D8344195D1CAA6 (config_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY(key_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE orders_stop_losses DROP FOREIGN KEY FK_E0A47B7F1252C1E9');
        $this->addSql('ALTER TABLE orders_stop_losses DROP FOREIGN KEY FK_E0A47B7F798109C');
        $this->addSql('ALTER TABLE orders_take_profits DROP FOREIGN KEY FK_46B74ED1252C1E9');
        $this->addSql('ALTER TABLE orders_take_profits DROP FOREIGN KEY FK_46B74ED798109C');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE orders_stop_losses');
        $this->addSql('DROP TABLE orders_take_profits');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE orders (id BIGINT AUTO_INCREMENT NOT NULL, contract_pipeline_id BIGINT NOT NULL, exchange_order_id VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, client_order_id VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, symbol VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, side VARCHAR(8) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, type VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, price NUMERIC(18, 8) DEFAULT NULL, size NUMERIC(24, 10) NOT NULL, leverage INT NOT NULL, open_type VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, plan_order_id VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, trigger_price NUMERIC(18, 8) DEFAULT NULL, execution_price NUMERIC(18, 8) DEFAULT NULL, activation_price NUMERIC(18, 8) DEFAULT NULL, callback_rate NUMERIC(10, 6) DEFAULT NULL, status VARCHAR(24) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, reason VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, state_raw INT DEFAULT NULL, deal_size NUMERIC(24, 10) NOT NULL, deal_avg_price NUMERIC(18, 8) DEFAULT NULL, fees_cum NUMERIC(18, 8) NOT NULL, last_trade_id VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, last_fill_qty NUMERIC(24, 10) DEFAULT NULL, last_fill_price NUMERIC(18, 8) DEFAULT NULL, last_fee NUMERIC(18, 8) DEFAULT NULL, last_fee_ccy VARCHAR(16) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', opened_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', filled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', server_update_time_ms BIGINT DEFAULT NULL, stop_loss DOUBLE PRECISION DEFAULT NULL, take_profit DOUBLE PRECISION DEFAULT NULL, UNIQUE INDEX uniq_client_order_id (client_order_id), INDEX idx_contract_pipeline (contract_pipeline_id), INDEX idx_symbol (symbol), INDEX idx_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE orders_stop_losses (parent_order_id BIGINT NOT NULL, child_order_id BIGINT NOT NULL, INDEX IDX_E0A47B7F1252C1E9 (parent_order_id), INDEX IDX_E0A47B7F798109C (child_order_id), PRIMARY KEY(parent_order_id, child_order_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE orders_take_profits (parent_order_id BIGINT NOT NULL, child_order_id BIGINT NOT NULL, INDEX IDX_46B74ED1252C1E9 (parent_order_id), INDEX IDX_46B74ED798109C (child_order_id), PRIMARY KEY(parent_order_id, child_order_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE orders_stop_losses ADD CONSTRAINT FK_E0A47B7F1252C1E9 FOREIGN KEY (parent_order_id) REFERENCES positions (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders_stop_losses ADD CONSTRAINT FK_E0A47B7F798109C FOREIGN KEY (child_order_id) REFERENCES positions (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders_take_profits ADD CONSTRAINT FK_46B74ED1252C1E9 FOREIGN KEY (parent_order_id) REFERENCES positions (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders_take_profits ADD CONSTRAINT FK_46B74ED798109C FOREIGN KEY (child_order_id) REFERENCES positions (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('DROP TABLE user_config');
        $this->addSql('DROP TABLE lock_keys');
    }
}
