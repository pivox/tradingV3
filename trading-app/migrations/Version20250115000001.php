<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour transformer la vue missing_kline_chunks_params_v en fonction
 */
final class Version20250115000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transform missing_kline_chunks_params_v view into a function for better performance and flexibility';
    }

    public function up(Schema $schema): void
    {
        // Supprimer l'ancienne vue
        $this->addSql('DROP VIEW IF EXISTS missing_kline_chunks_params_v');

        // Créer la nouvelle fonction
        $this->addSql('
            CREATE OR REPLACE FUNCTION get_missing_kline_chunks(
                p_symbol TEXT,
                p_timeframe TEXT,
                p_start TIMESTAMPTZ,
                p_end TIMESTAMPTZ,
                p_max_per_request INTEGER DEFAULT 500
            )
            RETURNS TABLE(
                symbol TEXT,
                step INTEGER,
                "from" BIGINT,
                "to" BIGINT
            )
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_step_min INTEGER;
                v_step_interval INTERVAL;
            BEGIN
                -- Validation des paramètres
                IF p_end <= p_start THEN
                    RAISE EXCEPTION \'p_end (=%) doit être > p_start (=%)\', p_end, p_start;
                END IF;

                -- Déterminer le step en minutes et l\'intervalle
                CASE p_timeframe
                    WHEN \'1m\' THEN
                        v_step_min := 1;
                        v_step_interval := interval \'1 minute\';
                    WHEN \'5m\' THEN
                        v_step_min := 5;
                        v_step_interval := interval \'5 minutes\';
                    WHEN \'15m\' THEN
                        v_step_min := 15;
                        v_step_interval := interval \'15 minutes\';
                    WHEN \'1h\' THEN
                        v_step_min := 60;
                        v_step_interval := interval \'1 hour\';
                    WHEN \'4h\' THEN
                        v_step_min := 240;
                        v_step_interval := interval \'4 hours\';
                    WHEN \'1d\' THEN
                        v_step_min := 1440;
                        v_step_interval := interval \'1 day\';
                    ELSE
                        RAISE EXCEPTION \'Timeframe inconnu: % (attendu: 1m,5m,15m,1h,4h,1d)\', p_timeframe;
                END CASE;

                -- Retourner les chunks manquants
                RETURN QUERY
                WITH
                    missing AS (
                        SELECT m.missing_open_time AS open_time, v_step_interval AS v_step
                        FROM missing_kline_opentimes(p_symbol, p_timeframe, p_start, p_end) AS m
                    ),
                    runs AS (
                        SELECT
                            open_time,
                            v_step,
                            (open_time - (row_number() OVER (ORDER BY open_time)) * v_step) AS grp
                        FROM missing
                    ),
                    ranges AS (
                        SELECT
                            min(open_time) AS range_start,
                            max(open_time) + MIN(v_step) AS range_end_excl,
                            count(*) AS candles_missing,
                            MIN(v_step) AS v_step
                        FROM runs
                        GROUP BY grp
                    ),
                    chunked AS (
                        SELECT
                            r.range_start,
                            r.range_end_excl,
                            r.candles_missing,
                            r.v_step,
                            p_max_per_request AS max_per_request,
                            v_step_min AS step_min,
                            gs.idx AS chunk_index,
                            LEAST(p_max_per_request,
                                  r.candles_missing - (gs.idx * p_max_per_request)) AS chunk_size,
                            (r.range_start + (gs.idx * p_max_per_request) * r.v_step) AS chunk_start,
                            (r.range_start + (gs.idx * p_max_per_request
                               + LEAST(p_max_per_request, r.candles_missing - (gs.idx * p_max_per_request))) * r.v_step) AS chunk_end_excl
                        FROM ranges r
                        CROSS JOIN LATERAL generate_series(
                            0,
                            GREATEST(ceil((r.candles_missing::numeric) / p_max_per_request)::int - 1, 0)
                        ) AS gs(idx)
                    )
                SELECT
                    p_symbol AS symbol,
                    v_step_min AS step,
                    extract(epoch from chunk_start)::bigint AS "from",
                    extract(epoch from chunk_end_excl)::bigint AS "to"
                FROM chunked
                ORDER BY chunk_start, chunk_index;
            END;
            $$;
        ');

        // Ajouter un commentaire sur la fonction
        $this->addSql('COMMENT ON FUNCTION get_missing_kline_chunks(TEXT, TEXT, TIMESTAMPTZ, TIMESTAMPTZ, INTEGER) IS \'Retourne les chunks de klines manquantes pour un symbole et timeframe donnés\'');
    }

    public function down(Schema $schema): void
    {
        // Supprimer la fonction
        $this->addSql('DROP FUNCTION IF EXISTS get_missing_kline_chunks(TEXT, TEXT, TIMESTAMPTZ, TIMESTAMPTZ, INTEGER)');

        // Recréer l'ancienne vue
        $this->addSql('
            CREATE OR REPLACE VIEW missing_kline_chunks_params_v AS
            WITH
                params AS (
                    SELECT
                        current_setting(\'app.symbol\',     true)                AS p_symbol,
                        current_setting(\'app.timeframe\',  true)                AS p_timeframe,
                        (current_setting(\'app.start\',     true))::timestamptz  AS p_start,
                        (current_setting(\'app."end"\',     true))::timestamptz  AS p_end,
                        COALESCE((current_setting(\'app.max_per_request\', true))::int, 500) AS max_per_request,
                        /* mapping BitMart (minutes) */
                        CASE current_setting(\'app.timeframe\', true)
                            WHEN \'1m\'  THEN  1
                            WHEN \'5m\'  THEN  5
                            WHEN \'15m\' THEN 15
                            WHEN \'1h\'  THEN 60
                            WHEN \'4h\'  THEN 240
                            WHEN \'1d\'  THEN 1440
                            ELSE NULL
                            END AS step_min,
                        /* pas temporel SQL */
                        CASE current_setting(\'app.timeframe\', true)
                            WHEN \'1m\'  THEN interval \'1 minute\'
                            WHEN \'5m\'  THEN interval \'5 minutes\'
                            WHEN \'15m\' THEN interval \'15 minutes\'
                            WHEN \'1h\'  THEN interval \'1 hour\'
                            WHEN \'4h\'  THEN interval \'4 hours\'
                            WHEN \'1d\'  THEN interval \'1 day\'
                            ELSE NULL
                            END AS v_step
                ),
                missing AS (
                    SELECT m.missing_open_time AS open_time, p.v_step
                    FROM params p
                             CROSS JOIN LATERAL missing_kline_opentimes(p.p_symbol, p.p_timeframe, p.p_start, p.p_end) AS m
            ),
            runs AS (
              SELECT
                open_time,
                v_step,
                (open_time - (row_number() OVER (ORDER BY open_time)) * v_step) AS grp
              FROM missing
            ),
            ranges AS (
              SELECT
                min(open_time)                       AS range_start,
                max(open_time) + MIN(v_step)         AS range_end_excl,  -- exclusif
                count(*)                             AS candles_missing,
                MIN(v_step)                          AS v_step
              FROM runs
              GROUP BY grp
            ),
            chunked AS (
              SELECT
                r.range_start,
                r.range_end_excl,
                r.candles_missing,
                r.v_step,
                p.max_per_request,
                p.step_min,
                p.p_symbol,
                p.p_timeframe,
                gs.idx AS chunk_index,
                LEAST(p.max_per_request,
                      r.candles_missing - (gs.idx * p.max_per_request)) AS chunk_size,
                (r.range_start + (gs.idx * p.max_per_request) * r.v_step) AS chunk_start,
                (r.range_start + (gs.idx * p.max_per_request
                   + LEAST(p.max_per_request, r.candles_missing - (gs.idx * p.max_per_request))) * r.v_step) AS chunk_end_excl
              FROM ranges r
              CROSS JOIN params p
              CROSS JOIN LATERAL generate_series(
                  0,
                  GREATEST(ceil((r.candles_missing::numeric) / p.max_per_request)::int - 1, 0)
              ) AS gs(idx)
            )
            SELECT
                p_symbol                     AS symbol,
                step_min                     AS step,
                extract(epoch from chunk_start)::bigint    AS "from",
                extract(epoch from chunk_end_excl)::bigint AS "to"
            FROM chunked
            ORDER BY chunk_start, chunk_index;
        ');
    }
}
