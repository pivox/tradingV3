# Brief OKX-010 — OKX demo order write

Date : 2026-10-08. Statut : brief d'implémentation, aucun code modifié.
Base : `.worktrees/paper-replay-scale` @ `d357de7b` ; l'implémentation doit
partir de la branche `feat/remove-bitmart` une fois posée. Mainnet hors scope.

## 1. Ce qui existe déjà (vérifié dans le code)

**Deux chemins d'exécution coexistent dans TradeEntry.**

| Chemin | Sélection | Interface vers l'exchange | État OKX |
|---|---|---|---|
| Legacy `ExecutionBox` | `ExecuteOrderPlan::shouldUseApiFirstExecution()` retourne `false` (l.207-212 : contexte absent **et** `ExchangeContext::isLegacyDefault()` = BITMART/PERPETUAL) | `OrderProviderInterface` → `OkxOrderGateway` | `placeOrder` l.44, `cancelOrder` l.47, `cancelAllOrders` l.247, `submitLeverage` l.283 lèvent `okx_order_write_not_implemented` (l.296-299). Les lectures (`getOrder`, `getOpenOrders*`, `getOrderHistory`, `getOrderBookTop`) marchent. |
| API-first `ExchangeExecutionService` | contexte explicite ou non-legacy | `ExchangeAdapterRegistry` (tag `app.exchange_adapter`) → `OkxExchangeAdapter` | **Écriture déjà implémentée** : `placeOrder` l.158 (`/api/v5/trade/order` ou `/api/v5/trade/order-algo`), `cancelOrder` l.223 (`cancel-order` / `cancel-algos`), `setLeverage` l.312 (`account/set-leverage`), tous via `OkxRestClient::privatePost` après `OkxConfig::assertTradingConfigured()`. Lectures balance/positions/orders/fills/`getOrder`/`getOrderBookTop`/`reconcile`. |

Conclusion : OKX-010 n'est **pas** « écrire un client d'ordres », c'est
(a) rendre le chemin API-first le seul chemin OKX, (b) poser l'enveloppe
démo (kill switch + audit) qui n'existe aujourd'hui **que** sur le port
Hyperliquid testnet, (c) décider du sort des consommateurs legacy de
`OrderProviderInterface`.

**Payloads OKX v5 déjà couverts par `OkxActionFactory`** :
- `order()` : `instId, tdMode, clOrdId, side, posSide, ordType, sz, reduceOnly` + prix ; refuse tout TP/SL attaché (`attachedStopLossPrice`/`attachedTakeProfitPrice` → exception).
- `algoOrder()` : `ordType=conditional`, `slTriggerPx`/`slOrdPx` ou `tpTriggerPx`/`tpOrdPx`, `*TriggerPxType=mark`, `algoClOrdId`.
- `cancelOrder()` (`ordId` ou `clOrdId`), `cancelAlgo()` (`algoId` via préfixe `algo:` ou `algoClOrdId`).
- `setLeverage()` / `setLeverageRequests()` (isolated → deux requêtes `posSide` long/short).
- Pas d'amend (`supportsModifyOrder=false`). Pas d'endpoint supplémentaire à écrire.

**Capabilities déclarées** (`OkxExchangeAdapter::capabilities()` l.57-73) :
`supportsTriggerOrders=true`, `supportsReduceOnly=true`,
`supportsAttachedStopLossOnEntry=false`, `supportsAttachedTakeProfitOnEntry=false`,
`requiresSeparateLeverageSubmit=true`, `supportsPerSymbolLeverage=true`,
`supportsCancelByClientOrderId=true`, `supportsTestnet=true`.
Conséquence dans `ExchangeExecutionService::executeOnAdapter` : leverage posé
d'abord (l.145-148), entrée sans SL attaché, puis
`ProtectionEnforcer::enforceAfterEntryFill` place un **SL reduce-only
`STOP_LOSS`** (l.63-74) → `order-algo` conditional. `ProtectionEnforcer` ne pose
**aucun TP**. Le TP deux cibles vit dans `AttachTpSl` → `TpSlTwoTargetsService`
(l.509 : `getOrderProvider()->getOpenOrders/cancelOrder/placeOrder`) = chemin
legacy → lèverait `okx_order_write_not_implemented` sur OKX.

**Gates existantes autour d'une écriture OKX :**
1. `OkxConfig::assertTradingConfigured()` (l.117-124) ⇒ `assertPrivateConfigured()` ⇒
   `OKX_ENV=demo`, `OKX_SIMULATED_TRADING=1`, `OKX_LIVE_ENABLED=0`,
   `OKX_DEMO_API_KEY/SECRET/PASSPHRASE` non vides, `OKX_API_BASE_URI` vide ou
   exactement `https://eea.okx.com` (sinon `okx_private_rest_endpoint_not_allowed`),
   puis `OKX_DEMO_TRADING_ENABLED=1`.
