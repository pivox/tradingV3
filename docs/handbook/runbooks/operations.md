# Runbook Exploitation

## Lancer un run MTF

CLI:

```bash
docker-compose exec trading-app-php php bin/console mtf:run --dry-run=0 --workers=8
```

HTTP:

```bash
curl -X POST http://localhost:8082/api/mtf/run \
  -H 'Content-Type: application/json' \
  -d '{"dry_run":false,"force_run":false,"force_timeframe_check":false,"workers":8,"mtf_profile":"scalper_micro"}'
```

## Workers indispensables

| Worker | Transport | Role |
| --- | --- | --- |
| `trading-app-messenger-trading` | `mtf_decision` | Decisions MTF vers TradeEntry. |
| `trading-app-messenger-projection` | `mtf_projection` | Projection resultats et snapshots indicateurs. |
| `trading-app-messenger-order-timeout` | `order_timeout` | Watchers d'ordres, annulations, out-of-zone. |

Apres modification de `messenger.yaml`, relancer les containers workers concernes.

## Logs a surveiller

| Recherche | Usage |
| --- | --- |
| `rg "/api/mtf/run" trading-app/var/log/dev-YYYY-MM-DD.log` | Appels HTTP. |
| `rg "reason=" trading-app/var/log/mtf-YYYY-MM-DD.log` | Raisons MTF agregeables. |
| `rg "[MTF Messenger]" trading-app/var/log/*` | Dispatch et consommation decisions. |
| `rg "entry_zone.rejected_by_deviation" trading-app/var/log/*` | Rejets zone. |
| `rg "trader_atr returned invalid value" trading-app/var/log/*` | Fallback ATR. |

## Rapports utiles

```bash
trading-app/scripts/mtf_report.sh 2025-11-26
trading-app/scripts/mtf_condition_report.py \
  --log trading-app/var/log/mtf-YYYY-MM-DD.log \
  --since "YYYY-MM-DD HH:MM" \
  --csv-prefix /tmp/mtf-summary
```

Le rapport condition genere:

- `*_failrule.csv`;
- `*_failsymbol.csv`;
- `*_passsymbol.csv`.

## Temporal

Commandes principales:

```bash
cd cron_symfony_mtf_workers
python scripts/manage_exchange_profile_schedule.py create --exchange=bitmart --profile=scalper_micro --dry-run=true
python scripts/manage_exchange_profile_schedule.py status --schedule-id=cron-mtf-bitmart-scalper-micro-1m
python scripts/manage_exchange_profile_schedule.py pause --schedule-id=cron-mtf-bitmart-scalper-micro-1m
python scripts/manage_exchange_profile_schedule.py resume --schedule-id=cron-mtf-bitmart-scalper-micro-1m
```

Avant `dry_run=false`, executer:

```bash
docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check bitmart perpetual
```

## Verification post-deploiement

1. `GET /health` repond.
2. Les migrations sont appliquees.
3. Les workers `mtf_projection`, `mtf_decision` et `order_timeout` consomment.
4. Un run dry-run retourne un `run_id`.
5. Les logs MTF contiennent les timings et les decisions.
6. Les snapshots indicateurs sont projetes sans doublons massifs.
7. Les ordres reels restent en `dry_run=false` uniquement sur profil et exchange valides.
