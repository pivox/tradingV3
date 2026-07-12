# Hyperliquid Testnet Controlled Trading

## Statut et interdiction actuelle

HL-012 fournit le chemin d'execution controlee Hyperliquid testnet, mais il est
desactive par defaut. Au 12 juillet 2026, la decision DEMO-005 reste
`blocked` : aucun ordre reel, y compris avec fonds fictifs, n'a ete envoye par
ce chemin. Le smoke mutatif de ce runbook est interdit tant qu'une decision
explicite, versionnee et relue ne remplace pas DEMO-005 par
`ready_for_demo_testnet_trading_attempt`.

Ce runbook ne rend jamais le mainnet disponible. Il prepare une unique
tentative operateur sur `hyperliquid/testnet/perpetual`, avec stop loss plein
volume et compensation fail-closed. Toute preuve obtenue avant l'ouverture de
la gate est une preuve de readiness ou de dry-run, jamais une preuve
d'execution reelle.

## Perimetre et ownership

La tentative exige un compte Hyperliquid testnet et un agent API dedies a
TradingV3. Pendant toute la fenetre, TradingV3 a l'ownership externe exclusif :

- aucun humain, bot, script, UI ou autre processus ne peut ecrire sur ce compte ;
- l'agent n'est utilise par aucun autre processus ;
- le symbole cible n'a aucune position, y compris de taille residuelle ;
- le symbole cible n'a aucun ordre ouvert, conditionnel ou non ;
- le compte est finance uniquement en collateral testnet suffisant pour le
  notional borne et sa marge ;
- toute intervention manuelle met fin a la recette et doit etre declaree comme
  incident avec transfert d'ownership explicite.

L'**account address** est l'adresse du master account ou subaccount dont on lit
le collateral, les positions et les ordres. L'**agent address** est l'adresse
du wallet API approuve qui signe pour ce compte. Elles doivent etre distinctes.
Les lectures `/info` utilisent toujours l'account address ; utiliser l'agent
address retournerait un state vide ou incorrect. Un agent neuf et dedie au
processus evite aussi le partage de nonces recommande par la documentation
Hyperliquid : [Nonces and API wallets](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/nonces-and-api-wallets).

## Secrets et sidecar

Le signer est le service Compose `hyperliquid-signer`, sous le profile
`hyperliquid-testnet`. Il expose `8098` uniquement sur le reseau Docker interne
`trading-app-net` et ne publie aucun port hote. Son endpoint est fixe a
`http://hyperliquid-signer:8098` cote PHP.

La private key de l'agent est injectee uniquement au sidecar. Le container PHP,
les workers, les fichiers de plan, Git, les logs, les captures et les rapports
ne doivent jamais la recevoir. Ne jamais coller une private key, un token
d'authentification, une signature ou un payload signe dans un terminal partage,
une commande, un ticket ou ce runbook. Ne pas publier la sortie de
`docker compose config`, qui peut materialiser les variables du sidecar.

