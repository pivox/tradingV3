create function missing_kline_opentimes(p_symbol text, p_timeframe text, p_start timestamp with time zone, p_end timestamp with time zone)
    returns TABLE(missing_open_time timestamp with time zone)
                 language plpgsql
as
$$
DECLARE
    v_step   interval;
v_start  timestamptz;
v_end    timestamptz;
BEGIN
    IF p_end <= p_start THEN
        RAISE EXCEPTION 'p_end (=%) doit être > p_start (=%)', p_end, p_start;
END IF;

    -- Déterminer le pas selon le timeframe
v_step := CASE p_timeframe
                  WHEN '1m'  THEN interval '1 minute'
                  WHEN '5m'  THEN interval '5 minutes'
                  WHEN '15m' THEN interval '15 minutes'
                  WHEN '1h'  THEN interval '1 hour'
                  WHEN '4h'  THEN interval '4 hours'
                  WHEN '1d'  THEN interval '1 day'
                  ELSE NULL
        END;
IF v_step IS NULL THEN
        RAISE EXCEPTION 'Timeframe inconnu: % (attendu: 1m,5m,15m,1h,4h,1d)', p_timeframe;
END IF;

    -- Aligner p_start sur le début de son bucket
v_start :=
            CASE p_timeframe
                WHEN '1m'  THEN date_trunc('minute', p_start)
                WHEN '5m'  THEN date_trunc('hour', p_start)
                    + make_interval(mins => (floor(extract(minute from p_start)::numeric / 5)*5)::int)
                WHEN '15m' THEN date_trunc('hour', p_start)
                    + make_interval(mins => (floor(extract(minute from p_start)::numeric / 15)*15)::int)
                WHEN '1h'  THEN date_trunc('hour', p_start)
                WHEN '4h'  THEN date_trunc('day', p_start)
                    + make_interval(hours => (floor(extract(hour from p_start)::numeric / 4)*4)::int)
                WHEN '1d'  THEN date_trunc('day', p_start)
                END;

    -- Aligner p_end sur le début de son bucket
v_end :=
            CASE p_timeframe
                WHEN '1m'  THEN date_trunc('minute', p_end)
                WHEN '5m'  THEN date_trunc('hour', p_end)
                    + make_interval(mins => (floor(extract(minute from p_end)::numeric / 5)*5)::int)
                WHEN '15m' THEN date_trunc('hour', p_end)
                    + make_interval(mins => (floor(extract(minute from p_end)::numeric / 15)*15)::int)
                WHEN '1h'  THEN date_trunc('hour', p_end)
                WHEN '4h'  THEN date_trunc('day', p_end)
                    + make_interval(hours => (floor(extract(hour from p_end)::numeric / 4)*4)::int)
                WHEN '1d'  THEN date_trunc('day', p_end)
                END;

RETURN QUERY
        WITH expected AS (
            SELECT gs AS open_time
            FROM generate_series(v_start, v_end, v_step) AS gs
        )
SELECT e.open_time
FROM expected e
         LEFT JOIN klines k
                   ON k.symbol = p_symbol
                       AND k.timeframe = p_timeframe
                       AND k.open_time = e.open_time
WHERE k.open_time IS NULL
ORDER BY e.open_time;
END;
$$;

alter function missing_kline_opentimes(text, text, timestamp with time zone, timestamp with time zone) owner to postgres;

create function missing_kline_opentimes(p_symbol text, p_timeframe text, p_start timestamp without time zone, p_end timestamp without time zone)
    returns TABLE(missing_open_time timestamp with time zone)
                 language plpgsql
as
$$
BEGIN
    -- Conversion explicite en timestamptz en assumant UTC
    RETURN QUERY
SELECT *
FROM missing_kline_opentimes(
    p_symbol,
    p_timeframe,
    p_start AT TIME ZONE 'UTC',
    p_end   AT TIME ZONE 'UTC'
     );
END;
$$;

alter function missing_kline_opentimes(text, text, timestamp, timestamp) owner to postgres;

