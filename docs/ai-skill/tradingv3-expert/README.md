# TradingV3 Expert Skill

Skill IA spécialisé pour le projet TradingV3.

Objectif : transformer un agent IA (Codex CLI ou Claude Code) en expert opérationnel capable d'analyser, diagnostiquer, améliorer et industrialiser un système de trading crypto futures multi-exchange (BitMart, OKX, Hyperliquid).

Ce skill est conçu pour :

- Réduire les pertes avant d'augmenter la fréquence
- Structurer la validation statistique
- Séparer stratégie et contraintes exchange
- Garantir un risk management strict
- Générer des issues GitHub et PR atomiques
- Être directement exploitable dans un repo Symfony/PHP

---

## Philosophie

1. La réduction des pertes prime sur l'augmentation du nombre de trades.
2. Aucun trade sans stop-loss automatique.
3. Aucun levier arbitraire.
4. Aucun changement sans validation statistique.
5. Aucune dépendance exchange dans la logique de stratégie.
6. Toute modification doit être atomique, testable, traçable.

---

## Structure du dossier

Voir `SKILL.md` pour la définition complète du comportement de l'agent.

Fichiers de référence inclus :

- `risk-management.md`
- `backtesting-methodology.md`
- `statistical-validation.md`
- `exchange-integration.md`
- `yaml-structure.md`
- `checklists.md`
- `github-templates.md`
- `prompts.md`
- `issues-ready.md`