2. `OkxRestClient::signedHeaders()` l.106 ajoute `x-simulated-trading: 1` en demo.
3. `OkxPrivateWebSocketEndpointGuard` : allowlist WS privé/business
   `wseeapap.okx.com` uniquement (observabilité privée, pas REST).
4. `DemoTradingKillSwitchService::evaluate(DemoTradingMutationAttempt)` :
   `DEMO_TRADING_ENABLED`, `OKX_DEMO_TRADING_ENABLED`, paire exchange/env
   supportée, `effectiveKillSwitchEnabled`, `client_order_id` requis, puis
   `DemoTradingSafetyPolicyEvaluator` (`demo_testnet_write_enabled`, kill switch,
   `require_stop_loss`, allowlist symboles/marchés, `max_notional` vs notional
   demandé, `stopLossPresent`), puis `ExchangePrivateObservabilityPolicy` sur le
   statut WS privé OKX. L'audit est écrit **avant** décision via
   `PsrDemoTradingAuditSink` → `monolog.logger.demo_trading_audit` ; un échec
   d'audit bloque (`audit_failed`). **Aujourd'hui seul
   `HyperliquidTestnetExecutionPort` l.240 l'appelle ; le chemin
   `ExchangeExecutionService`/`OkxExchangeAdapter` n'a ni kill switch ni audit.**
5. Couche environnement de la config effective : `config/trading/env/demo.yaml`
   (`dry_run: true, live_enabled: false, mainnet_write_enabled: false,
   demo_testnet_write_enabled: false, kill_switch_enabled: true,
   require_stop_loss: true`) et `config/trading/runtime/env/demo.yaml`
   (`allowed_symbols: [BTCUSDT, ETHUSDT]`, `allowed_markets: [perpetual]`,
   `max_notional: 250.0`). Rien ne lit ces valeurs pour construire un
   `DemoTradingMutationAttempt` OKX.
6. Readiness : `app:exchange:runtime-check okx perpetual`
   (`ExchangeRuntimeCheckCommand` l.386-410 → `OkxRuntimeCheck` →
   `ExchangeReadinessEvaluator`). `demoTestnetWriteGuard` OKX est dérivé de
   `OkxConfig` seulement (`$mainnetGuard`, `$demoHeaderGuard`), pas de la config
   effective. `OkxRuntimeCheck::check()` plafonne à `LocalDryRunReady` tant que
   `demoTestnetWriteEnabled=false`.

**`OkxDryRunExecutionPort`** (`src/TradingCore/Execution/Okx`, 546 l.) : preview
pur (`no_http`, `no_private_post`), rejette `ExecutionMode::Live`. Référencé
uniquement par son test. Il n'est câblé à rien parce que le chemin TradingCore
`ExecutionPortInterface` n'est branché à aucun flux applicatif (seul le smoke HL
l'utilise). Il reste utile comme oracle de preview dans les tests ; ne pas le
promouvoir en chemin d'écriture.

**Critères d'acceptation documentés** (`docs/handbook/technical/okx-demo-readiness.md`,
« Gates avant demo controlee ») pour `demo_testnet_enabled` :
`DEMO_TRADING_ENABLED=1` + `OKX_DEMO_TRADING_ENABLED=1` ;
`demo_testnet_write_enabled=true`, `mainnet_write_enabled=false`,
`kill_switch_enabled=false` ; requête avec `client_order_id`, symbole/marché
whitelist, notional > 0 sous plafond, SL présent ; policy d'observabilité
privée OK ; `x-simulated-trading: 1` garanti ; compensation fail-safe si SL
impossible ; audit écrit avant mutation. DEMO-005
(`docs/handbook/reports/pre-mutative-demo-readiness-decision.md`) est `blocked`
et « interdit OKX-010 en mode ordre demo tant que la decision reste blocked ».
Implémenter le code est permis ; **activer les flags ne l'est pas** sans une
décision versionnée `ready_for_demo_testnet_trading_attempt`.

## 2. Ordre d'implémentation (fichiers)

1. **`src/TradeEntry/Workflow/ExecuteOrderPlan.php` l.207-212** — après
   suppression Bitmart, `ExchangeContext::legacyDefault()` (BITMART) disparaît.
   Remplacer `shouldUseApiFirstExecution()` par : contexte requis, toujours
   API-first ; contexte `null` ⇒ `ExecutionResult` `STATUS_ERROR`
   `exchange_context_required`. Coordonner avec l'agent Bitmart : il touche
   `ExchangeContext::legacyDefault()/fromValues()/resolve()` (l.26-57),
   `ExchangeProviderRegistry` l.34, `ExchangeContextResolver` l.27,
   `MtfRunnerService::createContext()` l.1108, `ExchangeExecutionService`
   l.101/128/360-365 (`shouldRejectUnprotectableBitmartMarket`,
   `attachedStopLossRequested`). Ne pas inventer de nouveau défaut implicite.
