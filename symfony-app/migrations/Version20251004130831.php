<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251004130831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE blacklisted_contracts (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(50) NOT NULL, reason VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', no_response_streak SMALLINT UNSIGNED NOT NULL, last_no_response_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_blacklist_symbol (symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE orders (id BIGINT AUTO_INCREMENT NOT NULL, contract_pipeline_id BIGINT NOT NULL, exchange_order_id VARCHAR(64) DEFAULT NULL, client_order_id VARCHAR(64) NOT NULL, symbol VARCHAR(32) NOT NULL, side VARCHAR(8) NOT NULL, type VARCHAR(16) NOT NULL, price NUMERIC(18, 8) DEFAULT NULL, size NUMERIC(24, 10) NOT NULL, leverage INT NOT NULL, open_type VARCHAR(16) NOT NULL, plan_order_id VARCHAR(64) DEFAULT NULL, trigger_price NUMERIC(18, 8) DEFAULT NULL, execution_price NUMERIC(18, 8) DEFAULT NULL, activation_price NUMERIC(18, 8) DEFAULT NULL, callback_rate NUMERIC(10, 6) DEFAULT NULL, status VARCHAR(24) NOT NULL, reason VARCHAR(255) DEFAULT NULL, state_raw INT DEFAULT NULL, deal_size NUMERIC(24, 10) NOT NULL, deal_avg_price NUMERIC(18, 8) DEFAULT NULL, fees_cum NUMERIC(18, 8) NOT NULL, last_trade_id VARCHAR(64) DEFAULT NULL, last_fill_qty NUMERIC(24, 10) DEFAULT NULL, last_fill_price NUMERIC(18, 8) DEFAULT NULL, last_fee NUMERIC(18, 8) DEFAULT NULL, last_fee_ccy VARCHAR(16) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', opened_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', filled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', server_update_time_ms BIGINT DEFAULT NULL, INDEX idx_contract_pipeline (contract_pipeline_id), INDEX idx_symbol (symbol), INDEX idx_status (status), UNIQUE INDEX uniq_client_order_id (client_order_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE blacklisted_contracts');
        $this->addSql('DROP TABLE orders');
    }
}
