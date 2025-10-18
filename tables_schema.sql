--
-- PostgreSQL database dump
--

\restrict 7J8GeQh8zmZ6yIactUioHhtfBytzI1WAJ5eh78MjTqJaA7qtAJhhaTTEEgMPIsp

-- Dumped from database version 15.14 (Debian 15.14-1.pgdg13+1)
-- Dumped by pg_dump version 15.14 (Debian 15.14-1.pgdg13+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: signal_side; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.signal_side AS ENUM (
    'LONG',
    'SHORT',
    'NONE'
);


--
-- Name: timeframe; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.timeframe AS ENUM (
    '4h',
    '1h',
    '15m',
    '5m',
    '1m'
);


--
-- Name: ema(numeric, numeric); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ema(value numeric, alpha numeric) RETURNS numeric
    LANGUAGE plpgsql IMMUTABLE
    AS $$
            BEGIN
                RETURN value * alpha;
            END;
            $$;


--
-- Helper functions to mirror indicator condition tolerances (kept in sync with PHP conditions)
--

CREATE FUNCTION public.macd_hist_gt_eps(hist numeric, eps numeric DEFAULT 1.0e-6)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN hist IS NULL THEN FALSE
        ELSE hist >= (0 - abs(COALESCE(eps, 0)))
    END;
$$;

CREATE FUNCTION public.ema_ratio_gte_with_tolerance(fast numeric, slow numeric, tolerance numeric DEFAULT 0.0008)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN fast IS NULL OR slow IS NULL OR slow = 0 THEN FALSE
        ELSE (fast / slow) - 1 >= -abs(COALESCE(tolerance, 0))
    END;
$$;

CREATE FUNCTION public.close_gte_ema_with_tolerance(close_price numeric, ema numeric, tolerance numeric DEFAULT 0.0015)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN close_price IS NULL OR ema IS NULL OR ema = 0 THEN FALSE
        ELSE (close_price / ema) - 1 >= -abs(COALESCE(tolerance, 0))
    END;
$$;

CREATE FUNCTION public.close_lte_ema_with_tolerance(close_price numeric, ema numeric, tolerance numeric DEFAULT 0.0015)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN close_price IS NULL OR ema IS NULL OR ema = 0 THEN FALSE
        ELSE (close_price / ema) - 1 <= abs(COALESCE(tolerance, 0))
    END;
$$;

CREATE FUNCTION public.rsi_lt_softcap(value numeric, threshold numeric DEFAULT 78.0)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN value IS NULL THEN FALSE
        ELSE value < COALESCE(threshold, 78.0)
    END;
$$;

CREATE FUNCTION public.rsi_gt_softfloor(value numeric, threshold numeric DEFAULT 22.0)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN value IS NULL THEN FALSE
        ELSE value > COALESCE(threshold, 22.0)
    END;
$$;

CREATE FUNCTION public.atr_rel_in_range(atr numeric, close_price numeric, min_pct numeric, max_pct numeric)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN atr IS NULL OR close_price IS NULL OR close_price <= 0 THEN FALSE
        ELSE (atr / close_price) BETWEEN COALESCE(min_pct, 0) AND COALESCE(max_pct, 1)
    END;
$$;

CREATE FUNCTION public.atr_rel_in_range_15m(atr numeric, close_price numeric)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$ SELECT public.atr_rel_in_range(atr, close_price, 0.001, 0.004); $$;

CREATE FUNCTION public.atr_rel_in_range_5m(atr numeric, close_price numeric)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$ SELECT public.atr_rel_in_range(atr, close_price, 0.0008, 0.0035); $$;

CREATE FUNCTION public.ema20_over_50_with_tolerance(ema20 numeric, ema50 numeric, tolerance numeric DEFAULT 0.0008)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$ SELECT public.ema_ratio_gte_with_tolerance(ema20, ema50, tolerance); $$;

CREATE FUNCTION public.ema_above_200_with_tolerance(close_price numeric, ema200 numeric, tolerance numeric DEFAULT 0.0015)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$ SELECT public.close_gte_ema_with_tolerance(close_price, ema200, tolerance); $$;

CREATE FUNCTION public.ema_below_200_with_tolerance(close_price numeric, ema200 numeric, tolerance numeric DEFAULT 0.0015)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
AS $$ SELECT public.close_lte_ema_with_tolerance(close_price, ema200, tolerance); $$;


--
-- Name: get_missing_kline_chunks(text, text, timestamp with time zone, timestamp with time zone, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_missing_kline_chunks(p_symbol text, p_timeframe text, p_start timestamp with time zone, p_end timestamp with time zone, p_max_per_request integer DEFAULT 500) RETURNS TABLE(symbol text, step integer, "from" bigint, "to" bigint)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_step_min INTEGER;
    v_step_interval INTERVAL;
BEGIN
    -- Validation des paramètres
    IF p_end <= p_start THEN
        RAISE EXCEPTION 'p_end (=%) doit être > p_start (=%)', p_end, p_start;
    END IF;

    -- Déterminer le step en minutes et l'intervalle
    CASE p_timeframe
        WHEN '1m' THEN
            v_step_min := 1;
            v_step_interval := interval '1 minute';
        WHEN '5m' THEN
            v_step_min := 5;
            v_step_interval := interval '5 minutes';
        WHEN '15m' THEN
            v_step_min := 15;
            v_step_interval := interval '15 minutes';
        WHEN '1h' THEN
            v_step_min := 60;
            v_step_interval := interval '1 hour';
        WHEN '4h' THEN
            v_step_min := 240;
            v_step_interval := interval '4 hours';
        WHEN '1d' THEN
            v_step_min := 1440;
            v_step_interval := interval '1 day';
        ELSE
            RAISE EXCEPTION 'Timeframe inconnu: % (attendu: 1m,5m,15m,1h,4h,1d)', p_timeframe;
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


--
-- Name: ingest_klines_json(jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ingest_klines_json(p_payload jsonb) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
/*
  But : insérer un batch de klines Bitmart en JSON dans la table 'klines'
  Idempotent grâce à (symbol, timeframe, open_time) unique.

  Exemple de JSON :
  [
    {
      "symbol": "BTCUSDT",
      "timeframe": "15m",
      "open_time": "2025-10-14T09:45:00Z",
      "open_price": "111954.0",
      "high_price": "113318.2",
      "low_price": "111892.5",
      "close_price": "113079.2",
      "volume": "123.456",
      "source": "REST"
    }
  ]
*/

INSERT INTO klines (
    symbol, timeframe, open_time,
    open_price, high_price, low_price, close_price, volume,
    source, inserted_at, updated_at
)
SELECT
    t.symbol,
    t.timeframe,
    (t.open_time)::timestamptz,
    (t.open_price)::numeric,
    (t.high_price)::numeric,
    (t.low_price)::numeric,
    (t.close_price)::numeric,
    (t.volume)::numeric,
    COALESCE(t.source, 'REST'),
    now(),
    now()
FROM jsonb_to_recordset(p_payload) AS t(
    symbol text,
    timeframe text,
    open_time text,
    open_price text,
    high_price text,
    low_price text,
    close_price text,
    volume text,
    source text
  )
ON CONFLICT (symbol, timeframe, open_time) DO NOTHING; -- idempotent, ignore doublons
END;
$$;


--
-- Name: missing_kline_opentimes(text, text, timestamp without time zone, timestamp without time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.missing_kline_opentimes(p_symbol text, p_timeframe text, p_start timestamp without time zone, p_end timestamp without time zone) RETURNS TABLE(missing_open_time timestamp with time zone)
    LANGUAGE plpgsql
    AS $$
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


--
-- Name: missing_kline_opentimes(text, text, timestamp with time zone, timestamp with time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.missing_kline_opentimes(p_symbol text, p_timeframe text, p_start timestamp with time zone, p_end timestamp with time zone) RETURNS TABLE(missing_open_time timestamp with time zone)
    LANGUAGE plpgsql
    AS $$
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


--
-- Name: rma(numeric, numeric); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.rma(value numeric, alpha numeric) RETURNS numeric
    LANGUAGE plpgsql IMMUTABLE
    AS $$
            BEGIN
                RETURN value * alpha;
            END;
            $$;


--
-- Name: safe_div(numeric, numeric); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.safe_div(numerator numeric, denominator numeric) RETURNS numeric
    LANGUAGE plpgsql IMMUTABLE
    AS $$
            BEGIN
                RETURN CASE 
                    WHEN denominator IS NULL OR denominator = 0 THEN NULL
                    ELSE numerator / denominator
                END;
            END;
            $$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: blacklisted_contract; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.blacklisted_contract (
    id integer NOT NULL,
    symbol character varying(50) DEFAULT NULL::character varying,
    reason character varying(50) DEFAULT NULL::character varying,
    created_at timestamp(0) without time zone NOT NULL,
    expires_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone
);


--
-- Name: COLUMN blacklisted_contract.created_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.blacklisted_contract.created_at IS '(DC2Type:datetime_immutable)';


--
-- Name: COLUMN blacklisted_contract.expires_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.blacklisted_contract.expires_at IS '(DC2Type:datetime_immutable)';


--
-- Name: blacklisted_contract_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.blacklisted_contract_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: contracts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contracts (
    id bigint NOT NULL,
    symbol character varying(50) NOT NULL,
    name character varying(100),
    product_type integer,
    open_timestamp bigint,
    expire_timestamp bigint,
    settle_timestamp bigint,
    base_currency character varying(20),
    quote_currency character varying(20),
    last_price numeric(24,12),
    volume_24h numeric(28,12),
    turnover_24h numeric(28,12),
    status character varying(20),
    min_size numeric(24,12),
    max_size numeric(24,12),
    tick_size numeric(24,12),
    multiplier numeric(24,12),
    inserted_at timestamp(0) without time zone NOT NULL,
    updated_at timestamp(0) without time zone NOT NULL
);


--
-- Name: COLUMN contracts.inserted_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.contracts.inserted_at IS '(DC2Type:datetime_immutable)';


--
-- Name: COLUMN contracts.updated_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.contracts.updated_at IS '(DC2Type:datetime_immutable)';


--
-- Name: contracts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.contracts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: contracts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.contracts_id_seq OWNED BY public.contracts.id;


--
-- Name: doctrine_migration_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.doctrine_migration_versions (
    version character varying(191) NOT NULL,
    executed_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    execution_time integer
);


--
-- Name: indicator_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.indicator_snapshots (
    id bigint NOT NULL,
    symbol character varying(50) NOT NULL,
    timeframe character varying(10) NOT NULL,
    kline_time timestamp(0) with time zone NOT NULL,
    "values" jsonb NOT NULL,
    inserted_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: COLUMN indicator_snapshots.kline_time; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.indicator_snapshots.kline_time IS '(DC2Type:datetimetz_immutable)';


--
-- Name: COLUMN indicator_snapshots.inserted_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.indicator_snapshots.inserted_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: COLUMN indicator_snapshots.updated_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.indicator_snapshots.updated_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: indicator_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.indicator_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: indicator_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.indicator_snapshots_id_seq OWNED BY public.indicator_snapshots.id;


--
-- Name: klines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.klines (
    id bigint NOT NULL,
    symbol character varying(50) NOT NULL,
    timeframe character varying(10) NOT NULL,
    open_time timestamp(0) with time zone NOT NULL,
    open_price numeric(24,12) NOT NULL,
    high_price numeric(24,12) NOT NULL,
    low_price numeric(24,12) NOT NULL,
    close_price numeric(24,12) NOT NULL,
    volume numeric(28,12),
    source character varying(20) DEFAULT 'REST'::text NOT NULL,
    inserted_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: COLUMN klines.open_time; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.klines.open_time IS '(DC2Type:datetimetz_immutable)';


--
-- Name: COLUMN klines.inserted_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.klines.inserted_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: COLUMN klines.updated_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.klines.updated_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: klines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.klines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: klines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.klines_id_seq OWNED BY public.klines.id;


--
-- Name: mtf_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mtf_audit (
    id bigint NOT NULL,
    symbol character varying(50) NOT NULL,
    run_id uuid NOT NULL,
    step character varying(100) NOT NULL,
    timeframe character varying(10),
    cause text,
    details jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: COLUMN mtf_audit.created_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.mtf_audit.created_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: mtf_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.mtf_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: mtf_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.mtf_audit_id_seq OWNED BY public.mtf_audit.id;


--
-- Name: mtf_lock; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mtf_lock (
    lock_key character varying(50) NOT NULL,
    process_id character varying(100) NOT NULL,
    acquired_at timestamp with time zone NOT NULL,
    expires_at timestamp with time zone,
    metadata text
);


--
-- Name: mtf_state; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mtf_state (
    id bigint NOT NULL,
    symbol character varying(50) NOT NULL,
    k4h_time timestamp with time zone,
    k1h_time timestamp with time zone,
    k15m_time timestamp with time zone,
    sides jsonb DEFAULT '{}'::jsonb NOT NULL,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    k5m_time timestamp with time zone,
    k1m_time timestamp with time zone
);


--
-- Name: mtf_state_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.mtf_state_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: mtf_state_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.mtf_state_id_seq OWNED BY public.mtf_state.id;


--
-- Name: mtf_switch; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mtf_switch (
    id bigint NOT NULL,
    switch_key character varying(100) NOT NULL,
    is_on boolean DEFAULT true NOT NULL,
    description text,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    expires_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone
);


--
-- Name: COLUMN mtf_switch.expires_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.mtf_switch.expires_at IS 'Date d''expiration de la desactivation temporaire';


--
-- Name: mtf_switch_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.mtf_switch_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: mtf_switch_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.mtf_switch_id_seq OWNED BY public.mtf_switch.id;


--
-- Name: mv_adx14_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_adx14_5m AS
 WITH base AS (
         SELECT klines.symbol,
            klines.timeframe,
            klines.open_time,
            klines.high_price,
            klines.low_price,
            klines.close_price,
            lag(klines.high_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time) AS prev_high,
            lag(klines.low_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time) AS prev_low,
            lag(klines.close_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time) AS prev_close
           FROM public.klines
          WHERE ((klines.timeframe)::text = '5m'::text)
        ), dm_tr AS (
         SELECT base.symbol,
            base.timeframe,
            date_trunc('minute'::text, base.open_time) AS bucket,
            GREATEST((base.high_price - base.prev_high), (0)::numeric) AS up_move,
            GREATEST((base.prev_low - base.low_price), (0)::numeric) AS down_move,
            GREATEST(GREATEST((base.high_price - base.low_price), abs((base.high_price - base.prev_close))), abs((base.low_price - base.prev_close))) AS tr
           FROM base
          WHERE ((base.prev_high IS NOT NULL) AND (base.prev_low IS NOT NULL) AND (base.prev_close IS NOT NULL))
        ), smoothed AS (
         SELECT dm_tr.symbol,
            dm_tr.timeframe,
            dm_tr.bucket,
            avg(
                CASE
                    WHEN (dm_tr.up_move > dm_tr.down_move) THEN dm_tr.up_move
                    ELSE (0)::numeric
                END) OVER (PARTITION BY dm_tr.symbol, dm_tr.timeframe ORDER BY dm_tr.bucket ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS plus_dm,
            avg(
                CASE
                    WHEN (dm_tr.down_move > dm_tr.up_move) THEN dm_tr.down_move
                    ELSE (0)::numeric
                END) OVER (PARTITION BY dm_tr.symbol, dm_tr.timeframe ORDER BY dm_tr.bucket ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS minus_dm,
            avg(dm_tr.tr) OVER (PARTITION BY dm_tr.symbol, dm_tr.timeframe ORDER BY dm_tr.bucket ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS atr14
           FROM dm_tr
        )
 SELECT smoothed.symbol,
    smoothed.timeframe,
    smoothed.bucket,
    ((100)::numeric * public.safe_div(smoothed.plus_dm, smoothed.atr14)) AS plus_di,
    ((100)::numeric * public.safe_div(smoothed.minus_dm, smoothed.atr14)) AS minus_di,
    avg((abs(public.safe_div((smoothed.plus_dm - smoothed.minus_dm), (smoothed.plus_dm + smoothed.minus_dm))) * (100)::numeric)) OVER (PARTITION BY smoothed.symbol, smoothed.timeframe ORDER BY smoothed.bucket ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS adx
   FROM smoothed
  WHERE ((smoothed.plus_dm IS NOT NULL) AND (smoothed.minus_dm IS NOT NULL) AND (smoothed.atr14 IS NOT NULL))
  WITH NO DATA;


--
-- Name: mv_boll20_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_boll20_5m AS
 SELECT klines.symbol,
    klines.timeframe,
    date_trunc('minute'::text, klines.open_time) AS bucket,
    avg(klines.close_price) OVER w AS sma,
    stddev_samp(klines.close_price) OVER w AS sd,
    (avg(klines.close_price) OVER w + ((2)::numeric * stddev_samp(klines.close_price) OVER w)) AS upper,
    (avg(klines.close_price) OVER w - ((2)::numeric * stddev_samp(klines.close_price) OVER w)) AS lower
   FROM public.klines
  WHERE ((klines.timeframe)::text = '5m'::text)
  WINDOW w AS (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time ROWS BETWEEN 19 PRECEDING AND CURRENT ROW)
  WITH NO DATA;


--
-- Name: mv_donchian20_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_donchian20_5m AS
 SELECT klines.symbol,
    klines.timeframe,
    date_trunc('minute'::text, klines.open_time) AS bucket,
    max(klines.high_price) OVER w AS upper,
    min(klines.low_price) OVER w AS lower
   FROM public.klines
  WHERE ((klines.timeframe)::text = '5m'::text)
  WINDOW w AS (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time ROWS BETWEEN 19 PRECEDING AND CURRENT ROW)
  WITH NO DATA;


--
-- Name: mv_ichimoku_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_ichimoku_5m AS
 WITH base AS (
         SELECT klines.symbol,
            klines.timeframe,
            klines.open_time,
            klines.high_price,
            klines.low_price,
            klines.close_price,
            date_trunc('minute'::text, klines.open_time) AS bucket
           FROM public.klines
          WHERE ((klines.timeframe)::text = '5m'::text)
        ), tk_kj AS (
         SELECT base.symbol,
            base.timeframe,
            base.bucket,
            ((max(base.high_price) OVER w9 + min(base.low_price) OVER w9) / (2)::numeric) AS tenkan,
            ((max(base.high_price) OVER w26 + min(base.low_price) OVER w26) / (2)::numeric) AS kijun,
            base.close_price
           FROM base
          WINDOW w9 AS (PARTITION BY base.symbol, base.timeframe ORDER BY base.bucket ROWS BETWEEN 8 PRECEDING AND CURRENT ROW), w26 AS (PARTITION BY base.symbol, base.timeframe ORDER BY base.bucket ROWS BETWEEN 25 PRECEDING AND CURRENT ROW)
        )
 SELECT tk_kj.symbol,
    tk_kj.timeframe,
    tk_kj.bucket,
    tk_kj.tenkan,
    tk_kj.kijun,
    ((tk_kj.tenkan + tk_kj.kijun) / (2)::numeric) AS senkou_a,
    NULL::text AS senkou_b,
    lag(tk_kj.close_price, 26) OVER (PARTITION BY tk_kj.symbol, tk_kj.timeframe ORDER BY tk_kj.bucket) AS chikou
   FROM tk_kj
  WITH NO DATA;


--
-- Name: mv_macd_1m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_macd_1m AS
 WITH ema_calc AS (
         SELECT klines.symbol,
            klines.timeframe,
            date_trunc('minute'::text, klines.open_time) AS bucket,
            avg(klines.close_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time ROWS BETWEEN 11 PRECEDING AND CURRENT ROW) AS ema12,
            avg(klines.close_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time ROWS BETWEEN 25 PRECEDING AND CURRENT ROW) AS ema26
           FROM public.klines
          WHERE ((klines.timeframe)::text = '1m'::text)
        )
 SELECT ema_calc.symbol,
    ema_calc.timeframe,
    ema_calc.bucket,
    (ema_calc.ema12 - ema_calc.ema26) AS macd,
    avg((ema_calc.ema12 - ema_calc.ema26)) OVER (PARTITION BY ema_calc.symbol, ema_calc.timeframe ORDER BY ema_calc.bucket ROWS BETWEEN 8 PRECEDING AND CURRENT ROW) AS signal,
    ((ema_calc.ema12 - ema_calc.ema26) - avg((ema_calc.ema12 - ema_calc.ema26)) OVER (PARTITION BY ema_calc.symbol, ema_calc.timeframe ORDER BY ema_calc.bucket ROWS BETWEEN 8 PRECEDING AND CURRENT ROW)) AS histogram
   FROM ema_calc
  WHERE ((ema_calc.ema12 IS NOT NULL) AND (ema_calc.ema26 IS NOT NULL))
  WITH NO DATA;


--
-- Name: mv_macd_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_macd_5m AS
 WITH ema_calc AS (
         SELECT klines.symbol,
            klines.timeframe,
            date_trunc('minute'::text, klines.open_time) AS bucket,
            avg(klines.close_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time ROWS BETWEEN 11 PRECEDING AND CURRENT ROW) AS ema12,
            avg(klines.close_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time ROWS BETWEEN 25 PRECEDING AND CURRENT ROW) AS ema26
           FROM public.klines
          WHERE ((klines.timeframe)::text = '5m'::text)
        )
 SELECT ema_calc.symbol,
    ema_calc.timeframe,
    ema_calc.bucket,
    (ema_calc.ema12 - ema_calc.ema26) AS macd,
    avg((ema_calc.ema12 - ema_calc.ema26)) OVER (PARTITION BY ema_calc.symbol, ema_calc.timeframe ORDER BY ema_calc.bucket ROWS BETWEEN 8 PRECEDING AND CURRENT ROW) AS signal,
    ((ema_calc.ema12 - ema_calc.ema26) - avg((ema_calc.ema12 - ema_calc.ema26)) OVER (PARTITION BY ema_calc.symbol, ema_calc.timeframe ORDER BY ema_calc.bucket ROWS BETWEEN 8 PRECEDING AND CURRENT ROW)) AS histogram
   FROM ema_calc
  WHERE ((ema_calc.ema12 IS NOT NULL) AND (ema_calc.ema26 IS NOT NULL))
  WITH NO DATA;


--
-- Name: mv_obv_1m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_obv_1m AS
 WITH base AS (
         SELECT klines.symbol,
            klines.timeframe,
            klines.open_time,
            klines.close_price,
            klines.volume,
            lag(klines.close_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time) AS prev_close
           FROM public.klines
          WHERE (((klines.timeframe)::text = '1m'::text) AND (klines.volume IS NOT NULL))
        )
 SELECT base.symbol,
    base.timeframe,
    date_trunc('minute'::text, base.open_time) AS bucket,
    sum(
        CASE
            WHEN (base.close_price > base.prev_close) THEN base.volume
            WHEN (base.close_price < base.prev_close) THEN (- base.volume)
            ELSE (0)::numeric
        END) OVER (PARTITION BY base.symbol, base.timeframe ORDER BY base.open_time ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS obv
   FROM base
  WHERE (base.prev_close IS NOT NULL)
  WITH NO DATA;


--
-- Name: mv_obv_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_obv_5m AS
 WITH base AS (
         SELECT klines.symbol,
            klines.timeframe,
            klines.open_time,
            klines.close_price,
            klines.volume,
            lag(klines.close_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time) AS prev_close
           FROM public.klines
          WHERE (((klines.timeframe)::text = '5m'::text) AND (klines.volume IS NOT NULL))
        )
 SELECT base.symbol,
    base.timeframe,
    date_trunc('minute'::text, base.open_time) AS bucket,
    sum(
        CASE
            WHEN (base.close_price > base.prev_close) THEN base.volume
            WHEN (base.close_price < base.prev_close) THEN (- base.volume)
            ELSE (0)::numeric
        END) OVER (PARTITION BY base.symbol, base.timeframe ORDER BY base.open_time ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS obv
   FROM base
  WHERE (base.prev_close IS NOT NULL)
  WITH NO DATA;


--
-- Name: mv_rsi14_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_rsi14_5m AS
 WITH base AS (
         SELECT klines.symbol,
            klines.timeframe,
            klines.open_time,
            klines.close_price,
            lag(klines.close_price) OVER (PARTITION BY klines.symbol, klines.timeframe ORDER BY klines.open_time) AS prev_close
           FROM public.klines
          WHERE ((klines.timeframe)::text = '5m'::text)
        ), diffs AS (
         SELECT base.symbol,
            base.timeframe,
            date_trunc('minute'::text, base.open_time) AS bucket,
            GREATEST((base.close_price - base.prev_close), (0)::numeric) AS gain,
            GREATEST((base.prev_close - base.close_price), (0)::numeric) AS loss
           FROM base
          WHERE (base.prev_close IS NOT NULL)
        ), smoothed AS (
         SELECT diffs.symbol,
            diffs.timeframe,
            diffs.bucket,
            avg(diffs.gain) OVER (PARTITION BY diffs.symbol, diffs.timeframe ORDER BY diffs.bucket ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS avg_gain,
            avg(diffs.loss) OVER (PARTITION BY diffs.symbol, diffs.timeframe ORDER BY diffs.bucket ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS avg_loss
           FROM diffs
        )
 SELECT smoothed.symbol,
    smoothed.timeframe,
    smoothed.bucket,
    ((100)::numeric - ((100)::numeric / ((1)::numeric + public.safe_div(smoothed.avg_gain, NULLIF(smoothed.avg_loss, (0)::numeric))))) AS rsi
   FROM smoothed
  WHERE ((smoothed.avg_gain IS NOT NULL) AND (smoothed.avg_loss IS NOT NULL))
  WITH NO DATA;


--
-- Name: mv_stochrsi_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_stochrsi_5m AS
 WITH rsi_with_minmax AS (
         SELECT mv_rsi14_5m.symbol,
            mv_rsi14_5m.timeframe,
            mv_rsi14_5m.bucket,
            mv_rsi14_5m.rsi,
            min(mv_rsi14_5m.rsi) OVER w14 AS min_rsi_14,
            max(mv_rsi14_5m.rsi) OVER w14 AS max_rsi_14
           FROM public.mv_rsi14_5m
          WHERE (mv_rsi14_5m.rsi IS NOT NULL)
          WINDOW w14 AS (PARTITION BY mv_rsi14_5m.symbol, mv_rsi14_5m.timeframe ORDER BY mv_rsi14_5m.bucket ROWS BETWEEN 13 PRECEDING AND CURRENT ROW)
        ), stoch_rsi_calc AS (
         SELECT rsi_with_minmax.symbol,
            rsi_with_minmax.timeframe,
            rsi_with_minmax.bucket,
            rsi_with_minmax.rsi,
            ((rsi_with_minmax.rsi - rsi_with_minmax.min_rsi_14) / NULLIF((rsi_with_minmax.max_rsi_14 - rsi_with_minmax.min_rsi_14), (0)::numeric)) AS stoch_rsi
           FROM rsi_with_minmax
          WHERE ((rsi_with_minmax.min_rsi_14 IS NOT NULL) AND (rsi_with_minmax.max_rsi_14 IS NOT NULL))
        )
 SELECT stoch_rsi_calc.symbol,
    stoch_rsi_calc.timeframe,
    stoch_rsi_calc.bucket,
    stoch_rsi_calc.stoch_rsi,
    avg(stoch_rsi_calc.stoch_rsi) OVER (PARTITION BY stoch_rsi_calc.symbol, stoch_rsi_calc.timeframe ORDER BY stoch_rsi_calc.bucket ROWS BETWEEN 2 PRECEDING AND CURRENT ROW) AS stoch_rsi_d
   FROM stoch_rsi_calc
  WITH NO DATA;


--
-- Name: mv_vwap_5m; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_vwap_5m AS
 SELECT klines.symbol,
    klines.timeframe,
    date_trunc('minute'::text, klines.open_time) AS bucket,
    public.safe_div(sum(((((klines.high_price + klines.low_price) + klines.close_price) / 3.0) * klines.volume)), NULLIF(sum(klines.volume), (0)::numeric)) AS vwap
   FROM public.klines
  WHERE (((klines.timeframe)::text = '5m'::text) AND (klines.volume IS NOT NULL))
  GROUP BY klines.symbol, klines.timeframe, (date_trunc('minute'::text, klines.open_time))
  WITH NO DATA;


--
-- Name: order_plan; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.order_plan (
    id bigint NOT NULL,
    symbol character varying(50) NOT NULL,
    plan_time timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    side character varying(10) NOT NULL,
    risk_json jsonb NOT NULL,
    context_json jsonb NOT NULL,
    exec_json jsonb NOT NULL,
    status character varying(20) DEFAULT 'PLANNED'::text NOT NULL
);


--
-- Name: COLUMN order_plan.plan_time; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.order_plan.plan_time IS '(DC2Type:datetimetz_immutable)';


--
-- Name: order_plan_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.order_plan_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: order_plan_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.order_plan_id_seq OWNED BY public.order_plan.id;


--
-- Name: signals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.signals (
    id bigint NOT NULL,
    symbol character varying(50) NOT NULL,
    timeframe character varying(10) NOT NULL,
    kline_time timestamp(0) with time zone NOT NULL,
    side character varying(10) NOT NULL,
    score double precision,
    meta jsonb DEFAULT '{}'::jsonb NOT NULL,
    inserted_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: COLUMN signals.kline_time; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.signals.kline_time IS '(DC2Type:datetimetz_immutable)';


--
-- Name: COLUMN signals.inserted_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.signals.inserted_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: COLUMN signals.updated_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.signals.updated_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: signals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.signals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: signals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.signals_id_seq OWNED BY public.signals.id;


--
-- Name: validation_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.validation_cache (
    cache_key character varying(255) NOT NULL,
    payload jsonb NOT NULL,
    expires_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: COLUMN validation_cache.expires_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.validation_cache.expires_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: COLUMN validation_cache.updated_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.validation_cache.updated_at IS '(DC2Type:datetimetz_immutable)';


--
-- Name: blacklisted_contract blacklisted_contract_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blacklisted_contract
    ADD CONSTRAINT blacklisted_contract_pkey PRIMARY KEY (id);


--
-- Name: contracts contracts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_pkey PRIMARY KEY (id);


--
-- Name: doctrine_migration_versions doctrine_migration_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doctrine_migration_versions
    ADD CONSTRAINT doctrine_migration_versions_pkey PRIMARY KEY (version);


--
-- Name: indicator_snapshots indicator_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.indicator_snapshots
    ADD CONSTRAINT indicator_snapshots_pkey PRIMARY KEY (id);


--
-- Name: klines klines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.klines
    ADD CONSTRAINT klines_pkey PRIMARY KEY (id);


--
-- Name: mtf_audit mtf_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mtf_audit
    ADD CONSTRAINT mtf_audit_pkey PRIMARY KEY (id);


--
-- Name: mtf_lock mtf_lock_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mtf_lock
    ADD CONSTRAINT mtf_lock_pkey PRIMARY KEY (lock_key);


--
-- Name: mtf_state mtf_state_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mtf_state
    ADD CONSTRAINT mtf_state_pkey PRIMARY KEY (id);


--
-- Name: mtf_switch mtf_switch_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mtf_switch
    ADD CONSTRAINT mtf_switch_pkey PRIMARY KEY (id);


--
-- Name: order_plan order_plan_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.order_plan
    ADD CONSTRAINT order_plan_pkey PRIMARY KEY (id);


--
-- Name: signals signals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signals
    ADD CONSTRAINT signals_pkey PRIMARY KEY (id);


--
-- Name: mtf_state ux_mtf_state_symbol; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mtf_state
    ADD CONSTRAINT ux_mtf_state_symbol UNIQUE (symbol);


--
-- Name: mtf_switch ux_mtf_switch_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mtf_switch
    ADD CONSTRAINT ux_mtf_switch_key UNIQUE (switch_key);


--
-- Name: validation_cache validation_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.validation_cache
    ADD CONSTRAINT validation_cache_pkey PRIMARY KEY (cache_key);


--
-- Name: idx_ind_snap_kline_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ind_snap_kline_time ON public.indicator_snapshots USING btree (kline_time);


--
-- Name: idx_ind_snap_symbol_tf; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ind_snap_symbol_tf ON public.indicator_snapshots USING btree (symbol, timeframe);


--
-- Name: idx_klines_open_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_klines_open_time ON public.klines USING btree (open_time);


--
-- Name: idx_klines_symbol_tf; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_klines_symbol_tf ON public.klines USING btree (symbol, timeframe);


--
-- Name: idx_mtf_audit_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mtf_audit_created_at ON public.mtf_audit USING btree (created_at);


--
-- Name: idx_mtf_audit_run_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mtf_audit_run_id ON public.mtf_audit USING btree (run_id);


--
-- Name: idx_mtf_audit_symbol; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mtf_audit_symbol ON public.mtf_audit USING btree (symbol);


--
-- Name: idx_mv_adx14_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_adx14_5m_symbol_bucket ON public.mv_adx14_5m USING btree (symbol, bucket);


--
-- Name: idx_mv_boll20_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_boll20_5m_symbol_bucket ON public.mv_boll20_5m USING btree (symbol, bucket);


--
-- Name: idx_mv_donchian20_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_donchian20_5m_symbol_bucket ON public.mv_donchian20_5m USING btree (symbol, bucket);


--
-- Name: idx_mv_ichimoku_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_ichimoku_5m_symbol_bucket ON public.mv_ichimoku_5m USING btree (symbol, bucket);


--
-- Name: idx_mv_macd_1m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_macd_1m_symbol_bucket ON public.mv_macd_1m USING btree (symbol, bucket);


--
-- Name: idx_mv_macd_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_macd_5m_symbol_bucket ON public.mv_macd_5m USING btree (symbol, bucket);


--
-- Name: idx_mv_obv_1m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_obv_1m_symbol_bucket ON public.mv_obv_1m USING btree (symbol, bucket);


--
-- Name: idx_mv_obv_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_obv_5m_symbol_bucket ON public.mv_obv_5m USING btree (symbol, bucket);


--
-- Name: idx_mv_rsi14_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_rsi14_5m_symbol_bucket ON public.mv_rsi14_5m USING btree (symbol, bucket);


--
-- Name: idx_mv_stochrsi_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_stochrsi_5m_symbol_bucket ON public.mv_stochrsi_5m USING btree (symbol, bucket);


--
-- Name: idx_mv_vwap_5m_symbol_bucket; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mv_vwap_5m_symbol_bucket ON public.mv_vwap_5m USING btree (symbol, bucket);


--
-- Name: idx_order_plan_plan_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_order_plan_plan_time ON public.order_plan USING btree (plan_time);


--
-- Name: idx_order_plan_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_order_plan_status ON public.order_plan USING btree (status);


--
-- Name: idx_order_plan_symbol; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_order_plan_symbol ON public.order_plan USING btree (symbol);


--
-- Name: idx_signals_kline_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_signals_kline_time ON public.signals USING btree (kline_time);


--
-- Name: idx_signals_side; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_signals_side ON public.signals USING btree (side);


--
-- Name: idx_signals_symbol_tf; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_signals_symbol_tf ON public.signals USING btree (symbol, timeframe);


--
-- Name: idx_validation_cache_expires; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_validation_cache_expires ON public.validation_cache USING btree (expires_at);


--
-- Name: ux_contracts_symbol; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_contracts_symbol ON public.contracts USING btree (symbol);


--
-- Name: ux_ind_snap_symbol_tf_time; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_ind_snap_symbol_tf_time ON public.indicator_snapshots USING btree (symbol, timeframe, kline_time);


--
-- Name: ux_klines_symbol_tf_open; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_klines_symbol_tf_open ON public.klines USING btree (symbol, timeframe, open_time);


--
-- Name: ux_signals_symbol_tf_time; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_signals_symbol_tf_time ON public.signals USING btree (symbol, timeframe, kline_time);


--
-- PostgreSQL database dump complete
--

\unrestrict 7J8GeQh8zmZ6yIactUioHhtfBytzI1WAJ5eh78MjTqJaA7qtAJhhaTTEEgMPIsp
