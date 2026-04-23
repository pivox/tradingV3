# PNL-03 — Réduire la fenêtre d'attente des ordres limit de 120s à 45s

**Epic :** Amélioration de la courbe P&L
**Priorité :** Haute
**Effort estimé :** S
**Dépendances :** aucune

---

## Contexte (PO)

Un ordre limit peut rester ouvert 120 secondes avant d'être annulé. Sur un
timeframe 1m ou 5m, le signal qui a déclenché l'entrée est périmé au bout de
~30-45s. Un fill à T0+90s se fait sur un prix valide à T0, avec un TP/SL calculé
au même T0 — mais la microstructure du marché a changé. Ces entrées tardives
dégradent mécaniquement le ratio R réel de la position.

De plus, le dead-man exchange est désactivé (`cancel_after_timeout: 0`) : en cas
de crash du consumer Messenger, l'ordre reste ouvert indéfiniment côté Bitmart.

---

## Analyse technique (Architecte)

**Localisation :** `ExecutionBox.php` L.176-181

```php
// Actuel — hardcodé, dead-man désactivé
$watchWindowSec = 120;
$orderPayload['cancel_after_timeout'] = 0;
```

**Corrections :**

1. Rendre `watchWindowSec` configurable par profil dans `trade_entry.*.yaml`
   sous la clé `entry.limit_order_ttl_sec`
2. Activer le dead-man exchange en parallèle :
   `cancel_after_timeout = limit_order_ttl_sec + 30` (safety net si consumer tombe)

**Valeurs cibles par profil :**

| Profil | TF exécution | `limit_order_ttl_sec` | `entry_zone.ttl_sec` actuel | Cohérence |
|--------|-------------|----------------------|----------------------------|-----------|
| scalper_micro | 1m | 30s | 180s | ✓ |
| scalper | 5m | 45s | 150s | ✓ |
| regular | 15m | 90s | 240s | ✓ |

**Invariant :** `limit_order_ttl_sec` doit toujours être ≤ `entry_zone.ttl_sec`.

**Fichiers concernés :**

| Fichier | Action |
|---------|--------|
| `src/TradeEntry/Execution/ExecutionBox.php` | L.176-203 — lecture depuis config |
| `config/app/trade_entry.scalper_micro.yaml` | Ajout `entry.limit_order_ttl_sec: 30` |
| `config/app/trade_entry.scalper.yaml` | Ajout `entry.limit_order_ttl_sec: 45` |
| `config/app/trade_entry.regular.yaml` | Ajout `entry.limit_order_ttl_sec: 90` |

---

## Critères d'acceptance (PO)

- [ ] La durée d'attente d'un limit order respecte `entry.limit_order_ttl_sec`
      du profil actif (et non plus la valeur hardcodée 120s)
- [ ] `cancel_after_timeout` envoyé à Bitmart = `limit_order_ttl_sec + 30`
      (jamais 0)
- [ ] Un ordre non rempli après le TTL est annulé et loggué avec
      `reason: limit_order_ttl_expired`
- [ ] Le log `order_journey.trade_entry.skipped` contient le champ
      `ttl_sec_configured` pour auditabilité
- [ ] Test d'intégration simulant un non-fill → vérification de l'annulation
      dans les délais configurés
