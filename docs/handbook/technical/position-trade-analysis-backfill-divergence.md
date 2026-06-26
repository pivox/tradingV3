# `position_trade_analysis` v1/v2 backfill divergence dry-run

Issue #190 ajoute une commande de lecture seule pour auditer les donnees historiques avant
tout backfill du contrat `position_trade_analysis_v2`. Elle compare la vue historique
`position_trade_analysis` et la vue versionnee `position_trade_analysis_v2` sans reconstruire de
relation par symbole seul ni par fenetre temporelle.

## Commande

```bash
php bin/console app:position-trade-analysis:backfill-divergence \
  --from=2026-06-01 \
  --to=2026-06-30 \
  --mtf-profile=scalper \
  --exchange=fake \
  --market-type=perpetual \
  --symbol=BTCUSDT \
  --limit=500 \
  --batch-size=100 \
  --export-json=/tmp/pta-backfill-report.json \
  --export-csv=/tmp/pta-backfill-report.csv
```

Le mode par defaut est `--dry-run=1`. Une valeur date-only sur `--to` couvre toute la journee
UTC (`23:59:59.999999`). `--apply` existe uniquement comme garde explicite et
echoue volontairement dans cette PR. Aucun write n'est effectue par la commande.

## Cle de comparaison

La comparaison v1/v2 se fait uniquement par `entry_event_id`, l'identifiant persistant de
l'evenement d'entree commun aux deux vues. Les filtres `symbol`, `mtf-profile`, `exchange` et
`market-type` ne servent qu'a reduire le perimetre de lecture ; ils ne creent jamais de relation
entre une entree et une cloture.

Les lignes historiques ou v1 a rapproche une cloture par symbole/temps restent visibles comme
divergentes si v2 ne dispose pas d'un identifiant exact (`internal_trade_id`, `trade_id` ou
`position_id` borne a la venue).

## Pagination

La lecture est bornee par `--limit` (maximum 5000). Le rapport expose :

- `pagination.resume_cursor` : curseur d'entree recu ;
- `pagination.next_cursor` : dernier `entry_event_id` retourne ;
- `pagination.truncated` : vrai si plus de lignes existent au-dela de la page courante.

Pour reprendre :

```bash
php bin/console app:position-trade-analysis:backfill-divergence \
  --resume-cursor=123456 \
  --limit=500 \
  --export-json=/tmp/pta-backfill-report-next.json
```

## Classifications

Le champ `rows[].classification` peut valoir :

- `certified` : v2 est complete et expose un `net_pnl_usdt` certifie ;
- `partial` : ligne exploitable partiellement mais non certifiable ;
- `unknown` : etat ou couts inconnus ;
- `unmatched` : v2 ne rapproche pas la cloture par identifiant exact ;
- `identifier_conflict` : conflit d'identifiants conserve en flag ;
- `quantity_mismatch` : quantite incoherente ;
- `costs_incomplete` : couts partiels, jamais convertis en zero ;
- `v1_only` : presente seulement dans v1 ;
- `v2_only` : presente seulement dans v2 ;
- `pnl_divergence` : ecart PnL entre v1 et v2 au-dela de l'epsilon technique.

Une ligne incomplete n'est jamais presentee comme complete. Les valeurs absentes restent `null`.
Le delta `pnl_delta_usdt` compare v1 au `net_pnl_usdt` lorsque v2 est certifiee
(`cost_completeness=complete` et net present). Pour les lignes non certifiees, il compare v1 a
`recorded_pnl_usdt`, puis a `gross_realized_pnl_usdt` seulement si le recorded est absent.

## Rapport

Le JSON contient :

- `metadata` : filtres, cle de comparaison, mode read-only ;
- `summary` : volumes v1/v2, lignes communes, divergences, certifications, exclusions, taux de
  preuve lineage/cout/quantite ;
- `pagination` : limite, curseur et statut de troncature ;
- `proposal` : `ready_for_backfill=false` si une divergence, une exclusion ou une pagination
  tronquee subsiste ;
- `rows` : details par `entry_event_id`, avec deltas PnL, duration, MFE et MAE.

Exemple minimal :

```json
{
  "metadata": {
    "dry_run": true,
    "read_only": true,
    "comparison_key": "entry_event_id"
  },
  "summary": {
    "v1_rows": 2,
    "v2_rows": 2,
    "common_rows": 2,
    "certified_rows": 1,
    "excluded_rows": 1,
    "classification_counts": {
      "certified": 1,
      "unmatched": 1
    }
  },
  "proposal": {
    "ready_for_backfill": false,
    "blocking_reason": "divergence_or_incomplete_data",
    "apply_mode_available": false
  }
}
```

## Garanties

- Aucun fallback par symbole seul.
- Aucun rapprochement par fenetre temporelle pour le backfill.
- Aucun cout absent transforme en zero.
- Aucun changement live, strategie, MTF, EntryZone, Risk/Leverage ou SL/TP.
- Aucun payload brut sensible exporte.
