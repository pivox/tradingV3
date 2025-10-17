# Exemples d'utilisation de l'endpoint /api/mtf/run

## Tests avec curl

### 1. Test basique (mode dry-run)

```bash
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{}'
```

### 2. Test avec symboles spécifiques

```bash
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT"]
  }'
```

### 3. Test avec un seul symbole

```bash
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"]
  }'
```

### 4. Test en mode production (création d'order plans)

```bash
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT"],
    "dry_run": false
  }'
```

### 5. Test avec force run (ignorer les kill switches)

```bash
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"],
    "force_run": true
  }'
```

### 6. Test complet avec tous les paramètres

```bash
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT", "ADAUSDT"],
    "dry_run": false,
    "force_run": true
  }'
```

## Tests avec le script bash

### 1. Test basique

```bash
./scripts/test-mtf-run.sh
```

### 2. Test avec URL personnalisée

```bash
./scripts/test-mtf-run.sh http://localhost:8082
```

### 3. Test avec symboles spécifiques

```bash
./scripts/test-mtf-run.sh http://localhost:8082 BTCUSDT,ETHUSDT
```

### 4. Test en mode production

```bash
./scripts/test-mtf-run.sh http://localhost:8082 BTCUSDT,ETHUSDT false
```

### 5. Test avec force run

```bash
./scripts/test-mtf-run.sh http://localhost:8082 BTCUSDT true true
```

## Tests avec la commande CLI

### 1. Test basique

```bash
php bin/console app:test-mtf-run
```

### 2. Test avec symboles spécifiques

```bash
php bin/console app:test-mtf-run --symbols=BTCUSDT,ETHUSDT
```

### 3. Test en mode dry-run

```bash
php bin/console app:test-mtf-run --dry-run
```

### 4. Test avec force run

```bash
php bin/console app:test-mtf-run --force-run
```

### 5. Test avec URL personnalisée

```bash
php bin/console app:test-mtf-run --url=http://localhost:8082
```

### 6. Test en mode verbeux

```bash
php bin/console app:test-mtf-run --verbose
```

## Tests avec Postman

### 1. Configuration de base

- **Méthode**: POST
- **URL**: `http://localhost:8082/api/mtf/run`
- **Headers**: 
  - `Content-Type: application/json`

### 2. Body (JSON)

#### Test basique
```json
{}
```

#### Test avec symboles
```json
{
  "symbols": ["BTCUSDT", "ETHUSDT"]
}
```

#### Test en mode production
```json
{
  "symbols": ["BTCUSDT", "ETHUSDT"],
  "dry_run": false
}
```

#### Test avec force run
```json
{
  "symbols": ["BTCUSDT"],
  "force_run": true
}
```

## Tests avec JavaScript/Node.js

### 1. Test basique

```javascript
const axios = require('axios');

async function testMtfRun() {
  try {
    const response = await axios.post('http://localhost:8082/api/mtf/run', {
      symbols: ['BTCUSDT', 'ETHUSDT'],
      dry_run: true,
      force_run: false
    });
    
    console.log('Status:', response.status);
    console.log('Data:', JSON.stringify(response.data, null, 2));
  } catch (error) {
    console.error('Error:', error.response?.data || error.message);
  }
}

testMtfRun();
```

### 2. Test avec gestion d'erreurs

```javascript
const axios = require('axios');

async function testMtfRunWithErrorHandling() {
  try {
    const response = await axios.post('http://localhost:8082/api/mtf/run', {
      symbols: ['BTCUSDT'],
      dry_run: true
    }, {
      timeout: 60000 // 60 secondes
    });
    
    if (response.status === 200) {
      const data = response.data;
      console.log('✅ Test réussi');
      console.log('Run ID:', data.data.summary.run_id);
      console.log('Temps d\'exécution:', data.data.summary.execution_time_seconds + 's');
      console.log('Taux de succès:', data.data.summary.success_rate + '%');
      
      // Afficher les résultats par symbole
      Object.entries(data.data.results).forEach(([symbol, result]) => {
        console.log(`${symbol}: ${result.status}`);
      });
    }
  } catch (error) {
    if (error.response) {
      console.error('❌ Erreur HTTP:', error.response.status);
      console.error('Message:', error.response.data.message);
    } else if (error.request) {
      console.error('❌ Pas de réponse du serveur');
    } else {
      console.error('❌ Erreur:', error.message);
    }
  }
}

testMtfRunWithErrorHandling();
```

## Tests avec Python

### 1. Test basique

```python
import requests
import json

def test_mtf_run():
    url = 'http://localhost:8082/api/mtf/run'
    data = {
        'symbols': ['BTCUSDT', 'ETHUSDT'],
        'dry_run': True,
        'force_run': False
    }
    
    try:
        response = requests.post(url, json=data, timeout=60)
        
        if response.status_code == 200:
            result = response.json()
            print('✅ Test réussi')
            print(f"Run ID: {result['data']['summary']['run_id']}")
            print(f"Temps d'exécution: {result['data']['summary']['execution_time_seconds']}s")
            print(f"Taux de succès: {result['data']['summary']['success_rate']}%")
            
            # Afficher les résultats par symbole
            for symbol, result_data in result['data']['results'].items():
                print(f"{symbol}: {result_data['status']}")
        else:
            print(f'❌ Erreur HTTP: {response.status_code}')
            print(f'Message: {response.json().get("message", "N/A")}')
            
    except requests.exceptions.RequestException as e:
        print(f'❌ Erreur de requête: {e}')

if __name__ == '__main__':
    test_mtf_run()
```

