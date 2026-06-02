# Runbook Investigation

## Export symbole

Pour investiguer un symbole autour d'une date UTC:

```bash
docker-compose exec trading-app-php php bin/console app:export-symbol-data LINKUSDT "2025-11-30 13:02" --show-sql --show-logs
```

La commande exporte:

- `indicator_snapshots`;
- `mtf_*`;
- `order_intent*`;
- `futures_order*`;
- `trade_lifecycle_event`;
- `trade_zone_events`;
- logs positions, mtf, signals, bitmart, provider, indicators et dev.

Le fichier est ecrit dans `investigation/symbol_data_<SYMBOL>_<DATE>_<HEURE>.json`.

## Position fermee ou ordre manquant

1. Identifier `symbol`, `decision_key`, `run_id` et heure UTC.
2. Exporter les donnees avec `app:export-symbol-data`.
3. Rechercher la decision MTF dans `mtf_audit`.
4. Verifier si `TradingDecisionHandler` a publie un message `MtfTradingDecisionMessage`.
5. Verifier `order_intent` et `trade_lifecycle_event`.
6. Controler les logs provider pour rejet exchange, rate-limit ou protection invalide.
7. Verifier les watchers `order_timeout` si ordre limite non fill.

## Rejet entry zone

SQL utile:

```sql
SELECT
  symbol,
  happened_at,
  timeframe,
  config_profile,
  reason,
  entry_zone_width_pct,
  zone_dev_pct,
  zone_max_dev_pct,
  atr_pct,
  volume_ratio,
  spread_bps
FROM trade_zone_events
WHERE reason = 'skipped_out_of_zone'
ORDER BY happened_at DESC
LIMIT 50;
```

Jointure lifecycle:

```sql
SELECT
  tze.symbol,
  tze.reason,
  tze.entry_zone_width_pct,
  tze.zone_dev_pct,
  tle.event_type,
  tle.reason_code,
  tle.happened_at
FROM trade_zone_events tze
LEFT JOIN trade_lifecycle_event tle
  ON (tle.extra->>'decision_key') = tze.decision_key
WHERE tze.happened_at >= now() - interval '7 days'
ORDER BY tze.happened_at DESC;
```

## Diagnostic indicateurs

```bash
docker-compose exec trading-app-php php bin/console app:indicator:conditions:list
docker-compose exec trading-app-php php bin/console app:indicator:conditions:diagnose BTCUSDT 5m
docker-compose exec trading-app-php php bin/console indicator:snapshot list --symbol=BTCUSDT --timeframe=5m
```

Surveiller les warnings ATR si `php-trader` retourne une valeur invalide et force le fallback PHP.

## Decision MTF bloquee

Verifier dans cet ordre:

1. contrat actif dans `contracts`;
2. absence de blacklist/cooldown;
3. pas de position ouverte ni ordre pending;
4. pas de lock cross-profile;
5. klines suffisantes et fraiches;
6. snapshots ou indicateurs disponibles;
7. conditions contexte puis execution;
8. selection timeframe;
9. dispatch Messenger;
10. consommation du handler de decision.
