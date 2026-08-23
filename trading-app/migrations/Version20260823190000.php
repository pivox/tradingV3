<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823190000 extends AbstractMigration
{
    /** @var list<string> */
    private const TRADE_TABLES = [
        'order_intent',
        'trade_lineage',
        'trade_lifecycle_event',
        'fill_cost_ledger',
        'trade_zone_events',
    ];

    public function getDescription(): string
    {
        return 'Allow exact modern Paper provenance to be marked baseline eligible.';
    }

    public function up(Schema $schema): void
    {
        $this->replaceCellConstraint("eligibility IN ('reference_only', 'baseline_eligible')");
        foreach (self::TRADE_TABLES as $table) {
            $this->replaceTradeConstraint(
                $table,
                "paper_eligibility IS NULL OR paper_eligibility IN ('reference_only', 'baseline_eligible')",
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM paper_execution_cell WHERE eligibility = 'baseline_eligible')
        OR EXISTS (SELECT 1 FROM order_intent WHERE paper_eligibility = 'baseline_eligible')
        OR EXISTS (SELECT 1 FROM trade_lineage WHERE paper_eligibility = 'baseline_eligible')
        OR EXISTS (SELECT 1 FROM trade_lifecycle_event WHERE paper_eligibility = 'baseline_eligible')
        OR EXISTS (SELECT 1 FROM fill_cost_ledger WHERE paper_eligibility = 'baseline_eligible')
        OR EXISTS (SELECT 1 FROM trade_zone_events WHERE paper_eligibility = 'baseline_eligible')
    THEN
        RAISE EXCEPTION 'paper_baseline_eligible_evidence_blocks_downgrade';
    END IF;
END
$$
SQL);
        foreach (array_reverse(self::TRADE_TABLES) as $table) {
            $this->replaceTradeConstraint(
                $table,
                "paper_eligibility IS NULL OR paper_eligibility IN ('reference_only')",
            );
        }
        $this->replaceCellConstraint("eligibility IN ('reference_only')");
    }

    private function replaceCellConstraint(string $predicate): void
    {
        $this->addSql('ALTER TABLE paper_execution_cell DROP CONSTRAINT chk_paper_execution_cell_eligibility');
        $this->addSql(sprintf(
            'ALTER TABLE paper_execution_cell ADD CONSTRAINT chk_paper_execution_cell_eligibility CHECK (%s)',
            $predicate,
        ));
    }

    private function replaceTradeConstraint(string $table, string $predicate): void
    {
        $constraint = 'chk_' . $table . '_paper_eligibility';
        $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $constraint));
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
            $table,
            $constraint,
            $predicate,
        ));
    }
}