### 2. Test avec gestion d'erreurs avancée

```python
import requests
import json
import time

def test_mtf_run_advanced():
    url = 'http://localhost:8082/api/mtf/run'
    
    # Test avec différents paramètres
    test_cases = [
        {
            'name': 'Test basique',
            'data': {}
        },
        {
            'name': 'Test avec symboles',
            'data': {'symbols': ['BTCUSDT', 'ETHUSDT']}
        },
        {
            'name': 'Test en mode production',
            'data': {'symbols': ['BTCUSDT'], 'dry_run': False}
        },
        {
            'name': 'Test avec force run',
            'data': {'symbols': ['BTCUSDT'], 'force_run': True}
        }
    ]
    
    for test_case in test_cases:
        print(f"\n🧪 {test_case['name']}")
        print("-" * 50)
        
        try:
            start_time = time.time()
            response = requests.post(url, json=test_case['data'], timeout=60)
            end_time = time.time()
            
            if response.status_code == 200:
                result = response.json()
                summary = result['data']['summary']
                
                print(f"✅ Succès")
                print(f"Run ID: {summary['run_id']}")
                print(f"Temps d'exécution: {summary['execution_time_seconds']}s")
                print(f"Symboles traités: {summary['symbols_processed']}")
                print(f"Taux de succès: {summary['success_rate']}%")
                
                # Afficher les résultats par symbole
                for symbol, result_data in result['data']['results'].items():
                    status = result_data['status']
                    if status == 'success':
                        print(f"  {symbol}: ✅ {status}")
                    elif status == 'failed':
                        print(f"  {symbol}: ❌ {status} - {result_data.get('reason', 'N/A')}")
                    else:
                        print(f"  {symbol}: ⚠️  {status}")
                        
            else:
                print(f"❌ Erreur HTTP: {response.status_code}")
                error_data = response.json()
                print(f"Message: {error_data.get('message', 'N/A')}")
                
        except requests.exceptions.Timeout:
            print("❌ Timeout de la requête")
        except requests.exceptions.ConnectionError:
            print("❌ Erreur de connexion")
        except Exception as e:
            print(f"❌ Erreur: {e}")
        
        time.sleep(1)  # Pause entre les tests

if __name__ == '__main__':
    test_mtf_run_advanced()
```

## Tests de charge

### 1. Test avec Apache Bench

```bash
# Test avec 10 requêtes simultanées
ab -n 10 -c 2 -p test-data.json -T application/json http://localhost:8082/api/mtf/run
```

### 2. Test avec wrk

```bash
# Test avec 10 connexions, 10 threads, pendant 30 secondes
wrk -t10 -c10 -d30s -s test-script.lua http://localhost:8082/api/mtf/run
```

## Tests de validation

### 1. Test des paramètres invalides

```bash
# Test avec symboles invalides
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["INVALID_SYMBOL"]
  }'
```

### 2. Test avec paramètres manquants

```bash
# Test sans paramètres
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{}'
```

### 3. Test avec types incorrects

```bash
# Test avec dry_run comme string
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "dry_run": "true"
  }'
```

## Tests de performance

### 1. Test de temps de réponse

```bash
# Test avec mesure du temps
time curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT", "ADAUSDT", "SOLUSDT", "DOTUSDT"]
  }'
```

### 2. Test avec monitoring

```bash
# Test avec monitoring des ressources
top -p $(pgrep -f "php-fpm") &
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT"]
  }'
```

## Tests d'intégration

### 1. Test avec base de données

```bash
# Test avec vérification des données en base
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"],
    "dry_run": false
  }'

# Vérifier les order plans créés
curl -X GET http://localhost:8082/api/mtf/order-plans
```

### 2. Test avec logs

```bash
# Test avec monitoring des logs
tail -f /var/log/nginx/access.log &
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"]
  }'
```

## Tests de sécurité

### 1. Test avec authentification

```bash
# Test avec token d'authentification (si implémenté)
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "symbols": ["BTCUSDT"]
  }'
```

### 2. Test avec rate limiting

```bash
# Test avec plusieurs requêtes rapides
for i in {1..10}; do
  curl -X POST http://localhost:8082/api/mtf/run \
    -H "Content-Type: application/json" \
    -d '{
      "symbols": ["BTCUSDT"]
    }' &
done
wait
```

## Tests de récupération

### 1. Test avec kill switch OFF

```bash
# Désactiver le kill switch global
curl -X POST http://localhost:8082/api/mtf/switches/GLOBAL/toggle

# Tester l'endpoint
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"]
  }'

# Réactiver le kill switch
curl -X POST http://localhost:8082/api/mtf/switches/GLOBAL/toggle
```

### 2. Test avec base de données indisponible

```bash
# Arrêter la base de données
docker-compose stop trading-app-db

# Tester l'endpoint
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"]
  }'

# Redémarrer la base de données
docker-compose start trading-app-db
```

## Tests de monitoring

### 1. Test avec métriques

```bash
# Test avec collecte de métriques
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT"]
  }' \
  -w "Time: %{time_total}s\nSize: %{size_download} bytes\n"

# Vérifier les métriques
curl -X GET http://localhost:8082/api/mtf/metrics
```

### 2. Test avec health check

```bash
# Vérifier la santé du système
curl -X GET http://localhost:8082/api/mtf/health

# Tester l'endpoint
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"]
  }'

# Vérifier à nouveau la santé
curl -X GET http://localhost:8082/api/mtf/health
```




