<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250628133132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contract (symbol VARCHAR(255) NOT NULL, exchange_name VARCHAR(255) NOT NULL, product_type VARCHAR(255) DEFAULT NULL, open_timestamp DATETIME DEFAULT NULL, expire_timestamp DATETIME DEFAULT NULL, settle_timestamp DATETIME DEFAULT NULL, base_currency VARCHAR(255) DEFAULT NULL, quote_currency VARCHAR(255) DEFAULT NULL, last_price DOUBLE PRECISION DEFAULT NULL, volume24h BIGINT DEFAULT NULL, turnover24h DOUBLE PRECISION DEFAULT NULL, index_price DOUBLE PRECISION DEFAULT NULL, index_name VARCHAR(255) DEFAULT NULL, contract_size DOUBLE PRECISION DEFAULT NULL, min_leverage INT DEFAULT NULL, max_leverage INT DEFAULT NULL, price_precision DOUBLE PRECISION DEFAULT NULL, vol_precision DOUBLE PRECISION DEFAULT NULL, max_volume INT DEFAULT NULL, min_volume INT DEFAULT NULL, funding_rate DOUBLE PRECISION DEFAULT NULL, expected_funding_rate DOUBLE PRECISION DEFAULT NULL, open_interest BIGINT DEFAULT NULL, open_interest_value DOUBLE PRECISION DEFAULT NULL, high24h DOUBLE PRECISION DEFAULT NULL, low24h DOUBLE PRECISION DEFAULT NULL, change24h DOUBLE PRECISION DEFAULT NULL, funding_time DATETIME DEFAULT NULL, market_max_volume INT DEFAULT NULL, funding_interval_hours INT DEFAULT NULL, status VARCHAR(255) DEFAULT NULL, delist_time DATETIME DEFAULT NULL, next_schedule DATETIME DEFAULT NULL, INDEX IDX_E98F2859689D5E94 (exchange_name), PRIMARY KEY(symbol)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE exchange (name VARCHAR(255) NOT NULL, PRIMARY KEY(name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE kline (id INT AUTO_INCREMENT NOT NULL, contract_id VARCHAR(255) NOT NULL, timestamp DATETIME NOT NULL, open DOUBLE PRECISION NOT NULL, close DOUBLE PRECISION NOT NULL, high DOUBLE PRECISION NOT NULL, low DOUBLE PRECISION NOT NULL, volume DOUBLE PRECISION NOT NULL, `interval` VARCHAR(255) DEFAULT \'15m\' NOT NULL, INDEX IDX_D890DAAB2576E0FD (contract_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F2859689D5E94 FOREIGN KEY (exchange_name) REFERENCES exchange (name)');
        $this->addSql('INSERT INTO exchange (name) VALUES (\'bitmart\');');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract DROP FOREIGN KEY FK_E98F2859689D5E94');
        $this->addSql('ALTER TABLE kline DROP FOREIGN KEY FK_D890DAAB2576E0FD');
        $this->addSql('DROP TABLE contract');
        $this->addSql('DROP TABLE exchange');
        $this->addSql('DROP TABLE kline');
    }
}
