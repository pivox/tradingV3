# Agenda MTF – 24 novembre 2025

## Commandes récentes
- `docker-compose exec trading-app-php php bin/console mtf:run --dry-run=0 --workers=8`
- `curl -X POST http://localhost:8082/api/mtf/run -H 'Content-Type: application/json' -d '{"dry_run":false,"force_run":false,"force_timeframe_check":false,"workers":8}'`
- `docker-compose logs -f trading-app-messenger-trading`

## Points d'attention
- Deux workers doivent être actifs : `trading-app-messenger-order-timeout` (transport `order_timeout`) et `trading-app-messenger-trading` (transport `mtf_decision`).
- Les décisions MTF sont envoyées via Messenger, surveiller les logs `[MTF Messenger]` pour confirmer les ordres.
- Les messages `App\TradeEntry\Message\LimitFillWatchMessage` restent sur `order_timeout` pour éviter les warnings côté `mtf_decision`.
- Après chaque modification de config Messenger, relancer les deux containers pour appliquer les nouvelles files Redis.
