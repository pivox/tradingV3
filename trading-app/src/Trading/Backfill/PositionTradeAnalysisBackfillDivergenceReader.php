<?php

declare(strict_types=1);

namespace App\Trading\Backfill;

use Doctrine\DBAL\Connection;

final readonly class PositionTradeAnalysisBackfillDivergenceReader implements PositionTradeAnalysisBackfillDivergenceReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function fetchRows(BackfillDivergenceCriteria $criteria): array
    {
        $where = [];
        $params = [
            'limit' => $criteria->limit,
        ];

        if ($criteria->resumeCursor !== null) {
            $where[] = 'COALESCE(v1.entry_event_id, v2.entry_event_id) > :resume_cursor';
            $params['resume_cursor'] = $criteria->resumeCursor;
        }
        if ($criteria->from !== null) {
            $where[] = 'COALESCE(v2.entry_time, v1.entry_time) >= :from_ts';
            $params['from_ts'] = $criteria->from->format('Y-m-d H:i:s.uP');
        }
        if ($criteria->to !== null) {
            $where[] = 'COALESCE(v2.entry_time, v1.entry_time) <= :to_ts';
            $params['to_ts'] = $criteria->to->format('Y-m-d H:i:s.uP');
        }
        if ($criteria->symbol !== null && $criteria->symbol !== '') {
            $where[] = 'COALESCE(v2.symbol, v1.symbol) = :symbol';
            $params['symbol'] = $criteria->symbol;
        }
        if ($criteria->profile !== null && $criteria->profile !== '') {
            $where[] = 'v2.mtf_profile = :profile';
            $params['profile'] = $criteria->profile;
        }
        if ($criteria->exchange !== null && $criteria->exchange !== '') {
            $where[] = 'v2.exchange = :exchange';
            $params['exchange'] = $criteria->exchange;
        }
        if ($criteria->marketType !== null && $criteria->marketType !== '') {
            $where[] = 'v2.market_type = :market_type';
            $params['market_type'] = $criteria->marketType;
        }

        $sql = <<<SQL
SELECT
    COALESCE(v1.entry_event_id, v2.entry_event_id) AS entry_event_id,
    (v1.entry_event_id IS NOT NULL) AS v1_present,
    (v2.entry_event_id IS NOT NULL) AS v2_present,
    COALESCE(v2.symbol, v1.symbol) AS symbol,
    v2.exchange AS exchange,
    v2.market_data_venue AS market_data_venue,
    v2.market_type AS market_type,
    v2.mtf_profile AS profile,
    COALESCE(v2.entry_time, v1.entry_time) AS entry_time,
    v1.close_event_id AS v1_close_event_id,
    v2.close_event_id AS v2_close_event_id,
    v1.pnl_usdt AS v1_pnl_usdt,
    v2.recorded_pnl_usdt AS v2_recorded_pnl_usdt,
    v2.gross_realized_pnl_usdt AS v2_gross_realized_pnl_usdt,
    to_jsonb(v2)->> 'canonical_net_pnl_usdt' AS v2_net_pnl_usdt,
    v1.holding_time_sec AS v1_holding_time_sec,
    to_jsonb(v2)->> 'canonical_holding_time_sec' AS v2_holding_time_sec,
    v1.mfe_pct AS v1_mfe_pct,
    CASE
        WHEN to_jsonb(v2)->> 'canonical_mfe_mae_data_quality' = 'complete' THEN v2.mfe_pct
        ELSE NULL
    END AS v2_mfe_pct,
    v1.mae_pct AS v1_mae_pct,
    CASE
        WHEN to_jsonb(v2)->> 'canonical_mfe_mae_data_quality' = 'complete' THEN v2.mae_pct
        ELSE NULL
    END AS v2_mae_pct,
    to_jsonb(v2)->> 'paper_network' AS v2_paper_network,
    to_jsonb(v2)->> 'mode_id' AS v2_mode_id,
    to_jsonb(v2)->> 'mode_version' AS v2_mode_version,
    to_jsonb(v2)->> 'setup_id' AS v2_setup_id,
    to_jsonb(v2)->> 'setup_version' AS v2_setup_version,
    to_jsonb(v2)->> 'canonical_side' AS v2_canonical_side,
    to_jsonb(v2)->> 'canonical_config_hash' AS v2_canonical_config_hash,
    v2.internal_trade_id AS v2_internal_trade_id,
    v2.trade_id AS v2_trade_id,
    v2.position_id AS v2_position_id,
    v2.close_match_status AS v2_close_match_status,
    v2.close_matched_by AS v2_close_matched_by,
    v2.analysis_status AS v2_analysis_status,
    to_jsonb(v2)->> 'lineage_classification' AS v2_lineage_classification,
    to_jsonb(v2)->> 'canonical_cost_completeness' AS v2_cost_completeness,
    v2.position_fully_closed AS v2_position_fully_closed,
    to_jsonb(v2)-> 'canonical_pnl_quality_flags' AS v2_pnl_quality_flags,
    to_jsonb(v2)->> 'holding_time_source' AS v2_holding_time_source,
    to_jsonb(v2)->> 'mfe_mae_window_source' AS v2_mfe_mae_window_source,
    to_jsonb(v2)->> 'mfe_mae_entry_price_source' AS v2_mfe_mae_entry_price_source,
    to_jsonb(v2)->> 'canonical_mfe_mae_data_quality' AS v2_mfe_mae_data_quality
FROM position_trade_analysis v1
FULL OUTER JOIN position_trade_analysis_v2 v2
    ON v2.entry_event_id = v1.entry_event_id
SQL;

        if ($where !== []) {
            $sql .= "\nWHERE " . implode("\n  AND ", $where);
        }

        $sql .= "\nORDER BY COALESCE(v1.entry_event_id, v2.entry_event_id) ASC\nLIMIT :limit";

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return $rows;
    }
}
