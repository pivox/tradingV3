<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251009110628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE event_dedup (id INT AUTO_INCREMENT NOT NULL, event_id VARCHAR(64) NOT NULL, source VARCHAR(16) NOT NULL, processed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX pk_event_source (event_id, source), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE latest_signal_by_tf (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(32) NOT NULL, tf VARCHAR(8) NOT NULL, slot_start_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', at_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', side VARCHAR(8) NOT NULL, passed TINYINT(1) NOT NULL, score DOUBLE PRECISION DEFAULT NULL, meta_json JSON DEFAULT NULL, UNIQUE INDEX pk_symbol_tf (symbol, tf), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE outgoing_orders (order_id VARCHAR(64) NOT NULL, symbol VARCHAR(32) NOT NULL, intent VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', dedup_key VARCHAR(64) DEFAULT NULL, PRIMARY KEY(order_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pending_child_signals (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(32) NOT NULL, tf VARCHAR(8) NOT NULL, slot_start_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', payload_json JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX pk_symbol_tf_slot (symbol, tf, slot_start_utc), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE signal_events (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(32) NOT NULL, tf VARCHAR(8) NOT NULL, slot_start_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', passed TINYINT(1) NOT NULL, side VARCHAR(8) NOT NULL, score DOUBLE PRECISION DEFAULT NULL, at_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', meta_json JSON DEFAULT NULL, INDEX idx_read (symbol, tf, at_utc), UNIQUE INDEX uniq_signal (symbol, tf, slot_start_utc), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tf_eligibility (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(32) NOT NULL, tf VARCHAR(8) NOT NULL, status VARCHAR(16) NOT NULL, priority INT NOT NULL, cooldown_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', reason VARCHAR(255) DEFAULT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_tf_status (tf, status, priority, updated_at), UNIQUE INDEX pk_symbol_tf (symbol, tf), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tf_retry_status (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(32) NOT NULL, tf VARCHAR(8) NOT NULL, retry_count INT NOT NULL, last_result VARCHAR(8) NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX pk_symbol_tf (symbol, tf), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE event_dedup');
        $this->addSql('DROP TABLE latest_signal_by_tf');
        $this->addSql('DROP TABLE outgoing_orders');
        $this->addSql('DROP TABLE pending_child_signals');
        $this->addSql('DROP TABLE signal_events');
        $this->addSql('DROP TABLE tf_eligibility');
        $this->addSql('DROP TABLE tf_retry_status');
    }
}
