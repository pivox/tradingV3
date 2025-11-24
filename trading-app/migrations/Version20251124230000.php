<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251124230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add run_id column to signals and extend uniqueness to (symbol, timeframe, kline_time, run_id)';
    }

    public function up(Schema $schema): void
    {
        $defaultRunId = '00000000-0000-0000-0000-000000000000';

        $this->addSql("ALTER TABLE signals ADD run_id VARCHAR(36) NOT NULL DEFAULT '$defaultRunId'");
        $this->addSql("UPDATE signals SET run_id = COALESCE(NULLIF(meta->>'run_id', ''), '$defaultRunId')");
        $this->addSql('ALTER TABLE signals DROP CONSTRAINT IF EXISTS ux_signals_symbol_tf_time');
        $this->addSql('DROP INDEX IF EXISTS ux_signals_symbol_tf_time');
        $this->addSql('ALTER TABLE signals ADD CONSTRAINT ux_signals_symbol_tf_time_run UNIQUE (symbol, timeframe, kline_time, run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signals DROP CONSTRAINT IF EXISTS ux_signals_symbol_tf_time_run');
        $this->addSql('ALTER TABLE signals ADD CONSTRAINT ux_signals_symbol_tf_time UNIQUE (symbol, timeframe, kline_time)');
        $this->addSql('ALTER TABLE signals DROP COLUMN run_id');
    }
}
