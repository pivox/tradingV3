<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251203191232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SQL views for scalper 1m zone events and related width statistics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS v_zone_width_stats_scalper_1m');
        $this->addSql('DROP VIEW IF EXISTS v_zone_events_scalper_1m');

        $this->addSql(<<<'SQL'
CREATE OR REPLACE VIEW v_zone_events_scalper_1m AS
SELECT
    tze.symbol,
    tze.happened_at,
    tze.entry_zone_width_pct,
    tze.zone_dev_pct,
    tze.zone_max_dev_pct,
    tze.vwap_distance_pct,
    tze.mtf_level,
    tze.decision_key
FROM trade_zone_events tze
WHERE
    tze.config_profile = 'scalper'
  AND tze.timeframe = '1m';
SQL);

        $this->addSql(<<<'SQL'
CREATE OR REPLACE VIEW v_zone_width_stats_scalper_1m AS
SELECT
    CASE
        WHEN tze.entry_zone_width_pct < 0.004  THEN '[0.0%, 0.4%)'
        WHEN tze.entry_zone_width_pct < 0.008  THEN '[0.4%, 0.8%)'
        WHEN tze.entry_zone_width_pct < 0.012  THEN '[0.8%, 1.2%)'
        WHEN tze.entry_zone_width_pct < 0.016  THEN '[1.2%, 1.6%)'
        ELSE '>= 1.6%'
        END AS width_bucket,
    COUNT(*) AS event_count,
    COUNT(*) FILTER (WHERE reason = 'skipped_out_of_zone') AS skipped_out_of_zone_count
FROM trade_zone_events tze
WHERE
    tze.config_profile = 'scalper'
  AND tze.timeframe = '1m'
GROUP BY width_bucket
ORDER BY width_bucket;
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS v_zone_width_stats_scalper_1m');
        $this->addSql('DROP VIEW IF EXISTS v_zone_events_scalper_1m');
    }
}
