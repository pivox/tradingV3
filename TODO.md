# TODO

- Configurer un contact point Grafana pour l'alerte "Positions - New Entry Detected" (runs in Alerting UI).
- Lancer `php bin/console doctrine:migrations:migrate` dans `trading-app` pour créer `contract_cooldown` et `order_lifecycle`.
- Reconfigurer `bitmart-ws-forwarder` pour poster les évènements vers `/api/orders/events` du trading-app.
- Vérifier les logs applicatifs afin de confirmer que l'ATR 1m et les protections (SL/TP) sont bien enregistrés après chaque remplissage.



# ce qui est fait 
migration 

# codex conversatio history 
- position codex resume 0199eef0-132c-7903-83cd-56c3162970b2