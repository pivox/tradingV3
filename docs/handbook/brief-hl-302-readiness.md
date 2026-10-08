# Brief HL #302 — lever le fail-closed de la readiness Hyperliquid testnet

Date : 2026-10-08. Statut : brief d'implémentation, aucun code modifié.
Base : `.worktrees/paper-replay-scale` @ `d357de7b` ; implémenter sur
`feat/remove-bitmart` une fois posée. Mainnet hors scope et interdit.

## 1. Pourquoi c'est fail-closed (vérifié)

`src/Provider/Hyperliquid/EffectiveTradingHyperliquidMutationReadinessConfigSource.php`
l.9-15 : `current()` retourne `HyperliquidMutationReadinessConfig::failClosed()`
avec le commentaire : « This legacy profile-only source cannot provide the exact
mode/setup versions and side required by the canonical boundary. It must remain
fail-closed until its caller is migrated with #302 lineage. »

**« #302 lineage »** = Lot 2B de l'issue #302
(`docs/superpowers/plans/2026-08-08-issue-302-lot2b.md`,
`trading-app/docs/trading/canonical-trade-path-lot2a.md`) : une identité
canonique immuable par symbole — `mode_id@mode_version`, `setup_id@setup_version`,
`exchange`, `environment`, `side` — portée par `LineageContext`
(`src/Trading/Lineage/LineageContext.php`, `withSymbol/withDecision/withIntent`,
`assertTradeBoundary`, `isModern()`), jamais inférée d'un profil, d'un symbole
ou d'un `extra`. La config effective (`EffectiveTradingConfigResolver::resolve(
EffectiveTradingConfigRequest(modeId, modeVersion, setupId, setupVersion,
exchange, environment, side))`) refuse tout profil legacy
(`docs/handbook/technical/effective-trading-config-resolver.md`).

Le problème structurel : `HyperliquidMutationReadinessConfigSourceInterface::current()`
n'a **aucun paramètre**. `HyperliquidMutationReadinessProbe::current()` (l.45,
appel l.481) ne sait donc pas quelle identité résoudre, et un snapshot sans
identité n'existe pas dans le modèle #133/#302. D'où le `failClosed()`.

**Ce que `failClosed()` neutralise** (`HyperliquidMutationReadinessConfig` l.45-60) :
`profile=null`, `allowedSymbols=[]`, `allowedMarkets=[]`, `maxNotional=null`,
`dryRun=true`, `demoTestnetWriteEnabled=false`, `killSwitchEnabled=true`,
`configHash=null`. `authorizesTestnetMutation()` (l.27-40) exige l'inverse exact :
profil non vide, `configHash` = 64 hex, `!dryRun`, `!liveEnabled`,
`runtimeCheckRequired`, `!mainnetWriteEnabled`, `demoTestnetWriteEnabled`,
`!killSwitchEnabled`, `requireStopLoss`.

**Effet sur la gate** (`HyperliquidMutationReadinessGate::blockingReasons`,
l.18-50, 28 raisons). Six viennent directement de la source :
`demo_testnet_write_guard_not_ready` (probe l.104-137 : `mutationEvidenceReady`
inclut `!$killSwitch` où `killSwitch = durableTrip || runtimeConfig.killSwitchEnabled`),
`kill_switch_enabled`, `effective_config_profile_required`,
`effective_config_hash_required`, `market_allow_list_required`,
`positive_max_notional_required`. Les 22 autres : exchange/marché/env testnet,
endpoint `https://api.hyperliquid-testnet.xyz`, `DEMO_TRADING_ENABLED=1`,
`HYPERLIQUID_TESTNET_TRADING_ENABLED=1`, niveau `demo_testnet_candidate`,
account/permissions read+trade/collateral lisibles, observabilité privée,
polling, `stop_loss_capability`, signer configuré et lié au compte, nonce store,
`mainnet_write_guard`, `HYPERLIQUID_MAINNET_ENABLED=0`.

## 2. Comment le port testnet est atteint aujourd'hui vs. demain

**Aujourd'hui** : uniquement `app:hyperliquid:testnet:smoke`
(`src/Command/HyperliquidTestnetSmokeCommand.php`) : fichier plan JSON
`schema_version: 1` (`HyperliquidTestnetOrderPlanFileDecoder`, SL plein volume
obligatoire l.117), `--confirm=CONFIRM_HYPERLIQUID_TESTNET_ONLY`,
`--readiness-decision=ready_for_demo_testnet_trading_attempt` (valeur littérale,
ne lit aucun artefact), `readiness->current()` + gate, puis
`ExecutionRequest::forPlan($plan, ExecutionMode::Live)` →
`HyperliquidTestnetExecutionPort::execute()`.

