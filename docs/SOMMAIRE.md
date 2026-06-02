# Sommaire — tradingV3

La documentation canonique est maintenant dans `docs/handbook/`.
Le thème Bootstrap custom est dans `docs/mkdocs_theme/` et la configuration dans `mkdocs.yml`.
Le rendu HTML Bootstrap est généré dans `docs/site/`, qui n'est pas versionné.

## Build local

```bash
python3 -m pip install -r requirements-docs.txt
python3 -m mkdocs serve
python3 -m mkdocs build --strict
```

Avant une PR, `python3 -m mkdocs build --strict` doit passer.

## Publication GitHub Pages

GitHub Actions construit automatiquement le site MkDocs et publie `docs/site/` vers GitHub Pages à chaque push documentaire sur `main`.
Le workflow peut aussi être lancé manuellement avec `workflow_dispatch`.

Configuration GitHub à faire une fois : `Settings > Pages` → Source `GitHub Actions`.
URL cible : `https://pivox.github.io/tradingV3/`.

## Handbook

- `docs/handbook/index.md`
- `docs/handbook/architecture.md`
- `docs/handbook/functional/`
- `docs/handbook/technical/`
- `docs/handbook/graphs/`
- `docs/handbook/runbooks/`
- `docs/handbook/inventories/`

## trading-app

Ces fichiers restent comme références spécialisées historiques/fonctionnelles. Le handbook prime en cas de divergence.

- `docs/trading-app/00-introduction.md`
- `docs/trading-app/01-bitmart-apis-rate-limit.md`
- `docs/trading-app/02-klines.md`
- `docs/trading-app/03-indicateurs.md`
- `docs/trading-app/04-validation-mtf.md`
- `docs/trading-app/05-contrats.md`
- `docs/trading-app/06-trade-entry.md`
- `docs/trading-app/07-conditions-reference.md`
- `docs/trading-app/08-profils-validations.md`
- `docs/trading-app/09-trade-entry-yaml-reference.md`
- `docs/trading-app/10-mtf-contracts-yaml-reference.md`
- `docs/trading-app/11-validations-yaml-reference.md`
- `docs/trading-app/12-api-mtf-run-flux-scalper-micro.md`
- `docs/trading-app/user-stories.md` - User stories complètes du flux API → ordre placé

## Présentation

- `docs/presentation.md` - Présentation générale de l'application (format reveal.js)
