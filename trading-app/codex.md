# Codex Notes

## Fallback Messenger (EntityManager fermé)

- Problème historique : une erreur DBAL lors de l’upsert des klines fermait l’EntityManager, ce qui faisait échouer la projection MTF (audit / state / signaux).
- Correction primaire : sécuriser l’upsert (conversion explicite des `DateTimeImmutable` en string) pour supprimer la cause racine des fermetures d’EM.
- Garde‑fous actuels :
  - `BaseTimeframeService::persistAudit()` et `MtfResultProjector::project()` testent `EntityManager::isOpen()` et se mettent en “best‑effort” si l’EM est fermé.
- Plan de fallback Messenger (à implémenter si besoin) :
  - Créer un message `PersistMtfResultMessage` (runId, MtfRunDto/MtfResultDto sérialisés).
  - Dans `MtfResultProjector`, si `!$em->isOpen()`, logger puis dispatcher le message sur un bus Messenger dédié (`mtf_audit`).
  - Ajouter un handler consommé par le container `trading-app-messenger` qui rouvre un EM propre, reconstruit les entités (`MtfAudit`, `MtfState`, `Signal`) et persiste/flush.
  - Variante : même principe pour les audits ponctuels (`PersistMtfAuditMessage`) si on veut une granularité plus fine.

## Balanced Preset (objectif 7‑10 trades/jour, WR ≥55 %)

1. **Option A – ajuster le basculement vers 5m**
   - `execution_selector.drop_to_5m_if_any`: `atr_pct_15m_gt_bps` 130 + `entry_zone_width_pct_gt` 1.3.
   - Permet encore de rester en 15m quand la volatilité se contracte, mais autorise 5m quand le move est suffisamment vertueux.
   - Maintien du blocage `forbid_drop_to_5m_if_any` (`adx_5m_lt: 25`, `spread_bps_gt: 6`) pour éviter les trades en chop ; donc les 7‑10 trades viennent uniquement quand il y a un vrai momentum.

2. **Option B – micro-relax sur les micro-TF**
   - 5m/1m long acceptent désormais `close_above_vwap_or_ma9`; micro-triggers sont moins rigides.
   - RSI longs : 15m 65, 5m 65, 1m 62 (filtre global `rsi_lt_70` à 68). Cela laisse entrer un peu plus tôt sans dégrader le ratio.
   - MACD cross conserve l’hystérésis (prev gap, cool_down) = on filtre toujours le bruit tout en ouvrant plus de portes.

> Pourquoi ce combiné aide : on garde le squelette anti-SL (VWAP sur Tendance + ADX/Spread), on étend les micro-entrées quand la tendance est bonne, et on garde un RSI “sensible” et un levier borné. Cela vise explicitement ~7–10 trades/jour avec des win-rates ≥55 % car les entrées restent sur des breaks confirmés mais on tolère un peu plus l’entrée micro (Option B) tant que les gardes globaux (Option A) tiennent.