La private key du compte principal est hors perimetre applicatif. Le compte
testnet peut obtenir du collateral fictif selon la procedure officielle
[Testnet faucet](https://hyperliquid.gitbook.io/hyperliquid-docs/onboarding/testnet-faucet).

## Prerequis

Avant toute preparation :

1. La migration `Version20260712120000` est appliquee et PostgreSQL est sain.
2. Le compte et l'agent testnet dedies sont approuves et finances.
3. `HYPERLIQUID_ENV=testnet`, `HYPERLIQUID_NETWORK=testnet` et l'endpoint
   `https://api.hyperliquid-testnet.xyz` sont imposes.
4. `HYPERLIQUID_MAINNET_ENABLED=0` reste immuable.
5. Les adresses account et agent sont distinctes, valides et configurees hors Git.
6. L'auth token sidecar est configure hors Git ; sa valeur n'est jamais affichee.
7. Les configs effectives restent fail-closed pendant la preparation :
   `dry_run=true`, `live_enabled=false`, `demo_testnet_write_enabled=false`,
   `kill_switch_enabled=true` et `mainnet_write_enabled=false`.
8. DEMO-005 est relu. Avec la decision actuelle `blocked`, s'arreter avant la
   section « Tentative mutative future ».

Verifier l'etat sans afficher de valeur sensible :

```bash
docker compose ps trading-app-db trading-app-php trading-app-messenger-trading

docker compose exec -T trading-app-php php -r '
foreach ([
    "HYPERLIQUID_ENV",
    "HYPERLIQUID_NETWORK",
    "HYPERLIQUID_API_BASE_URI",
    "HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS",
    "HYPERLIQUID_TESTNET_AGENT_ADDRESS",
    "HYPERLIQUID_SIGNER_AUTH_TOKEN",
] as $name) {
    $value = getenv($name);
    echo $name . "=" . ($value === false || trim((string) $value) === "" ? "missing" : "present") . PHP_EOL;
}
'
```

Ne pas ajouter `HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY` a cette verification :
PHP ne doit pas la recevoir.

## Demarrage du sidecar, gates fermees

Charger les secrets dans le mecanisme local approuve, sans les saisir dans la
ligne de commande, puis garder les trois gates d'environnement fermees :

```bash
export DEMO_TRADING_ENABLED=0
export HYPERLIQUID_TESTNET_TRADING_ENABLED=0
export HYPERLIQUID_SIGNER_BROADCAST_ENABLED=0

docker compose --profile hyperliquid-testnet up -d --no-deps hyperliquid-signer
docker compose --profile hyperliquid-testnet ps hyperliquid-signer
```

Le service ne doit afficher aucun mapping de port hote. Les logs sont sensibles
meme s'ils sont redacted ; ne conserver que les lignes necessaires et relire la
redaction avant archivage :

```bash
docker compose --profile hyperliquid-testnet logs --tail=50 hyperliquid-signer
```

## Readiness et dry-run autorises

Recreer PHP apres toute modification de gate d'environnement, puis executer le
runtime-check :

```bash
docker compose up -d --no-deps --force-recreate trading-app-php
docker compose exec -T trading-app-php \
  php bin/console app:exchange:runtime-check hyperliquid perpetual
```

Dans l'etat actuel, les sorties attendues restent `Live trading: disabled`,
`Dry-run only: yes`, `Live allowed: no`, `Recommended dry_run: true` et
`Schedule ready: no`. Une autre sortie ne constitue pas une autorisation :
DEMO-005 reste le gate humain et documentaire.

La recette orchestrateur autorisee est locale et force `dry_run=true` :

```bash
cd python-orchestrator
python scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --target-exchange hyperliquid \
  --scenario R1 \
  --scenario R2 \
  --scenario R14 \
  --export-dir var/runtime-recipe/hyperliquid-dry-run \
  --keep-fixtures
cd ..
```

R14 doit refuser `dry_run=false` avant dispatch. Conserver le rapport redacted ;
il ne prouve aucun broadcast.

## Preuve de marge et liquidation

Le plan n'accepte pas une constante locale de maintenance margin. Il exige une
preuve officielle fraiche de cinq secondes au plus :

- `POST /info` type `meta` pour `universe`, `marginTables`, le coin et la table
  de marge ;
- `POST /info` type `activeAssetData` avec l'account address et le coin pour le
  mode `isolated` et le leverage effectivement observes ;
- identite exacte account/user, coin/symbol et leverage demande/observe.

Ces surfaces sont documentees dans l'[Info endpoint perpetuals](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint/perpetuals).
La table officielle definit :

```text
maintenance_margin = notional_position_value * maintenance_margin_rate
                     - maintenance_deduction

maintenance_margin_rate(tier n) = 1 / (2 * max_leverage(tier n))

maintenance_deduction(tier 0) = 0
maintenance_deduction(tier n) = maintenance_deduction(tier n - 1)
  + lower_bound(tier n)
  * (maintenance_margin_rate(tier n) - maintenance_margin_rate(tier n - 1))
```

Source : [Margin tiers](https://hyperliquid.gitbook.io/hyperliquid-docs/trading/margin-tiers).
La liquidation officielle utilise le mark price et :

```text
liq_price = price - side * margin_available / position_size
            / (1 - l * side)

l = 1 / maintenance_leverage
side = 1 pour long, -1 pour short
margin_available (isolated) = isolated_margin - maintenance_margin_required
```

Pour une table a paliers, le tier depend du notional au prix de liquidation ;
HL-012 resout donc le tier et le prix de maniere coherente, puis applique le
`LiquidationGuard`. Source : [Liquidations](https://hyperliquid.gitbook.io/hyperliquid-docs/trading/liquidations).
Toute preuve `meta`/`activeAssetData` absente, stale ou incoherente bloque avant
nonce et avant broadcast.

## Plan JSON schema v1

Le fichier est un objet JSON strict de 64 KiB maximum, regulier, non symlink,
appartenant a l'utilisateur effectif et non inscriptible par group/others. Le
decoder refuse les champs absents, supplementaires ou dupliques. Le creer dans
le container PHP sous un `umask 077`, puis verifier `chmod 600`.

Schema exact :

```json
{
  "schema_version": 1,
  "order_plan": {
    "symbol": "BTCUSDT",
    "profile": "scalper_micro",
    "config_hash": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "exchange": "hyperliquid",
    "market_type": "perpetual",
    "side": "long",
    "order_type": "limit",
    "margin_mode": "isolated",
    "time_in_force": "gtc",
    "entry_price": "50000",
    "quantity": "0.0002",
    "leverage": 2,
    "client_order_id": "hl12-testnet-attempt-001",
    "idempotency_key": "hl12:testnet:attempt:001",
    "protection_plan": {
      "stop_loss": {
        "stop_price": "49000",
        "stop_source": "operator_controlled",
        "is_full_size": true
      }
    }
  }
}
```

Les valeurs de prix et quantite sont des **chaines decimales canoniques** :
positives, sans signe, exposant, zero initial ou zero decimal final, avec huit
decimales au plus et representation float exacte. `"50000"` et `"0.0002"`
sont canoniques ; `50000`, `"50000.0"`, `"+50000"` et `"5e4"` sont refuses.
Le hash doit etre le `config_hash` effectif de 64 caracteres hexadecimaux, les
identifiants doivent etre uniques, et le notional doit respecter l'allow-list
et le plafond du profil. L'exemple est uniquement structurel : ses prix ne sont
pas une recommandation et doivent etre recalcules avec une quote fraiche.

HL-012 impose `perpetual`, `limit`, `gtc`, `isolated`, leverage entier 1..50,
position initialement flat et aucun ordre ouvert sur le symbole. Le stop doit
etre plein volume, correctement place par rapport au side et suffisamment loin
du prix de liquidation selon la garde. Aucun take profit n'est accepte dans le
schema v1.

## Gate de tentative mutative future

Cette section est **interdite avec DEMO-005=`blocked`**. Une future execution
n'est permise que si toutes les conditions suivantes sont simultanement
prouvees dans une decision versionnee :

1. decision exacte `ready_for_demo_testnet_trading_attempt` ;
2. confirmation operateur exacte `CONFIRM_HYPERLIQUID_TESTNET_ONLY` ;
3. compte et symbole flat, sans ordre ouvert, observes juste avant nonce ;
4. ownership exclusif confirme et reconciliation non concurrente ;
5. configs effectives explicitement changees pour la fenetre controlee :
   `dry_run=false`, `live_enabled=true`, `demo_testnet_write_enabled=true`,
   `kill_switch_enabled=false`, `mainnet_write_enabled=false`, SL requis,
   allow-list et max notional minimal ;
6. gates `DEMO_TRADING_ENABLED=1`,
   `HYPERLIQUID_TESTNET_TRADING_ENABLED=1` et signer broadcast testnet ouvertes ;
7. runtime-check sans blocking error, `Readiness level: demo_testnet_candidate`,
   `Live allowed: yes`, `Recommended dry_run: false` et `Schedule ready: yes` ;
8. preuve marge/liquidation authoritative fraiche et plan schema v1 valide.

Apres activation approuvee, recreer PHP et le sidecar, rejouer le runtime-check,
puis lancer exactement une fois :

```bash
docker compose exec -T trading-app-php \
  php bin/console app:hyperliquid:testnet:smoke \
  /var/www/html/var/operator/hyperliquid-testnet-plan-v1.json \
  --confirm=CONFIRM_HYPERLIQUID_TESTNET_ONLY \
  --readiness-decision=ready_for_demo_testnet_trading_attempt
```

Ne jamais relancer apres timeout, sortie `ambiguous` ou erreur. Un succes exige
`status=accepted`, le meme `client_order_id`, un `exchange_order_id` opaque et
`protection_confirmed=true` dans la preuve applicative. Toute autre sortie est
un incident.

## Observation, reconciliation, cancel et close

L'observation est bornee aux identifiants emis par la commande :
`client_order_id`, cloid wire derive et `exchange_order_id`. Il est interdit de
reconcilier par symbole seul, proximite temporelle, taille ou prix. Ne pas
afficher de payload signe.

```bash
rg -n --fixed-strings 'hl12-testnet-attempt-001' trading-app/var/log
```

Le chemin applicatif gere le cancel/close, pas l'operateur :

- si l'entree est resting ou partiellement filled lors d'un incident, il annule
  par cloid puis confirme le lifecycle via `orderStatus` lie au cloid/oid ;
- s'il reste une exposition, il soumet un close reduce-only borne, puis confirme
  le close par les memes identifiants ;
- si la protection, l'annulation ou le close ne sont pas confirmes, il trip le
  kill switch durable et conserve la quarantaine.

Une action manuelle UI/API pendant la fenetre viole l'ownership exclusif. Si
elle devient indispensable pour proteger le compte testnet, l'incident
commander annonce le transfert d'ownership, annule la recette, note uniquement
les identifiants non sensibles et traite l'etat final comme non certifie.

## Incident et quarantaine durable

Sur timeout, resultat ambigu, protection non confirmee, divergence d'identite,
reconciliation impossible, echec de lock ou exposition residuelle :

1. ne pas retenter ;
2. executer immediatement le rollback ci-dessous ;
3. conserver les identifiants, timestamps UTC, config hash et sorties redacted ;
4. verifier la position et les ordres uniquement via l'account address ;
5. laisser le kill switch DB et le marker filesystem en place jusqu'a revue.

La quarantaine primaire est la ligne singleton
`hyperliquid_testnet_kill_switch_state`. Si PostgreSQL est indisponible au
moment du trip, le fallback durable est
`trading-app/var/hyperliquid-testnet-execution.quarantine`. Ne jamais supprimer,
renommer ou editer ce marker a la main.

Verifier la DB sans afficher de contexte sensible :

```bash
docker compose exec -T trading-app-php php bin/console dbal:run-sql \
  "SELECT scope, tripped, reason, tripped_at, updated_at FROM hyperliquid_testnet_kill_switch_state WHERE scope = 'hyperliquid_testnet'"
```

Si le marker existe et **seulement apres** confirmation que la DB est lisible et
`tripped=true`, transferer la quarantaine avec la commande controlee :

```bash
docker compose exec -T trading-app-php \
  php bin/console app:hyperliquid:testnet:quarantine-recover \
  --confirm=CONFIRM_HYPERLIQUID_TESTNET_QUARANTINE_TRANSFER

docker compose restart trading-app-php trading-app-messenger-trading
```

Cette commande ne reset jamais le kill switch. Le restart est obligatoire pour
liberer tout lock retenu en memoire ; il ne constitue pas une autorisation de
reprendre.

## Rollback immediat sans deploiement

Le rollback ne depend pas d'un nouveau build :

```bash
export DEMO_TRADING_ENABLED=0
export HYPERLIQUID_TESTNET_TRADING_ENABLED=0
export HYPERLIQUID_SIGNER_BROADCAST_ENABLED=0

docker compose exec -T trading-app-php php bin/console dbal:run-sql \
  "INSERT INTO hyperliquid_testnet_kill_switch_state (scope, tripped, reason, audit_context, tripped_at, updated_at) VALUES ('hyperliquid_testnet', TRUE, 'operator_immediate_rollback', '{}'::jsonb, NOW(), NOW()) ON CONFLICT (scope) DO UPDATE SET tripped = TRUE, reason = 'operator_immediate_rollback', updated_at = NOW()"

docker compose up -d --no-deps --force-recreate trading-app-php
docker compose --profile hyperliquid-testnet up -d --no-deps --force-recreate hyperliquid-signer
docker compose --profile hyperliquid-testnet stop hyperliquid-signer
docker compose restart trading-app-messenger-trading
```

Retablir aussi la config effective fail-closed des que possible :
`dry_run=true`, `live_enabled=false`, `demo_testnet_write_enabled=false`,
`kill_switch_enabled=true`, sans jamais changer `mainnet_write_enabled=false`.
Ne pas effacer les plans, audits, rapports, lignes DB ou markers : conserver les
preuves redacted jusqu'a cloture de l'incident.

## Critere de cloture

La tentative n'est cloturee que lorsque l'account address montre le symbole
flat, aucun ordre ouvert, la protection ou compensation est reconciliee par
identifiants, les trois gates d'environnement sont a `0`, le sidecar est arrete
et les preuves redacted sont archivees. Tant qu'un point est inconnu, le statut
reste `ambiguous`, la quarantaine reste active et aucune autre tentative n'est
autorisee.
