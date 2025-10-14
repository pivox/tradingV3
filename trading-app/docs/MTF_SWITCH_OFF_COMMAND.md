# Commande MTF Switch Off

## Description

La commande `mtf:switch-off` permet de désactiver temporairement une liste de symboles MTF pour une durée spécifiée. Cette fonctionnalité est particulièrement utile pour gérer les symboles "TOO_RECENT" qui doivent être temporairement exclus du trading.

## Utilisation

### Commande de base

```bash
php bin/console mtf:switch-off --symbols="SYMBOL1,SYMBOL2,SYMBOL3" --duration="DURATION" --reason="RAISON"
```

### Options disponibles

- `--symbols` ou `-s` : Liste de symboles séparés par des virgules (obligatoire)
- `--duration` ou `-d` : Durée de désactivation (optionnel, défaut: "38640m")
- `--reason` ou `-r` : Raison de la désactivation (optionnel, défaut: "TOO_RECENT")
- `--dry-run` : Mode simulation - ne pas appliquer les changements

### Formats de durée supportés

- `4h` : 4 heures
- `1d` : 1 jour
- `38640m` : 38640 minutes (environ 26 jours)
- `1w` : 1 semaine
- `2d 3h` : 2 jours et 3 heures

## Exemples d'utilisation

### Désactiver quelques symboles pour 4 heures

```bash
php bin/console mtf:switch-off --symbols="BTCUSDT,ETHUSDT,ADAUSDT" --duration="4h" --reason="Maintenance"
```

### Désactiver des symboles TOO_RECENT pour 38640 minutes

```bash
php bin/console mtf:switch-off --symbols="BTCUSDT,ETHUSDT" --duration="38640m" --reason="TOO_RECENT"
```

### Mode simulation (dry-run)

```bash
php bin/console mtf:switch-off --symbols="BTCUSDT,ETHUSDT" --duration="1d" --dry-run
```

## Script automatisé

Un script shell est disponible pour faciliter la désactivation de la liste complète des symboles TOO_RECENT :

```bash
./scripts/switch_off_too_recent_symbols.sh [duration] [dry-run]
```

### Exemples d'utilisation du script

```bash
# Désactiver tous les symboles TOO_RECENT pour 38640 minutes (défaut)
./scripts/switch_off_too_recent_symbols.sh

# Désactiver pour 1 jour
./scripts/switch_off_too_recent_symbols.sh "1d"

# Mode simulation
./scripts/switch_off_too_recent_symbols.sh "38640m" "dry-run"
```

## Fonctionnement technique

### Conversion de durée

La commande convertit automatiquement les formats de durée non supportés par PHP :
- `38640m` → `644h` (38640 minutes = 644 heures)

### Base de données

Les switches sont stockés dans la table `mtf_switch` avec :
- `switch_key` : Format `SYMBOL:SYMBOLNAME`
- `is_on` : `false` pour désactiver
- `expires_at` : Date d'expiration automatique
- `description` : Description avec raison et timestamp

### Exemple de switch créé

```sql
INSERT INTO mtf_switch (switch_key, is_on, description, expires_at, created_at, updated_at) 
VALUES (
    'SYMBOL:LIGHTUSDT',
    false,
    'Symbole désactivé temporairement pour 38640m - 2025-01-15 10:30:00',
    '2025-02-10 10:30:00',
    '2025-01-15 10:30:00',
    '2025-01-15 10:30:00'
);
```

## Vérification des switches

### Lister les switches actifs

```bash
php bin/console doctrine:query:sql "SELECT * FROM mtf_switch WHERE is_on = false AND expires_at > NOW()"
```

### Nettoyer les switches expirés

```bash
php bin/console doctrine:query:sql "UPDATE mtf_switch SET is_on = true, expires_at = NULL, description = NULL WHERE expires_at <= NOW()"
```

## Sécurité

- La commande demande confirmation avant d'appliquer les changements (sauf avec `--no-interaction`)
- Le mode `--dry-run` permet de tester sans appliquer les changements
- Les erreurs sont affichées avec le détail pour chaque symbole

## Intégration avec le système MTF

Les switches créés par cette commande sont automatiquement pris en compte par :
- `MtfSwitchRepository::isSymbolSwitchOn()`
- `MtfSwitchRepository::canProcessSymbol()`
- Tous les services MTF qui vérifient les permissions de trading

Les symboles désactivés ne seront plus traités par le système MTF jusqu'à l'expiration du switch.
