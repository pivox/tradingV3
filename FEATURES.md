# Documentation des fonctionnalités

## 🎯 Système MTF (Multi-Timeframe)
- Validation séquentielle: 4h → 1h → 15m → 5m → 1m
- Cache de validation avec expiration
- Kill switches pour arrêt d'urgence
- Audit complet des opérations

## 📊 Indicateurs Techniques
- RSI, MACD, EMA, Bollinger Bands
- ATR, VWAP, ADX, Ichimoku
- Conditions complexes avec opérateurs logiques
- Snapshots d'indicateurs pour historique

## 🚀 Post-Validation & Exécution
- EntryZone avec calcul ATR et VWAP
- PositionOpener avec sizing intelligent
- Sélection dynamique de timeframe (1m vs 5m)
- Garde-fous: stale data, slippage, liquidité

## 🎨 Interface Web
- Dashboard temps réel avec graphiques
- Pages spécialisées pour chaque fonctionnalité
- Monitoring des états MTF
- Gestion des configurations

## 🔧 Infrastructure
- Docker Compose multi-services
- Temporal pour orchestration
- Rate limiting prioritaire
- WebSocket temps réel
