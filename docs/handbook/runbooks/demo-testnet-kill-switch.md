# Demo/Testnet Kill Switch Runbook

## Objectif

COMMON-004 ajoute un gate commun pour toute tentative mutative OKX demo ou
Hyperliquid testnet. Le gate doit etre appele avant un futur envoi d'ordre
`dry_run=false` en environnement fictif.

Il ne rend pas le mainnet disponible. Une tentative mainnet reste bloquee meme si
tous les switches demo/testnet sont ouverts.

## Switches

Les switches sont fail-closed par defaut :

| Variable | Defaut | Effet |
|---|---:|---|
| `DEMO_TRADING_ENABLED` | `0` | Gate global. Si `0`, toute tentative demo/testnet est bloquee. |
| `OKX_DEMO_TRADING_ENABLED` | `0` | Gate OKX demo. Si `0`, OKX/demo est bloque. |
| `HYPERLIQUID_TESTNET_TRADING_ENABLED` | `0` | Gate Hyperliquid testnet. Si `0`, Hyperliquid/testnet est bloque. |
| `trading.execution.kill_switch_enabled` | `true` | Kill switch de config effective. Si `true`, la tentative est bloquee. |

Pour autoriser une future tentative demo/testnet, il faut donc :

```bash
DEMO_TRADING_ENABLED=1
OKX_DEMO_TRADING_ENABLED=1              # OKX demo uniquement
HYPERLIQUID_TESTNET_TRADING_ENABLED=1   # Hyperliquid testnet uniquement
```

et une config effective avec :

```yaml
trading:
  execution:
    mainnet_write_enabled: false
    demo_testnet_write_enabled: true
    kill_switch_enabled: false
```

Ces valeurs ne suffisent pas seules. La policy exige aussi une requete concrete :
whitelist symbole ou marche, notional plafonne, notional demande, client order id
auditable et stop loss present.

## Audit obligatoire

`DemoTradingKillSwitchService` produit une entree d'audit pour chaque tentative :

- `exchange`
- `environment`
- `mode`
- `profile`
- `symbol`
- `market`
- `notional`
- `client_order_id`
- `action`
- `allowed`
- `outcome`
- `reasons`
- `correlation_ids`
- `safety`
- `audit_context`

Les champs sensibles de `audit_context` sont redacted. Ne jamais placer de payload
REST brut, signature, private key, token, cookie ou secret dans l'audit.

Si l'audit ne peut pas etre ecrit, le service retourne une decision bloquee avec
`audit_failed`. Une mutation ne doit jamais continuer sans audit ecrit.

## Rollback immediat

Pour stopper toute experimentation demo/testnet sans redeploy applicatif :

```bash
DEMO_TRADING_ENABLED=0
OKX_DEMO_TRADING_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
```

Puis redemarrer les processus qui lisent l'environnement si necessaire :

```bash
docker-compose restart trading-app-php
docker-compose restart trading-app-messenger-trading
```

Rollback de config effective :

```yaml
trading:
  execution:
    demo_testnet_write_enabled: false
    kill_switch_enabled: true
```

## Raisons de blocage attendues

| Raison | Interpretation |
|---|---|
| `demo_trading_disabled` | Gate global `DEMO_TRADING_ENABLED=0`. |
| `okx_demo_trading_disabled` | Gate OKX demo ferme. |
| `hyperliquid_testnet_trading_disabled` | Gate Hyperliquid testnet ferme. |
| `effective_kill_switch_enabled` | Config effective encore en kill switch. |
| `mainnet_write_forbidden` | Tentative mainnet mutative interdite. |
| `audit_failed` | Audit non ecrit, mutation interdite. |

Les raisons issues du safety envelope restent visibles, notamment
`kill_switch_enabled`, `demo_testnet_write_not_enabled`, `max_notional_exceeded`
ou `stop_loss_missing`.
