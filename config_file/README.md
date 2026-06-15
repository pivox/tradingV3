# config_file

Ce dossier contient les gabarits de clés d'environnement attendues par TradingV3.

## Fichiers

```text
dev.env
prod.env
```

## Regles

- Ces fichiers doivent contenir les noms des clés attendues, pas les valeurs réelles.
- Ne jamais committer de secrets, tokens, private keys, mots de passe ou URLs sensibles.
- Les vraies valeurs doivent rester dans l'environnement local, le secret manager, le CI/CD ou les fichiers locaux ignores par Git.
- `Bitmart` reste liste uniquement comme legacy tant que le runtime existant en depend encore.
- Les gateways cible sont `OKX`, `Hyperliquid` et `Fake/Paper`.

## Utilisation cible

Ces fichiers servent de reference pour :

- comparer dev/prod ;
- verifier les clés manquantes ;
- preparer `EffectiveTradingConfigResolver` ;
- documenter les besoins des gateways cible ;
- eviter que les secrets soient disperses dans la documentation.
