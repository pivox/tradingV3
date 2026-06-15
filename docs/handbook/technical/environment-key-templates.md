# Templates de clés d'environnement

## Objectif

Le dossier `config_file/` contient les noms de clés attendues pour les environnements de développement et de production.

```text
config_file/dev.env
config_file/prod.env
```

Ces fichiers ne doivent pas contenir de vraies valeurs. Ils servent de référence pour savoir quelles clés doivent être renseignées dans l'environnement réel.

## Règles

- Ne jamais committer de secrets.
- Ne jamais committer de private keys.
- Ne jamais committer de mots de passe ou tokens.
- Garder uniquement les noms de clés sous forme `KEY=`.
- Les vraies valeurs doivent rester dans `.env.local`, le CI/CD, le secret manager ou l'environnement de la machine.

## Pourquoi ce dossier existe

Le futur `EffectiveTradingConfigResolver` devra pouvoir vérifier que les clés nécessaires sont présentes selon :

```text
base
+ mode
+ exchange
+ mode_exchange
+ env
= EffectiveTradingConfig
```

Le dossier `config_file/` sert donc de source de référence pour :

- comparer dev/prod ;
- détecter les clés manquantes ;
- préparer les gateways OKX, Hyperliquid et Fake/Paper ;
- documenter les clés legacy Bitmart tant que le runtime existant en dépend ;
- éviter que les clés attendues soient dispersées dans plusieurs documents.

## Gateways cible

Les clés des gateways cible sont dans les fichiers :

```text
OKX_*
HYPERLIQUID_*
FAKE_EXCHANGE_*
```

Bitmart reste listé uniquement comme legacy tant que le runtime existant en dépend encore :

```text
BITMART_*
```

## Utilisation recommandée

Pour un environnement local :

```bash
cp config_file/dev.env trading-app/.env.local
```

Puis remplir localement les valeurs nécessaires.

Pour la production, `config_file/prod.env` doit servir de checklist de clés à créer dans le secret manager ou dans la configuration d'environnement du déploiement.
