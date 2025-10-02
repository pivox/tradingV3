<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251002102958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE trading_configuration (id INT AUTO_INCREMENT NOT NULL, context VARCHAR(40) NOT NULL, scope VARCHAR(120) DEFAULT NULL, budget_cap_usdt NUMERIC(12, 2) DEFAULT NULL, risk_abs_usdt NUMERIC(12, 2) DEFAULT NULL, tp_abs_usdt NUMERIC(12, 2) DEFAULT NULL, banned_contracts LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_trading_configuration_context_scope (context, scope), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_exchange_account (id INT AUTO_INCREMENT NOT NULL, user_id VARCHAR(120) NOT NULL, exchange VARCHAR(60) NOT NULL, last_balance_sync_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_order_sync_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_balance NUMERIC(20, 8) DEFAULT NULL, balance NUMERIC(20, 8) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_user_exchange (user_id, exchange), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE trading_configuration');
        $this->addSql('DROP TABLE user_exchange_account');
    }
}