2. **Nouveau `src/TradingCore/Execution/Safety/EffectiveDemoTradingEnvelopeSource.php`**
   (+ interface) — à partir d'un `LineageContext` (modeId/modeVersion/setupId/
   setupVersion/side) et du couple exchange/environment, appelle
   `EffectiveTradingConfigResolver::resolve(EffectiveTradingConfigRequest)` et
   extrait la couche environnement : `dry_run`, `live_enabled`,
   `mainnet_write_enabled`, `demo_testnet_write_enabled`, `kill_switch_enabled`,
   `require_stop_loss`, `allowed_symbols`, `allowed_markets`, `max_notional`,
   `configHash`, `executable`/`blockers`. Fail-closed si identité absente ou
   snapshot non exécutable. **Même brique que le brief HL #302 — à écrire une
   fois, partagée.**
3. **Nouveau `src/TradeEntry/Execution/DemoTradingGuardedExchangeAdapter.php`** —
   décorateur `ExchangeAdapterInterface` (+ `ExchangeRestSnapshotProviderInterface`
   pass-through) autour de `OkxExchangeAdapter` (`#[AsDecorator]`), qui pour
   `placeOrder`, `cancelOrder`, `setLeverage` construit un
   `DemoTradingMutationAttempt` (`exchange=OKX`, `environment=DEMO` dérivé de
   `OkxConfig::isDemo()`, `mode/profile/market/symbol/notional/clientOrderId`,
   `stopLossPresent` = `request->orderType===STOP_LOSS || protection à venir`,
   `privateObservabilityStatus` depuis `OkxPrivateWebSocketStatusStoreInterface`,
   enveloppe de l'étape 2), appelle `DemoTradingKillSwitchService::evaluate()`,
   retourne `PlaceOrderResult` rejeté (`accepted=false`, `status=REJECTED`,
   `raw.reasons`) ou `CancelOrderResult` refusé si non autorisé, sinon délègue.
   Un seul point d'étranglement couvre `ExchangeExecutionService`,
   `ProtectionEnforcer` et toute compensation. Le `PlaceOrderRequest` ne porte
   pas l'identité canonique : la passer via `metadata` de `PlaceOrderRequest`
   (`ExchangeExecutionService::entryRequest()` l.322 reçoit déjà
   `executionMetadata`) — ajouter `lineage` dans ce tableau depuis
   `OrderPlanModel::lineageContext`.
4. **`src/Provider/Okx/OkxOrderGateway.php`** — décision ouverte (§4 Q2). Si les
   consommateurs legacy restent (TP deux cibles, watchers), implémenter
   `placeOrder/cancelOrder/cancelAllOrders/submitLeverage` par délégation à
   l'adapter décoré : mapper `OrderSide`/`OrderType`/`$options`
   (`client_order_id`, `reduce_only`, `stop_price`, `open_type`) vers
   `PlaceOrderRequest`/`CancelOrderRequest` ; `cancelAllOrders` =
   `getOpenOrdersOrFail($symbol)` puis cancel unitaire (OKX n'a pas de
   cancel-all par instrument sans `cancel-batch-orders`, max 20/req — à
   ajouter dans `OkxActionFactory` si retenu). Sinon : laisser lever et
   désactiver `AttachTpSl`/`LimitFillWatchMessageHandler`/
   `CancelOrderMessageHandler` pour `Exchange::OKX` avec une raison explicite.
5. **`config/services.yaml`** — rien à ajouter pour le kill switch (déjà câblé
   ~l.378 avec les trois flags) ; déclarer le décorateur et la source
   d'enveloppe ; vérifier `app.http_client.exchange_guard.okx` (l.505) reste la
   seule sortie HTTP.
6. **`src/Command/ExchangeRuntimeCheckCommand.php` l.386-410 et
   `src/Provider/Okx/OkxRuntimeCheck.php`** — alimenter `demoTestnetWriteGuard`,
   `demoTestnetWriteEnabled`, `killSwitch`, `allowedSymbols`, `maxNotional`,
   `configHash`, `configProfile` depuis l'enveloppe de l'étape 2 (nouvelles
   options `--mode-id/--mode-version/--setup-id/--setup-version/--side`, même
   forme que `app:paper-market:runtime-check`). Sans identité : comportement
   actuel (plafond local dry-run).
7. **Docs** : `docs/handbook/technical/okx-demo-readiness.md` (statut, capability
   matrix « Demo write »), `docs/handbook/runbooks/demo-testnet-kill-switch.md`,
   et la décision versionnée remplaçant DEMO-005 (rédaction utilisateur).

## 3. Plan de test (sans réseau)

- `tests/Exchange/Adapter/OkxExchangeAdapterTest.php` : `FakeOkxClient`
  (`privatePost` routé par path, l.303-460) couvre déjà limit, SL algo,
  rejet algo, cancel normal/algo/clOrdId, set-leverage, refus sans
  `OKX_DEMO_TRADING_ENABLED`. Ajouter : `x-simulated-trading` présent sur
  chaque `privatePost` (étendre le fake pour capturer les headers via
  `OkxRestClientTest` plutôt que le fake adapter), refus si
  `OKX_API_BASE_URI=https://www.okx.com`.
- Nouveau `tests/TradeEntry/Execution/DemoTradingGuardedExchangeAdapterTest.php` :
  matrice kill switch (chaque raison de `switchReasons()` et de
  `demoTestnetWriteErrors()`), audit écrit avant délégation, `audit_failed`
  bloque, lecture pass-through non auditée, notional calculé = `quantity ×
  price` (ou `contractSize`) cohérent avec `max_notional`.
- `tests/TradeEntry/Execution/ExchangeExecutionServiceTest.php` : scénario OKX
  bout en bout avec `FakeExchangeAdapter` décoré → leverage, entrée, SL algo,
  `protection_confirmed`, et scénario « SL refusé par le kill switch »
  ⇒ `emergencyCloseAfterEntryRisk`.
- `tests/TradingCore/Execution/Safety/*` : aucun changement attendu ; ajouter
  un cas `EffectiveDemoTradingEnvelopeSource` ⇒ fail-closed sans identité, et
  mapping exact de `config/trading/env/demo.yaml` + `runtime/env/demo.yaml`.
- `tests/Command/ExchangeRuntimeCheckCommandTest.php` : `demo_testnet_candidate`
  seulement avec identité + enveloppe `demo_testnet_write_enabled=true` +
  flags ; sinon `local_dry_run`.
- `tests/Provider/Okx/OkxPrivateReadProviderTest.php` : si Q2 = délégation,
  tests `OkxOrderGateway::placeOrder/cancelOrder` → adapter.
- `OkxDryRunExecutionPortTest` reste l'oracle de preview : même plan ⇒ même
  payload que `OkxActionFactory::order()` (test de parité à ajouter).
- Suite complète : `php bin/phpunit`, `vendor/bin/phpstan analyse`,
  `bin/console lint:container`. Aucun test ne doit ouvrir de socket.

## 4. Questions ouvertes (décisions utilisateur)

1. **Défaut d'exchange post-Bitmart** : fail-closed sans contexte explicite
   (recommandé) ou nouveau défaut OKX ? Impacte `MtfRunnerService`,
   `ExchangeContext`, commandes `mtf:*`, contrôleurs.
2. **Protection démo OKX** : SL seul via `ProtectionEnforcer` (déjà câblé,
   conforme OKX-001 « SL immédiat obligatoire ») ou TP deux cibles via
   `TpSlTwoTargetsService` (chemin legacy à réimplémenter sur OKX) ? Recommandé :
   SL seul pour la première démo, TP ensuite via `algoOrder` `tp` dans le
   chemin API-first.
3. **Remplacement de DEMO-005** : qui rédige la décision versionnée ; elle exige
   aussi la recette #188 (orchestrateur) rejouée, le rollback schedule prouvé et
   le runtime-check redacted sur la stack cible.
4. **Credentials** (`.env`, action utilisateur) : renommer `OKX_API_*` en
   `OKX_DEMO_API_*`, poser `OKX_SIMULATED_TRADING=1`, retirer ou corriger
   `OKX_API_BASE_URI` (`https://www.okx.com` est refusé en demo). Ne jamais
   afficher les valeurs.
5. **Enveloppe** : valider `allowed_symbols`, `max_notional` (250 USDT actuel)
   et la liste de marchés pour la fenêtre démo ; décider si l'enveloppe vit dans
   `config/trading/env/demo.yaml` (couche composée, hashée) ou
   `config/trading/runtime/env/demo.yaml` (ces deux fichiers se recouvrent
   aujourd'hui — en garder un seul comme source).
6. **Observabilité privée** : `ExchangePrivateObservabilityPolicy` exige un
   statut WS privé OKX (`OkxPrivateWebSocketWorker`) ; lancer ce worker fait
   partie de la séquence démo ou accepter une policy documentée.
