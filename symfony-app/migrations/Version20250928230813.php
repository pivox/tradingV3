<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250928230813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE trade_events (id BIGINT AUTO_INCREMENT NOT NULL, aggregate_type VARCHAR(32) NOT NULL, aggregate_id VARCHAR(128) NOT NULL, type VARCHAR(64) NOT NULL, payload JSON DEFAULT NULL, context JSON DEFAULT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', event_key VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_6AAEE9589B5F6E7A (event_key), INDEX idx_trade_events_agg_time (aggregate_type, aggregate_id, occurred_at), INDEX idx_trade_events_type (type), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE kline ADD from_kline_id INT DEFAULT NULL, ADD to_kline_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE kline ADD CONSTRAINT FK_D890DAAB7E5B2922 FOREIGN KEY (from_kline_id) REFERENCES kline (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE kline ADD CONSTRAINT FK_D890DAABF5ED5716 FOREIGN KEY (to_kline_id) REFERENCES kline (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D890DAAB7E5B2922 ON kline (from_kline_id)');
        $this->addSql('CREATE INDEX IDX_D890DAABF5ED5716 ON kline (to_kline_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE trade_events');
        $this->addSql('ALTER TABLE kline DROP FOREIGN KEY FK_D890DAAB7E5B2922');
        $this->addSql('ALTER TABLE kline DROP FOREIGN KEY FK_D890DAABF5ED5716');
        $this->addSql('DROP INDEX IDX_D890DAAB7E5B2922 ON kline');
        $this->addSql('DROP INDEX IDX_D890DAABF5ED5716 ON kline');
        $this->addSql('ALTER TABLE kline DROP from_kline_id, DROP to_kline_id');
    }
}