**`HyperliquidTestnetExecutionPort`** (1008 l.) enchaîne : identifiants opaques,
fingerprint du plan, `HyperliquidExecutionAttemptStoreInterface::claim()`
(idempotence durable, replay terminal, conflit ⇒ trip), `reportOrNull()` +
`initialReasons()` (l.466-486 : `blockingErrors` + gate), lock d'exécution
(`SymfonyLockHyperliquidExecutionLock`, FlockStore `var/lock`),
`DemoTradingKillSwitchService::evaluate()` (l.240) avec statut polling,
`HyperliquidLeveragePolicy`, preuve de marge ≤ 2 000 ms
(`MAX_MARGIN_EVIDENCE_AGE_MILLISECONDS`), distance de liquidation ≥ 3×
(`HyperliquidIsolatedLiquidationSolver` + `LiquidationGuard`), nonce
(`PersistentHyperliquidNonceManager`, scope compte/agent), action signée par le
sidecar (`HttpHyperliquidSignedActionClient`), compensation fail-closed
(`HyperliquidCompensationService`), trip durable
(`FilesystemFallbackHyperliquidKillSwitch`).

**Flux MTF** : `MtfTradingDecisionMessageHandler` → `TradingDecisionHandler` →
`TradeEntryService` → `ExecuteOrderPlan` → (API-first) `ExchangeExecutionService`
→ `ExchangeAdapterRegistry` → **`HyperliquidExchangeAdapter::placeOrder` l.154**,
tagué `app.exchange_adapter`, gardé seulement par
`HyperliquidConfig::assertTradingConfigured()` (env/network testnet, adresses).
**Ce chemin contourne tout l'enveloppe du port testnet** : pas de gate 28
conditions, pas de kill switch `DemoTradingKillSwitchService`, pas de store de
tentatives, pas de lock, pas de nonce manager, pas de preuve de marge, pas de
compensation. C'est un trou de sécurité à fermer dans le même lot (voir §5 Q2).

Le mapping `OrderPlanModel` (TradeEntry) → `OrderPlan` (TradingCore, avec
`ProtectionPlan`, `clientOrderId`, `idempotencyKey`, `configHash`, `metadata`)
existe : `src/TradingCore/OrderPlan/Mapper/LegacyOrderPlanMapper::fromLegacy()`
— aucun appelant aujourd'hui.

## 3. Forme cible : une readiness versionnée par identité

Source de vérité = la **couche environnement** de la config effective, déjà
hashée dans le snapshot (`EffectiveTradingConfigSnapshot::configHash`,
`executable`, `blockers`) :

