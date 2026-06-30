# TradingV3 — Vague 4 — Shadow, Capital Protection & Live-readiness

**Issue parent :** #173  
**Dépendance :** au moins une config candidate validée par la vague 3  
**Objectif :** vérifier que l’edge survit aux conditions de marché réelles, puis préparer une gouvernance de risque sans activer le mainnet.

Cette vague ne signifie pas “trader en réel”. Elle prépare la décision. Toute activation réelle éventuelle doit rester manuelle, séparée et explicitement validée.

## Règles permanentes

- Pas de secrets mainnet dans l’application tant que la décision humaine finale n’est pas prise.
- Pas d’ordre réel dans les PR de live-readiness.
- Shadow production avant capital réel.
- Risque et capital protection avant scaling.
- Un seul mode, peu de symboles, un exchange stable avant toute extension.
- Tout incident déclenche pause, audit et rollback.
- Toute PR doit être atomique, testée, documentée et rollbackable.
- Surveiller chaque PR jusqu’à validation Codex ; traiter les retours pertinents ; marquer résolu ; compresser le contexte si 80%.

## PR proposées

### Bloc 4A — Shadow production réaliste

- [ ] SHADOW-001 — ADR Shadow Production : objectifs, limites, données nécessaires, no-mainnet.
- [ ] SHADOW-002 — Shadow signal runner : générer les signaux live sans envoyer d’ordre.
- [ ] SHADOW-003 — Capture bid/ask/spread/orderbook au moment du signal.
- [ ] SHADOW-004 — Simulateur fill réaliste : market, limit, non-fill, TTL, slippage pessimiste.
- [ ] SHADOW-005 — Comparaison signal théorique vs fill réaliste.
- [ ] SHADOW-006 — Rapport quotidien shadow : PnL brut, PnL net, fills, non-fills, slippage.
- [ ] SHADOW-007 — Rapport par mode/config/symbol/exchange en shadow.
- [ ] SHADOW-008 — Gate : rejet config si edge disparaît en shadow.

### Bloc 4B — Capital protection layer

- [ ] RISKCAP-001 — ADR Capital Protection : limites journalières, hebdomadaires, exposition, pause.
- [ ] RISKCAP-002 — `risk_per_trade` strict : 0.25% à 0.5% par défaut en pilot.
- [ ] RISKCAP-003 — Daily loss limit et weekly loss limit.
- [ ] RISKCAP-004 — Max consecutive losses → pause automatique.
- [ ] RISKCAP-005 — Max open positions et max exposure par exchange/symbol/mode.
- [ ] RISKCAP-006 — Circuit breaker volatilité/spread/stale data.
- [ ] RISKCAP-007 — Confirmation humaine pour changement config critique.
- [ ] RISKCAP-008 — Audit capital protection dans cockpit et logs redacted.

### Bloc 4C — Mainnet-readiness sans activation

- [ ] READY-001 — ADR mainnet-readiness sans activation mainnet.
- [ ] READY-002 — Strategy secrets/vault future : aucun secret mainnet en Git/env par défaut.
- [ ] READY-003 — Runtime gate `mainnet_write_enabled=false` impossible à contourner silencieusement.
- [ ] READY-004 — Mainnet read-only éventuel, séparé des permissions write.
- [ ] READY-005 — Double confirmation humaine pour toute future activation.
- [ ] READY-006 — Runbook incident : kill switch, close/cancel, rollback, postmortem.
- [ ] READY-007 — Alerting Telegram/email pour incidents, drawdown, stale data, stop attach failure.
- [ ] READY-008 — Audit complet des décisions, configs et hash de version.

### Bloc 4D — Controlled live pilot éventuel

Ce bloc reste documentaire tant qu’aucune décision humaine finale n’autorise le réel.

- [ ] PILOT-001 — Critères go/no-go pour petit live pilot.
- [ ] PILOT-002 — Plan pilot : 1 exchange, 1 mode, 1 à 3 symboles, capital ridicule.
- [ ] PILOT-003 — Checklist pré-pilot : shadow positif, risk caps, runbook, rollback.
- [ ] PILOT-004 — Rapport pilot quotidien : PnL net, drawdown, incidents, décisions.
- [ ] PILOT-005 — Stop criteria : premier incident critique, drawdown, SL attach fail, data stale.
- [ ] PILOT-006 — Live pilot extended si et seulement si pilot initial positif et stable.

### Bloc 4E — Production risk governance et scaling contrôlé

- [ ] GOV-001 — Budget de perte mensuel.
- [ ] GOV-002 — Capital allocation par mode/exchange/symbol.
- [ ] GOV-003 — Versioning et approbation des configs.
- [ ] GOV-004 — Rapport mensuel expectancy nette et drawdown.
- [ ] GOV-005 — Décision : source rentable, pause, refonte, ou projet R&D/portfolio.
- [ ] SCALE-001 — Règles de scaling lent : capital avant fréquence, un axe à la fois.

## Gates obligatoires

### Gate Shadow

```text
GO shadow-to-pilot si :
- expectancy_R nette positive ;
- profit factor robuste ;
- slippage pessimiste encore acceptable ;
- pas de dépendance à un seul trade ;
- config stable plusieurs semaines ;
- aucun incident safety critique.
```

### Gate Capital

```text
NO-GO si :
- edge shadow négatif ;
- frais/spread détruisent le résultat ;
- drawdown supérieur au budget ;
- SL attach/fail-safe non prouvé ;
- runtime-check ou kill switch douteux ;
- données incomplètes certifiées à tort.
```

## Prompt type

```text
Tu travailles sur le repo pivox/tradingV3.
Langue : français.

Objectif : créer la PR <ID> de la vague 4 Shadow/Capital Protection/Live-readiness.

Contraintes :
- PR atomique.
- Aucun ordre mainnet.
- Aucun secret mainnet.
- Aucun scaling.
- Aucun live pilot implicite.
- Shadow avant capital réel.
- Risk caps avant toute expérimentation live future.
- Toute décision doit être fondée sur position_trade_analysis + shadow report.
- Surveiller la PR jusqu’à validation Codex.
- Corriger les retours pertinents, répondre et marquer comme résolu.
- Si contexte 80%, compresser en gardant objectif, fichiers modifiés, décisions, tests, retours ouverts, risques, rollback, prochaine action.

Tests attendus selon périmètre :
- tests risk caps ;
- tests circuit breaker ;
- tests no-mainnet ;
- tests alerting/fail-closed ;
- mkdocs build --strict si docs ;
- git diff --check.
```

## Critère de sortie vague 4

La vague 4 est terminée si TradingV3 peut produire une décision claire :

```text
- go small pilot
- no-go, continuer shadow
- no-go, rejeter config/mode
- no-go, refonte stratégie
```

Le résultat attendu n’est pas “trader gros”. Le résultat attendu est :

```text
un système qui sait dire non,
perdre peu,
s’arrêter seul,
et prouver ou invalider son edge avec des données.
```
