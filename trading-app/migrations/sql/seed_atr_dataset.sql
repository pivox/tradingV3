-- Seed de données riche pour tester l'ATR (PostgreSQL)
-- Symboles ciblés: ATRTEST (1m et 5m)

BEGIN;

-- Contrat pour fournir un tick_size (utilisé par le plancher ATR = tick*2)
INSERT INTO contracts(symbol, tick_size, min_size, inserted_at, updated_at)
VALUES ('ATRTEST', 0.1, 0.001, now(), now())
ON CONFLICT (symbol) DO UPDATE SET
  tick_size = EXCLUDED.tick_size,
  min_size = EXCLUDED.min_size,
  updated_at = now();

-- Purge préalable
DELETE FROM klines WHERE symbol = 'ATRTEST' AND timeframe IN ('1m','5m');

-- Paramètres
-- Fenêtre: 180 bougies 1m (~3h)
WITH params AS (
  SELECT
    'ATRTEST'::text AS symbol,
    now() - interval '180 minutes' AS t0,
    30000.0::numeric AS p0
),
g AS (
  -- i = 0..179
  SELECT
    p.symbol,
    generate_series(0,179) AS i,
    p.t0 + (generate_series(0,179)) * interval '1 minute' AS ts,
    p.p0 AS base_price
  FROM params p
),
series AS (
  -- Close synthétique: sinus + trend local + bruit
  SELECT
    symbol,
    ts,
    base_price
      + 100.0 * sin((i/10.0))
      + CASE WHEN i BETWEEN 60 AND 90 THEN (i-60) * 8.0 ELSE 0 END -- trend haussier local (~240 pts)
      + (random()*10.0 - 5.0) AS close_raw,
    i
  FROM g
),
ohlc0 AS (
  -- Construire open/high/low de façon cohérente
  SELECT
    symbol,
    ts AS open_time,
    (close_raw + (random()*6.0 - 3.0))::numeric(24,12) AS open_price,
    close_raw::numeric(24,12) AS close_price,
    i
  FROM series
),
ohlc1 AS (
  SELECT
    symbol,
    open_time,
    open_price,
    close_price,
    GREATEST(open_price, close_price) + (random()*12.0)::numeric(24,12) AS high_price,
    LEAST(open_price, close_price) - (random()*12.0)::numeric(24,12) AS low_price,
    i
  FROM ohlc0
),
patterns AS (
  -- Injecter des segments particuliers pour tester ATR
  SELECT
    symbol,
    open_time,
    -- Segment à-plat (i 120..125): bougies plates quasi nulles
    CASE WHEN i BETWEEN 120 AND 125 THEN close_price ELSE open_price END AS open_price,
    CASE WHEN i BETWEEN 120 AND 125 THEN close_price ELSE close_price END AS close_price,
    CASE WHEN i BETWEEN 120 AND 125 THEN close_price ELSE high_price END AS high_price,
    CASE WHEN i BETWEEN 120 AND 125 THEN close_price ELSE low_price END AS low_price,
    i
  FROM ohlc1
),
outlier AS (
  -- Outlier majeur à i=90 pour tester le cap 3x médiane (1m)
  SELECT
    symbol,
    open_time,
    open_price,
    close_price,
    CASE WHEN i = 90 THEN high_price + 800.0 ELSE high_price END AS high_price,
    CASE WHEN i = 90 THEN low_price - 800.0 ELSE low_price END AS low_price,
    i
  FROM patterns
),
volumes AS (
  -- Volumes: plus élevés pendant le trend (60..90), plus faibles sur à-plat (120..125)
  SELECT
    symbol,
    open_time,
    open_price,
    high_price,
    low_price,
    close_price,
    (CASE
       WHEN i BETWEEN 60 AND 90 THEN 150.0 + random()*50.0
       WHEN i BETWEEN 120 AND 125 THEN 5.0 + random()*2.0
       ELSE 50.0 + random()*20.0
     END)::numeric(28,12) AS volume,
    i
  FROM outlier
)
INSERT INTO klines(symbol, timeframe, open_time, open_price, high_price, low_price, close_price, volume, source)
SELECT
  symbol,
  '1m'::timeframe,
  open_time,
  open_price,
  GREATEST(high_price, LEAST(open_price, close_price)), -- sécurité
  LEAST(low_price, GREATEST(open_price, close_price)),  -- sécurité
  close_price,
  volume,
  'REST'
FROM volumes
ORDER BY open_time;

-- Générer 5m indépendamment (36 bougies ~ 3h)
WITH params AS (
  SELECT 'ATRTEST'::text AS symbol, now() - interval '180 minutes' AS t0, 30000.0::numeric AS p0
),
g AS (
  SELECT p.symbol, generate_series(0,35) AS j, p.t0 + (generate_series(0,35)) * interval '5 minute' AS ts, p.p0 AS base_price FROM params p
),
series AS (
  SELECT symbol, ts, base_price + 200.0 * sin((j/4.0)) + (CASE WHEN j BETWEEN 12 AND 18 THEN (j-12)*20.0 ELSE 0 END) + (random()*15.0 - 7.5) AS close_raw, j FROM g
),
ohlc AS (
  SELECT
    symbol,
    ts AS open_time,
    (close_raw + (random()*10.0 - 5.0))::numeric(24,12) AS open_price,
    (close_raw + (random()*10.0 - 5.0))::numeric(24,12) AS close_price,
    j
  FROM series
)
INSERT INTO klines(symbol, timeframe, open_time, open_price, high_price, low_price, close_price, volume, source)
SELECT
  symbol,
  '5m'::timeframe,
  open_time,
  open_price,
  GREATEST(open_price, close_price) + (random()*25.0)::numeric(24,12) AS high_price,
  LEAST(open_price, close_price) - (random()*25.0)::numeric(24,12) AS low_price,
  close_price,
  (80.0 + random()*40.0)::numeric(28,12) AS volume,
  'REST'
FROM ohlc
ORDER BY open_time;

COMMIT;

-- Utilisation:
-- psql "$DATABASE_URL" -f trading-app/migrations/sql/seed_atr_dataset.sql

