CREATE OR REPLACE VIEW missing_kline_chunks_params_v AS
WITH
    params AS (
        SELECT
            current_setting('app.symbol',     true)                AS p_symbol,
            current_setting('app.timeframe',  true)                AS p_timeframe,
            (current_setting('app.start',     true))::timestamptz  AS p_start,
            (current_setting('app."end"',     true))::timestamptz  AS p_end,
            COALESCE((current_setting('app.max_per_request', true))::int, 500) AS max_per_request,
            /* mapping BitMart (minutes) */
            CASE current_setting('app.timeframe', true)
                WHEN '1m'  THEN  1
                WHEN '5m'  THEN  5
                WHEN '15m' THEN 15
                WHEN '1h'  THEN 60
                WHEN '4h'  THEN 240
                WHEN '1d'  THEN 1440
                ELSE NULL
                END AS step_min,
            /* pas temporel SQL */
            CASE current_setting('app.timeframe', true)
                WHEN '1m'  THEN interval '1 minute'
                WHEN '5m'  THEN interval '5 minutes'
                WHEN '15m' THEN interval '15 minutes'
                WHEN '1h'  THEN interval '1 hour'
                WHEN '4h'  THEN interval '4 hours'
                WHEN '1d'  THEN interval '1 day'
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
