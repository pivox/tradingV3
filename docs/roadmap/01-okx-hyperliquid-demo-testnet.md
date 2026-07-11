# TradingV3 — Vague 1 — OKX / Hyperliquid demo-testnet

**Issue parent :** #173  
**Statut :** en cours avancé  
**Objectif :** fiabiliser OKX demo et Hyperliquid testnet sans aucune écriture mainnet.

Cette vague remplace la logique “dry-run uniquement” par une cible plus précise :

- `dry_run=true` : simulation locale, aucun ordre envoyé à l’exchange ;
- `dry_run=false + environment=demo|testnet` : exécution mutative autorisée uniquement après gates explicites ;
- `dry_run=false + mainnet` : interdit dans toute cette vague.

## Règles permanentes

- Ne jamais utiliser, demander, stocker ou logger de secret mainnet.
- OKX est autorisé uniquement en Demo Trading.
- Hyperliquid est autorisé uniquement en testnet.
- Toute exécution mutative demo/testnet nécessite whitelist, notional minimal, kill switch, runtime-check, audit et rollback.
- Aucune position, même fictive, sans SL immédiat ou compensation fail-safe documentée.
- Aucun fallback silencieux vers Bitmart quand `exchange=okx` ou `exchange=hyperliquid`.
- Aucune optimisation stratégie, fréquence, EntryZone ou SL/TP métier dans cette vague.
- Toute PR doit être atomique, testée, documentée et rollbackable.
- Surveiller chaque PR jusqu’à validation Codex ; traiter les retours pertinents ; marquer résolu ; compresser le contexte si 80%.

## Déjà livré / à cocher dans #173

- [x] COMMON-001 — Safety envelope demo/testnet — #221 / `45ff518`.
- [x] COMMON-002 — Effective Config runtime exchange/env — #222 / `9cb132f`.
- [x] COMMON-003 — Exchange readiness contract commun — #223 / `b801a6b`.
- [x] COMMON-004 — Kill switch demo/testnet commun — #224 / `0e61c01`.
- [x] COMMON-006 — Private observability policy — #225 / `49d45fb`.
- [x] COMMON-005 — Fake/Paper scenarios pour recette demo — #226 / `280837e`.
- [x] OKX-001 — Capability matrix OKX + ADR readiness demo — #227 / `6313c55`.
- [x] OKX-002 — Provider bundle OKX skeleton — #228 / `06b275c`.
- [x] OKX-003 — Public REST read-only OKX — #229 / `4e5919c`.
- [x] OKX-004 — Auth + private read-only OKX demo — #230 / `fb902e7`.
- [x] OKX-005 — Metadata / precision / fees / funding OKX — #231 / `5d7f399`.
- [x] OKX-006 — Normalizers lifecycle OKX — #232 / `bf8c1fb`.
- [x] OKX-007 — Local dry-run write serialization OKX — #233 / `18ef164`.
- [x] OKX-008 — Runtime-check demo/testnet candidate OKX — #234 / `8444f7b`.
- [x] OKX-009 — Orchestrator dry-run recipe OKX — #235 / `c103c1b`.
- [x] HL-001 — Capability matrix + ADR Hyperliquid testnet — #236 / `5724aab`.
- [x] HL-002 — Provider bundle skeleton Hyperliquid — #237 / `ac8e1d2`.
- [x] HL-003 — Public read-only Hyperliquid — #238 / `3e89691`.
- [x] HL-004 — Signer Hyperliquid isolé — #239 / `27ec03a`.
- [x] HL-005 — Nonce manager Hyperliquid persistant — #240 / `932d686`.
- [x] HL-006 — Account read-only Hyperliquid — #241 / `7bfad8f`.
- [x] HL-007 — Metadata / precision / fees / funding Hyperliquid — #242 / `b0ce547`.
- [x] HL-008 — Order/fill/position normalizers Hyperliquid — #243 / `8d75cf5`.
- [x] HL-009 — Local dry-run no broadcast Hyperliquid — #245 / `d595d4d`.
- [x] HL-010 — Runtime-check `demo_testnet_candidate` Hyperliquid — #248 / `8e1650f`.
- [x] HL-011 — Orchestrator dry-run recipe Hyperliquid — #249 / `0b62049`.
- [x] DEMO-001 — Fixtures demo OKX + Hyperliquid — #250 / `d90b3dd`.
- [x] DEMO-002 — Recette R1-R16 double exchange en dry-run — #251 / `e524424`.
- [x] DEMO-003 — Demo ops runbook + rollback — #252 / `79acc0c`.
- [x] DEMO-004 — Enable demo schedule guarded — #253.
- [x] DEMO-005 — Pre-mutative demo readiness decision — decision `blocked`.

## Restant vague 1

- [ ] OKX-010 — Demo trading controlled avec SL.
- [ ] HL-012 — Testnet trading controlled avec SL.
- [ ] DEMO-006 — Final demo/testnet execution evidence report.

## Ordre strict recommandé

```text
OKX-010
HL-012

DEMO-006
```

## Prompt type pour chaque PR restante

```text
Tu travailles sur le repo pivox/tradingV3.
Langue : français.

Objectif : créer la PR <ID> de la vague 1 OKX/Hyperliquid demo-testnet.

Contraintes :
- PR atomique.
- Aucun mainnet mutatif.
- Aucun secret mainnet.
- Aucun fonds réel.
- Aucun tuning stratégie.
- Tests ciblés obligatoires.
- Documentation et rollback si runtime/config/ops touchés.
- Surveiller la PR jusqu’à validation Codex.
- Corriger les retours pertinents, répondre et marquer comme résolu.
- Si contexte 80%, compresser en gardant objectif, fichiers modifiés, décisions, tests, retours ouverts, risques, rollback, prochaine action.

Definition of Done :
- tests ciblés verts ;
- `git diff --check` ;
- `mkdocs build --strict` si docs touchées ;
- pas de secret ;
- body PR complet ;
- rollback documenté.
```

## Critère de sortie vague 1

La vague 1 est terminée uniquement si :

- OKX demo et Hyperliquid testnet ont une recette dry-run complète ;
- la décision pre-mutative est documentée ;
- OKX-010 et HL-012 n’autorisent des ordres que sur environnements fictifs ;
- un rapport final prouve ou bloque la readiness demo/testnet ;
- aucune formulation ne laisse croire que le mainnet est prêt.