- `config/trading/env/testnet.yaml` : `trading.execution.{dry_run, live_enabled,
  mainnet_write_enabled, demo_testnet_write_enabled, kill_switch_enabled,
  require_stop_loss}` (tous fail-closed aujourd'hui, `version:
  0.1.0-effective-config-demo-testnet`).
- `config/trading/runtime/env/testnet.yaml` : `allowed_symbols: [BTCUSDT,
  ETHUSDT]`, `allowed_markets: [perpetual]`, `max_notional: 250.0`, mêmes
  gates. Les deux fichiers se recouvrent : n'en garder qu'un comme source
  (recommandé : `config/trading/env/testnet.yaml`, car composé et hashé).

« Versionné » signifie : la **seule** façon d'ouvrir une fenêtre mutative est un
commit relu qui change ces valeurs pour `testnet` uniquement
(`dry_run=false`, `live_enabled=true`, `demo_testnet_write_enabled=true`,
`kill_switch_enabled=false`, `mainnet_write_enabled=false`, `require_stop_loss=true`,
allow-list et `max_notional` minimal) — exactement les points 5 du runbook
HL-012 « Gate de tentative mutative future ». Le `configHash` du snapshot change
avec elles, donc la preuve runtime-check et l'audit portent la version.
`config/trading/env/demo.yaml`, `mainnet.yaml`, `prod.yaml` restent fail-closed.
La campagne paper #132 a prouvé que le resolver produit des snapshots exécutables
avec hash pour les 6 identités `day_trading`/`micro_scalping`/`scalping` 1.1.0
(cf. `config_hash` dans `campaign-run-1/state-hl-2.json`) : la source canonique
est réalisable pour ces identités.

## 4. Fichiers à modifier (ordre)

1. **`src/Provider/Hyperliquid/HyperliquidMutationReadinessConfigSourceInterface.php`**
   — remplacer `current()` par `forIdentity(EffectiveTradingConfigRequest $request):
   HyperliquidMutationReadinessConfig` (ou accepter `LineageContext` et dériver
   la requête avec `exchange=hyperliquid`, `environment=testnet`).
2. **Nouveau `src/Provider/Hyperliquid/CanonicalEffectiveHyperliquidMutationReadinessConfigSource.php`**
   — `EffectiveTradingConfigResolver::resolve()` → si `!executable` ou blockers
   ⇒ `failClosed()` ; sinon mapper la couche `environment` + `runtime/env` vers
   `HyperliquidMutationReadinessConfig(profile = "{modeId}@{modeVersion}/{setupId}@{setupVersion}/{side}",
   allowedSymbols, allowedMarkets, maxNotional, dryRun, liveEnabled,
   runtimeCheckRequired=true, mainnetWriteEnabled, demoTestnetWriteEnabled,
   killSwitchEnabled, requireStopLoss, configHash = snapshot->configHash)`.
   Brique commune avec le brief OKX-010 (`EffectiveDemoTradingEnvelopeSource`).
3. **Garder `EffectiveTradingHyperliquidMutationReadinessConfigSource`** en
   fail-closed pour tout appel sans identité ; ne pas la supprimer tant qu'un
   appelant legacy existe (`HyperliquidRuntimeCheck` sans options).
4. **`src/Provider/Hyperliquid/HyperliquidMutationReadinessProbe.php`** —
   `current()` → `current(?EffectiveTradingConfigRequest $identity = null)` ;
   sans identité : `runtimeConfig()` l.478 retourne `failClosed()` + warning
   `canonical_identity_required`. Reporter `configProfile`/`configHash` du
   snapshot dans `ExchangeReadinessReport` (l.145-146).
5. **`src/Provider/Hyperliquid/HyperliquidRuntimeCheck.php`** et
   **`src/Command/ExchangeRuntimeCheckCommand.php`** — options
   `--mode-id/--mode-version/--setup-id/--setup-version/--side` (mêmes noms que
   `app:paper-market:runtime-check`), transmises à la probe ; sortie
   `Readiness level`, `Demo/testnet write guard`, `Kill switch`,
   `Config hash` (redacted = hash seulement).
6. **`src/Command/HyperliquidTestnetSmokeCommand.php` +
   `HyperliquidTestnetOrderPlanFileDecoder.php`** — le plan v1 doit porter
   l'identité canonique (nouveau bloc `identity` ou passage à `schema_version: 2`) ;
   le smoke résout la readiness **pour cette identité** et vérifie que
   `plan.configHash === snapshot.configHash`.
7. **`src/TradingCore/Execution/Hyperliquid/HyperliquidTestnetExecutionPort.php`**
   — `reportOrNull()` l.518 et `initialReasons()` l.466 : prendre l'identité
   depuis `OrderPlan` (`metadata['lineage']` ou champs dédiés) ; refuser
   (`hyperliquid_testnet_preflight_rejected`, raison `canonical_identity_missing`)
   sans identité ; comparer `plan->configHash` au hash résolu.
8. **Fermeture du trou adapter** (Q2) : soit `HyperliquidExchangeAdapter::placeOrder/
   cancelOrder/setLeverage` deviennent fail-closed (`hyperliquid_adapter_write_disabled`)
   et un nouvel adapter `HyperliquidTestnetPortExchangeAdapter` (tag
   `app.exchange_adapter`, remplace l'actuel dans le registre pour
   HYPERLIQUID/PERPETUAL) traduit `PlaceOrderRequest` + `metadata.lineage` en
   `OrderPlan` via `LegacyOrderPlanMapper` et appelle le port ; soit le flux MTF
   reste interdit pour HL et seul le smoke opérateur écrit (alors fail-closer
   l'adapter suffit).
9. **`config/services.yaml`** l.~295 : alias
   `HyperliquidMutationReadinessConfigSourceInterface` → source canonique ;
   enregistrer le nouvel adapter si retenu.
10. **Config** : `config/trading/env/testnet.yaml` reste fail-closed dans ce lot ;
    le commit « ouverture de fenêtre » est séparé et relu (décision versionnée).
11. **Docs** : `docs/handbook/technical/hyperliquid-testnet-readiness.md`
    (« Config effective / safety envelope »), runbook HL-012 (préreq. 7-8 et
    gate future : ajouter l'identité et le hash attendu), DEMO-005 → décision
    versionnée (utilisateur).

## 5. Gates qui doivent rester (ne pas affaiblir)

`HyperliquidConfig::assertTradingConfigured()` et `HyperliquidAgentSigner` (refus
mainnet) ; `HYPERLIQUID_MAINNET_ENABLED=0` immuable ; flags
`DEMO_TRADING_ENABLED` + `HYPERLIQUID_TESTNET_TRADING_ENABLED` ; sidecar
`hyperliquid-signer` (`docker-compose.yml` l.411-424, profile
`hyperliquid-testnet`, `HYPERLIQUID_SIGNER_BROADCAST_ENABLED=0` par défaut,
`vaultAddress=null`, la clé privée agent n'entre jamais dans PHP, PHP ne reçoit
que `HYPERLIQUID_SIGNER_BASE_URI` + `HYPERLIQUID_SIGNER_AUTH_TOKEN`) ; kill
switch durable (table `hyperliquid_testnet_kill_switch_state`, migration
`Version20260712120000`, marker `var/hyperliquid-testnet-execution.quarantine`,
jamais supprimé à la main → `app:hyperliquid:testnet:quarantine-recover`) ;
store de tentatives (`hyperliquid_testnet_execution_attempt`, migration
`Version20260712230000`) ; lock ; nonce manager ; preuve de marge/liquidation ;
compensation ; gate 28 conditions ; `DemoTradingKillSwitchService` + audit
`demo_trading_audit` avant mutation ; confirmation opérateur du smoke.

## 6. Plan de test (sans réseau, sans sidecar)

- `tests/Provider/Hyperliquid/HyperliquidReadinessRuntimeConfigTest.php` :
  conserver `testLegacyProfileOnlySourceFailsClosedWithoutCanonicalIdentity` et
  `testExactAuthorizationValuesAreRequired` ; ajouter pour la source canonique :
  mapping exact de `env/testnet.yaml` fail-closed ⇒ `authorizesTestnetMutation()=false` ;
  fixture `testnet` ouverte (fichier de test, pas le vrai) ⇒ `true` avec
  `configHash` = hash du snapshot ; `demo`/`mainnet` ⇒ toujours `false` même
  ouverts ; snapshot `executable=false` ⇒ `failClosed()` ; identité inconnue ⇒
  `failClosed()`.
- `HyperliquidMutationReadinessProbeTest.php` : sans identité ⇒ 6 raisons de
  source présentes ; avec identité + fixture ouverte + collaborateurs fake ⇒
  `demoTestnetWriteGuard=true`, `configHash` propagé ; trip durable ⇒
  `killSwitch=true` même si config ouverte.
- `HyperliquidMutationReadinessGateTest.php` : table des 28 raisons, dont le cas
  « config ouverte mais `DEMO_TRADING_ENABLED=0` » ⇒ bloqué.
- `HyperliquidTestnetExecutionPortTest.php` : plan sans identité ⇒ preflight
  rejeté ; `configHash` divergent ⇒ rejeté ; replay terminal inchangé ;
  `FakeHyperliquidSigner` uniquement.
- Nouveau test de l'adapter de remplacement / du fail-close de
  `HyperliquidExchangeAdapter::placeOrder` (`tests/Exchange/Adapter/`,
  `tests/Exchange/Contract/`).
- `tests/Command/ExchangeRuntimeCheckCommandTest.php` et un test du smoke avec
  plan v2 (identité) ⇒ refus si hash ≠.
- `php bin/phpunit`, `vendor/bin/phpstan analyse`, `bin/console lint:container`.

## 7. Questions ouvertes (décisions utilisateur, pas détails d'implémentation)

1. **Périmètre d'exécution** : HL-012 n'autorise qu'**une** tentative opérateur
   (compte flat, ownership exclusif, aucun autre processus). Brancher le flux
   MTF automatique sur le testnet est un changement de périmètre à assumer.
   Recommandé : lot A = smoke opérateur avec identité canonique ; lot B
   (séparé) = flux MTF.
2. **Trou de l'adapter** : `HyperliquidExchangeAdapter::placeOrder` est
   atteignable par le flux MTF sans enveloppe. Le fail-closer dès ce lot
   (recommandé) ou le router vers le port ?
3. **Décision versionnée** : quelles valeurs exactes dans
   `config/trading/env/testnet.yaml` (`max_notional`, allow-list), qui relit, et
   DEMO-005 est-il remplacé par un nouveau rapport
   `ready_for_demo_testnet_trading_attempt` (exige aussi #188 rejoué, R14-R16,
   rollback prouvé) ?
4. **Infra/credentials** (actions utilisateur, jamais dans Git) : master account
   testnet de rôle `user` financé, agent API dédié approuvé,
   `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS`, `HYPERLIQUID_TESTNET_AGENT_ADDRESS`,
   `HYPERLIQUID_SIGNER_AUTH_TOKEN`, clé privée agent dans le sidecar seulement,
   `HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz`, migrations
   `Version20260712120000`/`230000` appliquées, workers Messenger `mtf_decision`
   + `order_timeout`. Aucune ligne `HYPERLIQUID_*` n'existe dans `.env` du
   worktree aujourd'hui.
5. **Identités autorisées** : lesquelles des 6 identités paper (ou d'autres)
   sont éligibles au testnet ? La source canonique peut porter une allow-list
   d'identités en plus des symboles.
6. **Dépendance stratégie** : les specs n'ont déclenché aucun trade en paper sur
   24 h ; une fenêtre testnet ouverte via le flux MTF ne produira probablement
   rien tant que l'étape 3 (itération des profils) n'est pas faite. Le smoke
   opérateur, lui, force un plan explicite.
