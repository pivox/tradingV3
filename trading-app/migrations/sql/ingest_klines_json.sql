CREATE OR REPLACE FUNCTION ingest_klines_json(p_payload jsonb)
RETURNS void
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
