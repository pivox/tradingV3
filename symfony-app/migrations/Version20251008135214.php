<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251008135214 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE batch_run_items DROP FOREIGN KEY FK_90B87CF5571898B4');
        $this->addSql('DROP TABLE batch_run_items');
        $this->addSql('DROP TABLE batch_runs');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE batch_run_items (id INT AUTO_INCREMENT NOT NULL, batch_run_id INT NOT NULL, symbol VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, status VARCHAR(12) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, attempts SMALLINT UNSIGNED NOT NULL, last_error VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_90B87CF5571898B4 (batch_run_id), INDEX idx_batch_symbol (batch_run_id, symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE batch_runs (id INT AUTO_INCREMENT NOT NULL, timeframe VARCHAR(10) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, slot_start_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', slot_end_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, snapshot_done TINYINT(1) DEFAULT 0 NOT NULL, snapshot_source VARCHAR(16) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, total_planned INT UNSIGNED NOT NULL, remaining INT UNSIGNED NOT NULL, total_enqueued INT UNSIGNED NOT NULL, total_completed INT UNSIGNED NOT NULL, total_failed INT UNSIGNED NOT NULL, total_skipped INT UNSIGNED NOT NULL, meta JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', started_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ended_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', version INT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_tf_slot (timeframe, slot_start_utc), INDEX idx_tf_status (timeframe, status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE batch_run_items ADD CONSTRAINT FK_90B87CF5571898B4 FOREIGN KEY (batch_run_id) REFERENCES batch_runs (id) ON UPDATE NO ACTION ON DELETE CASCADE');
    }
}
