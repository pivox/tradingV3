# README – How To Tester la persistance des zone skips & l’autotune

## 1. Pré‑requis
- Base PostgreSQL accessible via `DATABASE_URL`.
- Migration exécutée (table `trade_zone_events`).
- Pipeline capable de produire des `skipped_out_of_zone`.

## 2. Exécuter les migrations
```bash
docker-compose exec trading-app-php php bin/console doctrine:migrations:migrate
```
Confirme que la table `trade_zone_events` existe :
```bash
docker-compose exec trading-app-db psql -U postgres -d trading_app -c \
  "\d+ trade_zone_events"
```

## 3. Générer quelques évènements
Deux options :
1. **Run pipeline réel** : laisser tourner la stack et attendre des skips naturels.
2. **Insertion manuelle** (exemple) :
```sql
INSERT INTO trade_zone_events(
    symbol, happened_at, reason, decision_key,
    zone_min, zone_max, candidate_price,
    zone_dev_pct, zone_max_dev_pct,
    config_profile, timeframe, category
) VALUES (
    'GLMUSDT', now(), 'skipped_out_of_zone', 'test-glm',
    0.22, 0.24, 0.26,
    0.0282, 0.0160,
    'regular', '1m', 'moderate_gap'
);
```

## 4. Vérifier la persistance
```bash
docker-compose exec trading-app-db psql -U postgres -d trading_app -c \
  "SELECT symbol, zone_dev_pct, zone_max_dev_pct, category, happened_at FROM trade_zone_events ORDER BY happened_at DESC LIMIT 5;"
```

## 5. Lancer l’autotune (dry‑run)
```bash
docker-compose exec trading-app-php php bin/console trade-entry:zone:auto-adjust \
  --mode=regular --mode=scalper \
  --hours=24 \
  --threshold=0.05 \
  --min-events=2 \
  --dry-run
```
La commande affiche le tableau des symboles impactés sans toucher aux overrides.

## 6. Appliquer les overrides
Supprimer `--dry-run` pour persister :
```bash
docker-compose exec trading-app-php php bin/console trade-entry:zone:auto-adjust \
  --mode=regular --mode=scalper \
  --hours=24 \
  --threshold=0.05 \
  --min-events=2
```
Les valeurs sont stockées dans `var/config/zone_deviation_overrides.json`.

## 7. Vérifications runtime
1. Afficher le JSON :
   ```bash
   cat var/config/zone_deviation_overrides.json | jq .
   ```
2. Déclencher un TradeEntry (ou un test intégration) sur un symbole overridé.
3. Vérifier dans les logs `positions` que `zone_max_dev_pct` reflète la valeur override.

## 8. Points de contrôle
- **Pas d’évènements** → la commande affiche un warning et ne modifie rien.
- **Override ≈ valeur par défaut** → l’autotune supprime l’entrée (action `remove`).
- **Rollback** → supprimer la clé dans le JSON ou `overrideStore->removeOverride()` via commande.

## 9. Cron suggéré
```bash
0 */3 * * * docker-compose -f ... exec trading-app-php \
  php bin/console trade-entry:zone:auto-adjust --mode=regular --mode=scalper --hours=24 --threshold=0.05 --min-events=3 >> /var/log/zone_autotune.log 2>&1
```
