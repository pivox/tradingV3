# Trading Signal Detection Platform

Ce projet est une plateforme complète de détection de signaux de trading basée sur un stack multi-services comprenant :
- **Symfony 7 (PHP 8.3)** pour l'API principale
- **React + Vite** pour le frontend utilisateur
- **FastAPI (Python)** pour l'analyse des indicateurs techniques
- **MySQL 8** pour la base de données
- **Nginx** comme serveur web
- **Docker Compose** pour l'orchestration

## 📦 Structure du projet

```
.
├── symfony-app/       # Symfony 7 + API Platform
├── frontend/          # React + Vite
├── python-api/        # FastAPI pour les indicateurs
├── nginx/             # Config Nginx
├── docker-compose.yml
└── README.md
```

## 🚀 Lancer le projet

### Prérequis
- Docker & Docker Compose installés

### Étapes
1. Clonez le dépôt :
   ```bash
   git clone https://github.com/votre-utilisateur/votre-projet.git
   cd votre-projet
   ```

2. Lancez les containers :
   ```bash
   docker-compose up --build
   ```

3. Accédez aux services :
- Frontend : http://localhost:3000
- API Symfony : http://localhost:8080
- API Python (indicateurs) : http://localhost:8888

## 🔧 Services & Ports

| Service      | URL                   | Port local |
|-------------|-----------------------|------------|
| Frontend    | http://localhost:3000  | 3000       |
| API Symfony | http://localhost:8080  | 8080       |
| API Python  | http://localhost:8888  | 8888       |
| MySQL       | localhost              | 3306       |

## 🧩 Indicateurs disponibles (Python FastAPI)
- RSI
- MACD
- ADX
- Bollinger Bands
- EMA
- Candle Patterns
- Stochastic RSI
- Supertrend
- VWAP
- Volume

Chaque indicateur est exposé via une route REST dédiée.

## 📝 Notes importantes
- Le système permet de lancer des analyses par timeframe (1m, 5m, 15m, 1h, 4h) depuis `/api/frame{timeframe}/run` sur l'API Python.
- Les résultats sont unifiés pour un traitement plus simple côté Symfony ou Frontend.

---

Développé par Haythem 🚀