<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251004223215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE batch_run_items (id INT AUTO_INCREMENT NOT NULL, batch_run_id INT NOT NULL, symbol VARCHAR(50) NOT NULL, status VARCHAR(12) NOT NULL, attempts SMALLINT UNSIGNED NOT NULL, last_error VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_90B87CF5571898B4 (batch_run_id), INDEX idx_batch_symbol (batch_run_id, symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE batch_runs (id INT AUTO_INCREMENT NOT NULL, timeframe VARCHAR(10) NOT NULL, slot_start_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', slot_end_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(16) NOT NULL, snapshot_done TINYINT(1) DEFAULT 0 NOT NULL, snapshot_source VARCHAR(16) DEFAULT NULL, total_planned INT UNSIGNED NOT NULL, remaining INT UNSIGNED NOT NULL, total_enqueued INT UNSIGNED NOT NULL, total_completed INT UNSIGNED NOT NULL, total_failed INT UNSIGNED NOT NULL, total_skipped INT UNSIGNED NOT NULL, meta JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', started_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ended_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', version INT DEFAULT 1 NOT NULL, INDEX idx_tf_status (timeframe, status), UNIQUE INDEX uniq_tf_slot (timeframe, slot_start_utc), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE orders_take_profits (parent_order_id BIGINT NOT NULL, child_order_id BIGINT NOT NULL, INDEX IDX_46B74ED1252C1E9 (parent_order_id), INDEX IDX_46B74ED798109C (child_order_id), PRIMARY KEY(parent_order_id, child_order_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE orders_stop_losses (parent_order_id BIGINT NOT NULL, child_order_id BIGINT NOT NULL, INDEX IDX_E0A47B7F1252C1E9 (parent_order_id), INDEX IDX_E0A47B7F798109C (child_order_id), PRIMARY KEY(parent_order_id, child_order_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE batch_run_items ADD CONSTRAINT FK_90B87CF5571898B4 FOREIGN KEY (batch_run_id) REFERENCES batch_runs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders_take_profits ADD CONSTRAINT FK_46B74ED1252C1E9 FOREIGN KEY (parent_order_id) REFERENCES positions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders_take_profits ADD CONSTRAINT FK_46B74ED798109C FOREIGN KEY (child_order_id) REFERENCES positions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders_stop_losses ADD CONSTRAINT FK_E0A47B7F1252C1E9 FOREIGN KEY (parent_order_id) REFERENCES positions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders_stop_losses ADD CONSTRAINT FK_E0A47B7F798109C FOREIGN KEY (child_order_id) REFERENCES positions (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE batch_run');
        $this->addSql('DROP TABLE batch_run_item');
        $this->addSql('ALTER TABLE orders ADD stop_loss DOUBLE PRECISION DEFAULT NULL, ADD take_profit DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE batch_run (id BIGINT AUTO_INCREMENT NOT NULL, run_key VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, timeframe VARCHAR(8) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, end_at_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expected_count INT NOT NULL, persisted_count INT DEFAULT 0 NOT NULL, validated_count INT DEFAULT 0 NOT NULL, status VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_run_key (run_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE batch_run_item (id BIGINT AUTO_INCREMENT NOT NULL, run_key VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, symbol VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, status VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, meta JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', persisted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', validated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_run_symbol (run_key, symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE batch_run_items DROP FOREIGN KEY FK_90B87CF5571898B4');
        $this->addSql('ALTER TABLE orders_take_profits DROP FOREIGN KEY FK_46B74ED1252C1E9');
        $this->addSql('ALTER TABLE orders_take_profits DROP FOREIGN KEY FK_46B74ED798109C');
        $this->addSql('ALTER TABLE orders_stop_losses DROP FOREIGN KEY FK_E0A47B7F1252C1E9');
        $this->addSql('ALTER TABLE orders_stop_losses DROP FOREIGN KEY FK_E0A47B7F798109C');
        $this->addSql('DROP TABLE batch_run_items');
        $this->addSql('DROP TABLE batch_runs');
        $this->addSql('DROP TABLE orders_take_profits');
        $this->addSql('DROP TABLE orders_stop_losses');
        $this->addSql('ALTER TABLE orders DROP stop_loss, DROP take_profit');
    }
}
