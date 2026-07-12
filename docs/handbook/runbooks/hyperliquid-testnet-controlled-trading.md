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

## Creation et approbation de l'agent API dedie

Cette procedure utilise l'UI officielle Hyperliquid testnet. Le repository ne
fournit aucune commande de creation ou d'approbation d'agent ; ne pas en
inventer une et ne pas appeler `/exchange` a la main. Garder
`DEMO_TRADING_ENABLED=0`, `HYPERLIQUID_TESTNET_TRADING_ENABLED=0`,
`HYPERLIQUID_SIGNER_BROADCAST_ENABLED=0` et le sidecar arrete.

1. Ouvrir exclusivement [l'UI API officielle testnet](https://app.hyperliquid-testnet.xyz/API)
   et verifier le host avant de connecter le wallet du compte testnet dedie.
2. Verifier dans l'UI que l'adresse connectee est l'**account address** attendue.
   Pour un subaccount, utiliser son adresse comme account address ; l'agent
   reste approuve par le master account selon le modele Hyperliquid.
3. Creer un **named API wallet** avec un nom neuf reserve a ce processus. Ne pas
   reutiliser un agent ou un nom existant : Hyperliquid peut deregistrer/remplacer
   un agent du meme slot et son etat de nonce peut etre prune.
4. L'UI genere une private key et en derive une **agent address**. L'agent
   address est publique et distincte de l'account address. La private key agent
   est le secret de signature ; elle n'est ni l'adresse du compte ni la private
   key du compte principal.
5. Conserver la private key dans un enregistrement secret `pending`, hors Git et
   hors runtime. Ne pas la copier dans une commande, une variable shell, un
   fichier `.env`, le presse-papiers partage ou un terminal. Ne pas encore
   l'injecter au sidecar.
6. Approuver l'agent dans l'UI avec le wallet du compte, sur **Testnet**, puis
   relever uniquement account address, agent address, nom et expiration.

Le modele agent, les nonces par signer et le risque de reutilisation sont
decrits dans [Nonces and API wallets](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/nonces-and-api-wallets).
L'action officielle sous-jacente est `approveAgent`, documentee dans
[Approve an API wallet](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/exchange-endpoint#approve-an-api-wallet).

Verifier l'approbation **avant toute injection du secret**. Les deux invites
ci-dessous ne demandent que des adresses publiques et ne les inscrivent pas
dans l'historique shell :

```bash
set +x
read -r -p 'Dedicated testnet account address: ' HL_TESTNET_ACCOUNT_ADDRESS
read -r -p 'Derived testnet agent address: ' HL_TESTNET_AGENT_ADDRESS
export HL_TESTNET_ACCOUNT_ADDRESS HL_TESTNET_AGENT_ADDRESS
export HL_INFO_URL='https://api.hyperliquid-testnet.xyz/info'

jq -en --arg account "$HL_TESTNET_ACCOUNT_ADDRESS" \
  --arg agent "$HL_TESTNET_AGENT_ADDRESS" '
  ($account | test("^0x[0-9a-fA-F]{40}$")) and
  ($agent | test("^0x[0-9a-fA-F]{40}$")) and
  (($account | ascii_downcase) != ($agent | ascii_downcase))
' >/dev/null

EXTRA_AGENTS_JSON="$(
  curl --fail --silent --show-error \
    -H 'Content-Type: application/json' \
    --data "$(jq -cn --arg user "$HL_TESTNET_ACCOUNT_ADDRESS" \
      '{type:"extraAgents", user:$user}')" \
    "$HL_INFO_URL"
)"

printf '%s' "$EXTRA_AGENTS_JSON" | jq \
  --arg agent "$HL_TESTNET_AGENT_ADDRESS" '
  [.[]
    | select((.address | ascii_downcase) == ($agent | ascii_downcase))
    | {address, name, validUntil}]
'

printf '%s' "$EXTRA_AGENTS_JSON" | jq -e \
  --arg agent "$HL_TESTNET_AGENT_ADDRESS" '
  [.[]
    | select((.address | ascii_downcase) == ($agent | ascii_downcase))
    | select((.validUntil | type) == "number")
    | select(.validUntil > (now * 1000))]
  | length == 1
' >/dev/null
```

La sortie filtree doit contenir exactement l'agent derive et une expiration
future. Une reponse vide, dupliquee, expiree ou mal formee bloque la procedure.
`extraAgents` est la preuve read-only utilisee par la readiness HL-012 ; elle ne
contient et ne valide aucune private key.

Configurer ensuite **uniquement les deux adresses publiques** dans
`HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` et
`HYPERLIQUID_TESTNET_AGENT_ADDRESS` du runtime PHP, recreer PHP sans demarrer le
sidecar, puis verifier que PHP a charge exactement ces valeurs publiques :

```bash
docker compose up -d --no-deps --force-recreate trading-app-php

HL_CONTAINER_ADDRESSES="$(
  docker compose exec -T trading-app-php php -r '
  echo json_encode([
      "account" => getenv("HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS"),
      "agent" => getenv("HYPERLIQUID_TESTNET_AGENT_ADDRESS"),
  ], JSON_THROW_ON_ERROR);
  '
)"

printf '%s' "$HL_CONTAINER_ADDRESSES" | jq -e \
  --arg account "$HL_TESTNET_ACCOUNT_ADDRESS" \
  --arg agent "$HL_TESTNET_AGENT_ADDRESS" '
  type == "object" and
  (.account | type) == "string" and
  (.agent | type) == "string" and
  .account == $account and
  .agent == $agent
' >/dev/null

HL_READINESS_OUTPUT="$(
  docker compose exec -T trading-app-php \
    php bin/console app:exchange:runtime-check hyperliquid perpetual
)"

for diagnostic in \
  'Exchange' \
  'Market type' \
  'Adapter' \
  'Provider bundle' \
  'Credentials' \
  'REST' \
  'Private WS' \
  'Live trading' \
  'Dry-run only' \
  'Network' \
  'Mainnet enabled' \
  'Testnet trading enabled' \
  'Readiness level' \
  'Readiness blocking errors' \
  'Readiness warnings' \
  'Mainnet write guard' \
  'Demo/testnet write guard' \
  'Signer configured' \
  'Signer/account relation' \
  'Nonce store' \
  'Collateral readable' \
  'WS/polling' \
  'Stop loss capability' \
  'Kill switch' \
  'Live allowed' \
  'Recommended dry_run' \
  'Schedule ready'
do
  count="$(printf '%s\n' "$HL_READINESS_OUTPUT" | awk \
    -v prefix="$diagnostic: " 'index($0, prefix) == 1 {n++} END {print n + 0}')"
  test "$count" -eq 1
done

printf '%s\n' "$HL_READINESS_OUTPUT" \
  | rg '^(Readiness level|Readiness blocking errors|Readiness warnings|Signer configured|Live allowed|Schedule ready): '

printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Exchange: hyperliquid'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Market type: perpetual'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Network: testnet'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Mainnet enabled: no'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Testnet trading enabled: no'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Signer configured: no'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Dry-run only: yes'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Live allowed: no'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Recommended dry_run: true'
printf '%s\n' "$HL_READINESS_OUTPUT" | rg -x 'Schedule ready: no'

if printf '%s\n' "$HL_READINESS_OUTPUT" | rg -q \
  'hyperliquid_(extra_agents|agent_wallet_trade_permission)'; then
  echo 'Agent trade permission is not proven; secret injection refused.' >&2
  exit 1
fi
```

Avec le sidecar encore arrete, d'autres blocages comme
`hyperliquid_signer_sidecar_not_ready` sont attendus. La requete directe
`extraAgents` est la preuve positive de permission trade. Le runtime-check doit
etre complet, lire exactement les memes adresses publiques et ne pas contredire
cette preuve par un warning `extra_agents` ou `agent_wallet_trade_permission`.
Seules ces conditions autorisent le passage de l'enregistrement secret
`pending` vers l'injection sidecar par le mecanisme local approuve. Ne jamais
utiliser `export`, `read`, `docker compose run -e`, `--env` ou une ligne de
commande pour la private key.

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

### Reconciliation `/info` read-only

Les commandes suivantes utilisent exclusivement l'endpoint testnet et
l'**account address**, jamais l'agent address. Elles suivent les surfaces
officielles [clearinghouseState](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint/perpetuals#retrieve-users-perpetuals-account-summary),
[frontendOpenOrders](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint#retrieve-a-users-open-orders-with-additional-frontend-info)
et [orderStatus](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint#query-order-status-by-oid-or-cloid).
Elles n'affichent que coin, taille, oid, cloid, statut et flags utiles.

Initialiser les identifiants publics. `HL_COIN` est le coin Hyperliquid, par
exemple celui lie au symbole du plan. `HL_ENTRY_OID` vient de la sortie smoke et
`HL_CLIENT_ORDER_ID` est le `client_order_id` public exact du plan et de la
sortie acceptee.

```bash
set +x
export HL_INFO_URL='https://api.hyperliquid-testnet.xyz/info'
: "${HL_TESTNET_ACCOUNT_ADDRESS:?set the dedicated account address}"
: "${HL_COIN:?set the exact Hyperliquid coin from the approved plan}"
: "${HL_ENTRY_OID:?set the exact exchange OID returned by the smoke command}"
: "${HL_CLIENT_ORDER_ID:?set the exact public client_order_id from the plan}"

jq -en --arg account "$HL_TESTNET_ACCOUNT_ADDRESS" --arg coin "$HL_COIN" '
  ($account | test("^0x[0-9a-fA-F]{40}$")) and
  ($coin | test("^[A-Z0-9][A-Z0-9_-]{0,31}$"))
' >/dev/null

HL_ENTRY_CLOID="$(
  printf '%s' "$HL_CLIENT_ORDER_ID" | php -r '
  $candidate = trim(stream_get_contents(STDIN));
  if (preg_match("/^0x[0-9a-fA-F]{32}$/", $candidate) === 1) {
      echo strtolower($candidate);
      exit(0);
  }
  echo "0x" . substr(hash("sha256", $candidate), 0, 32);
  '
)"
export HL_ENTRY_CLOID

printf '%s' "$HL_ENTRY_CLOID" \
  | jq -R -e 'test("^0x[0-9a-f]{32}$")' >/dev/null

hl_info() {
  curl --fail --silent --show-error \
    -H 'Content-Type: application/json' \
    --data "$1" \
    "$HL_INFO_URL"
}
```

Cette derivation est exactement celle de `HyperliquidActionFactory::cloid` : un
`client_order_id` deja au format `0x` + 32 hex est preserve puis lower-case ;
sinon le cloid vaut `0x` suivi des 32 premiers caracteres hexadecimaux
lower-case du SHA-256 de `trim(client_order_id)`. Elle ne lit aucun secret.

Verifier les positions non nulles du coin, puis exiger un resultat vide pour
declarer le symbole flat :

```bash
HL_POSITIONS_JSON="$(hl_info "$(
  jq -cn --arg user "$HL_TESTNET_ACCOUNT_ADDRESS" \
    '{type:"clearinghouseState", user:$user}'
)")"

printf '%s' "$HL_POSITIONS_JSON" | jq -e '
  type == "object" and
  (.assetPositions | type) == "array" and
  all(.assetPositions[];
    type == "object" and
    (.position | type) == "object" and
    (.position.coin | type) == "string" and
    (.position.szi | type) == "string" and
    (.position.szi | test("^-?(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$")) and
    (try (.position.szi | tonumber) catch null) != null)
' >/dev/null

HL_OPEN_POSITIONS="$(
  printf '%s' "$HL_POSITIONS_JSON" | jq --arg coin "$HL_COIN" '
    [.assetPositions[]?.position
      | select(.coin == $coin)
      | select((.szi | tonumber) != 0)
      | {coin, size:.szi}]
  '
)"
printf '%s\n' "$HL_OPEN_POSITIONS"
printf '%s' "$HL_OPEN_POSITIONS" | jq -e 'length == 0' >/dev/null
```

Verifier les ordres ouverts du meme coin, puis exiger un resultat vide :

```bash
HL_ORDERS_JSON="$(hl_info "$(
  jq -cn --arg user "$HL_TESTNET_ACCOUNT_ADDRESS" \
    '{type:"frontendOpenOrders", user:$user}'
)")"

printf '%s' "$HL_ORDERS_JSON" | jq -e '
  type == "array" and
  all(.[];
    type == "object" and
    has("coin") and (.coin | type) == "string" and
    has("oid") and (.oid | type) == "number" and (.oid | floor) == .oid and .oid > 0 and
    has("sz") and (.sz | type) == "string" and
      (.sz | test("^(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$")) and
      (try (.sz | tonumber) catch null) != null and
    has("cloid") and
      ((.cloid | type) == "null" or
       ((.cloid | type) == "string" and
        (.cloid | test("^0x[0-9a-fA-F]{32}$")))))
' >/dev/null

HL_OPEN_ORDERS="$(
  printf '%s' "$HL_ORDERS_JSON" | jq --arg coin "$HL_COIN" '
    [.[]
      | select(.coin == $coin)
      | {coin, oid, cloid, size:.sz, reduceOnly, isTrigger}]
  '
)"
printf '%s\n' "$HL_OPEN_ORDERS"
printf '%s' "$HL_OPEN_ORDERS" | jq -e 'length == 0' >/dev/null
```

Interroger `orderStatus` sans convertir un OID u64 en float et sans accepter un
identifiant approximatif :

```bash
hl_order_status() {
  local identifier="$1" payload
  if [[ "$identifier" =~ ^[1-9][0-9]*$ ]]; then
    payload="$(printf '{"type":"orderStatus","user":"%s","oid":%s}' \
      "$HL_TESTNET_ACCOUNT_ADDRESS" "$identifier")"
  elif [[ "$identifier" =~ ^0x[0-9a-fA-F]{32}$ ]]; then
    payload="$(jq -cn --arg user "$HL_TESTNET_ACCOUNT_ADDRESS" \
      --arg oid "$identifier" '{type:"orderStatus", user:$user, oid:$oid}')"
  else
    echo 'Invalid exact OID/cloid; lookup refused.' >&2
    return 2
  fi
  hl_info "$payload"
}

HL_OID_STATUS_JSON="$(hl_order_status "$HL_ENTRY_OID")"
HL_CLOID_STATUS_JSON="$(hl_order_status "$HL_ENTRY_CLOID")"

for status_json in "$HL_OID_STATUS_JSON" "$HL_CLOID_STATUS_JSON"; do
  printf '%s' "$status_json" | jq '
    if .status == "order" then
      {
        lookupStatus:.status,
        oid:.order.order.oid,
        cloid:.order.order.cloid,
        orderStatus:.order.status,
        size:.order.order.sz,
        originalSize:.order.order.origSz,
        reduceOnly:.order.order.reduceOnly
      }
    else
      {lookupStatus:.status}
    end
  '
done

for status_json in "$HL_OID_STATUS_JSON" "$HL_CLOID_STATUS_JSON"; do
  printf '%s' "$status_json" | jq -e \
    --arg oid "$HL_ENTRY_OID" --arg cloid "$HL_ENTRY_CLOID" '
    .status == "order" and
    ((.order.order.oid | tostring) == $oid) and
    ((.order.order.cloid | ascii_downcase) == ($cloid | ascii_downcase))
  ' >/dev/null
done

HL_OID_STATUS="$(printf '%s' "$HL_OID_STATUS_JSON" | jq -r '.order.status')"
HL_CLOID_STATUS="$(printf '%s' "$HL_CLOID_STATUS_JSON" | jq -r '.order.status')"
test "$HL_OID_STATUS" = "$HL_CLOID_STATUS"
```

Les deux recherches doivent retourner le meme couple OID/cloid. `unknownOid`,
un cloid absent, un OID divergent ou des statuts incompatibles reste
`ambiguous`. Si le statut a change entre les deux appels, rejouer immediatement
les deux lookups une seule fois ; une seconde divergence reste `ambiguous`. Il
est interdit de chercher un autre ordre par coin, prix, taille ou fenetre
temporelle. La commande smoke ne sort pas les OID/cloid du SL ou du close ; ne
pas les inventer ni tenter de les retrouver par approximation.

### Escalade cancel/close

Le chemin applicatif gere le cancel/close, pas l'operateur :

- si l'entree est resting ou partiellement filled lors d'un incident, il annule
  par cloid puis confirme le lifecycle via `orderStatus` lie au cloid/oid ;
- s'il reste une exposition, il soumet un close reduce-only borne, puis confirme
  le close par les memes identifiants ;
- si la protection, l'annulation ou le close ne sont pas confirmes, il trip le
  kill switch durable et conserve la quarantaine.

Le seul point d'entree implemente est
`app:hyperliquid:testnet:smoke` : son execution port appelle la compensation
dans la meme tentative. Ne pas interrompre le processus pendant cette phase,
ne pas relancer le smoke et ne pas chercher une commande de compensation
separee, qui n'existe pas.

Apres retour `failed`/`ambiguous`, ordre encore `open`, position non nulle ou
ordre protecteur restant :

1. laisser la tentative originale terminer son cancel-by-cloid, sa
   reconciliation OID/cloid et, si necessaire, son close reduce-only ;
2. executer les trois controles read-only ci-dessus avec l'OID d'entree et le
   cloid d'entree derive de son `client_order_id` ;
3. si `length == 0` n'est pas prouve pour positions **et** open orders, executer
   immediatement « Rollback immediat sans deploiement » pour fermer les flags,
   trip la DB, arreter le signer et conserver la quarantaine ;
4. declarer l'etat `ambiguous`. Aucune commande repository ne peut reprendre la
   compensation apres coup ; une action UI/API d'urgence exige un transfert
   explicite d'ownership a l'incident commander et invalide la recette ;
5. apres toute action d'urgence, rejouer uniquement les memes assertions
   account/coin et les lookups OID/cloid exacts. La cloture exige position flat,
   zero ordre ouvert et identifiants terminaux coherents.

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
