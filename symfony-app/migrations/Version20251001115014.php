<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251001115014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE batch_run (id BIGINT AUTO_INCREMENT NOT NULL, run_key VARCHAR(64) NOT NULL, timeframe VARCHAR(8) NOT NULL, end_at_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expected_count INT NOT NULL, persisted_count INT DEFAULT 0 NOT NULL, validated_count INT DEFAULT 0 NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_run_key (run_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE batch_run_item (id BIGINT AUTO_INCREMENT NOT NULL, run_key VARCHAR(64) NOT NULL, symbol VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, meta JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', persisted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', validated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_run_symbol (run_key, symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('DROP TABLE trade_events');
        $this->addSql('ALTER TABLE contract_pipeline ADD order_id VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE kline DROP FOREIGN KEY FK_D890DAAB7E5B2922');
        $this->addSql('ALTER TABLE kline DROP FOREIGN KEY FK_D890DAABF5ED5716');
        $this->addSql('DROP INDEX IDX_D890DAABF5ED5716 ON kline');
        $this->addSql('DROP INDEX IDX_D890DAAB7E5B2922 ON kline');
        $this->addSql('ALTER TABLE kline DROP from_kline_id, DROP to_kline_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE trade_events (id BIGINT AUTO_INCREMENT NOT NULL, aggregate_type VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, aggregate_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, type VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, payload JSON DEFAULT NULL, context JSON DEFAULT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', event_key VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, INDEX idx_trade_events_type (type), INDEX idx_trade_events_agg_time (aggregate_type, aggregate_id, occurred_at), UNIQUE INDEX UNIQ_6AAEE9589B5F6E7A (event_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('DROP TABLE batch_run');
        $this->addSql('DROP TABLE batch_run_item');
        $this->addSql('ALTER TABLE contract_pipeline DROP order_id');
        $this->addSql('ALTER TABLE kline ADD from_kline_id INT DEFAULT NULL, ADD to_kline_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE kline ADD CONSTRAINT FK_D890DAAB7E5B2922 FOREIGN KEY (from_kline_id) REFERENCES kline (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('ALTER TABLE kline ADD CONSTRAINT FK_D890DAABF5ED5716 FOREIGN KEY (to_kline_id) REFERENCES kline (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D890DAABF5ED5716 ON kline (to_kline_id)');
        $this->addSql('CREATE INDEX IDX_D890DAAB7E5B2922 ON kline (from_kline_id)');
    }
}
