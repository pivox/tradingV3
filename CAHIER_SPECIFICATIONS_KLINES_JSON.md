# Cahier de Spécifications Détaillé - Insertion Klines JSON PostgreSQL

## 1. Objectif & Portée

### Objectif
Ingérer des bougies (klines) BitMart dans la table `klines` directement en SQL via une fonction unique recevant un tableau JSONB, en remplacement de la méthode actuelle `KlineRepository::upsertKlines()` qui utilise Doctrine ORM.

### Portée
- **Ingestion idempotente** : Pas de doublons grâce à la contrainte unique `(symbol, timeframe, open_time)`
- **Conversion type-safe** : ISO 8601 → `timestamptz` ; décimaux → `numeric(24,12)`
- **Compatibilité REST et WS** : Champ `source` pour tracer l'origine des données
- **Performance robuste** : Batches 200–10 000 lignes avec latence < 100ms
- **Intégration MTF** : Compatible avec `MtfRunService` et les workflows existants
- **Journalisation & observabilité** : Logs structurés pour monitoring

## 2. Modèle de Données (Table Existante)

### Table Cible
```sql
CREATE TABLE klines (
    id          BIGSERIAL PRIMARY KEY,
    symbol      VARCHAR(50) NOT NULL,
    timeframe   VARCHAR(10) NOT NULL,
    open_time   TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    open_price  NUMERIC(24, 12) NOT NULL,
    high_price  NUMERIC(24, 12) NOT NULL,
    low_price   NUMERIC(24, 12) NOT NULL,
    close_price NUMERIC(24, 12) NOT NULL,
    volume      NUMERIC(28, 12),
    source      VARCHAR(20) DEFAULT 'REST'::TEXT NOT NULL,
    inserted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at  TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL
);

-- Index existants
CREATE INDEX idx_klines_symbol_tf ON klines (symbol, timeframe);
CREATE INDEX idx_klines_open_time ON klines (open_time);
CREATE UNIQUE INDEX ux_klines_symbol_tf_open ON klines (symbol, timeframe, open_time);
```

### Clé d'Unicité
`(symbol, timeframe, open_time)` = base de l'idempotence

## 3. Contrat d'Entrée (Payload JSON)

### 3.1. Structure Attendue
```json
[
  {
    "symbol": "BTCUSDT",
    "timeframe": "15m",
    "open_time": "2025-10-14T09:45:00Z",
    "open_price": "111954.0",
    "high_price": "113318.2",
    "low_price": "111892.5",
    "close_price": "113079.2",
    "volume": "123.456",
    "source": "REST"
  }
]
```

### 3.2. Règles de Validation (Hard)
- **symbol** : Non vide, ≤ 50 chars, format `[A-Z0-9_]+`
- **timeframe** : Un des `{'4h','1h','15m','5m','1m'}` (validation applicative)
- **open_time** : ISO 8601 avec timezone (converti en `timestamptz`)
- **open/high/low/close** : Numériques positifs, précision conforme aux colonnes
- **volume** : Numérique ≥ 0 (nullable côté payload ; NULL accepté en SQL)
- **source** : `REST` (défaut) ou `WS`

### 3.3. Règles Métier (Soft)
- `high_price ≥ max(open_price, close_price)`
- `low_price ≤ min(open_price, close_price)`
- Cohérence OHLC (validation côté app ou CHECK SQL)

## 4. Fonction d'Ingestion SQL

### 4.1. Spécification
- **Nom** : `ingest_klines_json(p_payload jsonb) RETURNS void`
- **Rôle** : Insérer en masse un batch de bougies depuis un tableau JSONB
- **Propriété** : Idempotente (`ON CONFLICT DO NOTHING`)

### 4.2. Implémentation
```sql
CREATE OR REPLACE FUNCTION ingest_klines_json(p_payload jsonb)
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
  INSERT INTO klines (
    symbol, timeframe, open_time,
    open_price, high_price, low_price, close_price, volume,
    source, inserted_at, updated_at
  )
  SELECT
    t.symbol,
    t.timeframe,
    (t.open_time)::timestamptz,
    (t.open_price)::numeric,
    (t.high_price)::numeric,
    (t.low_price)::numeric,
    (t.close_price)::numeric,
    (t.volume)::numeric,
    COALESCE(t.source, 'REST'),
    now(),
    now()
  FROM jsonb_to_recordset(p_payload) AS t(
    symbol text,
    timeframe text,
    open_time text,
    open_price text,
    high_price text,
    low_price text,
    close_price text,
    volume text,
    source text
  )
  ON CONFLICT (symbol, timeframe, open_time) DO NOTHING;
END;
$$;
```

## 5. Intégration avec le Système MTF

### 5.1. Flux d'Orchestration Séquentiel MTF

#### Workflow Actuel (MtfRunService)
```php
// Dans MtfRunService::run()
foreach ($symbols as $symbol) {
    $results[$symbol] = $this->mtfService->runForSymbol(
        $runId, $symbol, $now, $currentTf, $forceTimeframeCheck, $forceRun
    );
}
```

#### Workflow Proposé avec JSON
1. **Fetch BitMart** (respect 0,2s entre appels)
2. **Construire payload JSONB** (200 klines max par symbole/TF)
3. **Appeler** : `SELECT ingest_klines_json(:payload::jsonb)`
4. **Répéter par timeframe** (4h → 1h → 15m → 5m → 1m)
5. **Rafraîchir vues matérialisées** d'indicateurs (si delta)

### 5.2. Intégration avec MtfService

#### Dans MtfService::processTimeframe()
```php
// Remplacer la logique actuelle de détection des gaps
$missingChunks = $this->klineRepository->getMissingKlineChunks(
    $symbol, $timeframe->value, $startDate, $endDate, 500
);

if (!empty($missingChunks)) {
    // NOUVEAU: Utiliser la fonction JSON pour backfill
    $this->backfillKlinesWithJson($symbol, $timeframe, $missingChunks);
}
```

### 5.3. Nouveau Service d'Ingestion JSON

#### KlineJsonIngestionService
```php
<?php

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class KlineJsonIngestionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Ingère un batch de klines via la fonction SQL JSON
     */
    public function ingestKlinesBatch(array $klines): IngestionResult
    {
        $startTime = microtime(true);
        $payload = $this->buildJsonPayload($klines);
        
        try {
            $this->connection->beginTransaction();
            
            $this->connection->executeStatement(
                'SELECT ingest_klines_json(:payload::jsonb)',
                ['payload' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION)]
            );
            
            $this->connection->commit();
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logger->info('[KlineJsonIngestion] Batch ingested', [
                'count' => count($klines),
                'duration_ms' => round($duration, 2),
                'symbol' => $klines[0]['symbol'] ?? 'unknown',
                'timeframe' => $klines[0]['timeframe'] ?? 'unknown'
            ]);
            
            return new IngestionResult(
                count: count($klines),
                durationMs: $duration,
                success: true
            );
            
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            
            $this->logger->error('[KlineJsonIngestion] Batch failed', [
                'count' => count($klines),
                'error' => $e->getMessage(),
                'symbol' => $klines[0]['symbol'] ?? 'unknown'
            ]);
            
            throw $e;
        }
    }
    
    private function buildJsonPayload(array $klines): array
    {
        return array_map(function($kline) {
            return [
                'symbol' => $kline['symbol'],
                'timeframe' => $kline['timeframe'],
                'open_time' => $kline['open_time']->format('c'), // ISO 8601
                'open_price' => (string) $kline['open_price'],
                'high_price' => (string) $kline['high_price'],
                'low_price' => (string) $kline['low_price'],
                'close_price' => (string) $kline['close_price'],
                'volume' => $kline['volume'] ? (string) $kline['volume'] : null,
                'source' => $kline['source'] ?? 'REST'
            ];
        }, $klines);
    }
}
```

## 6. Gestion des Erreurs & Idempotence

### 6.1. Cas d'Erreurs en Entrée
- **Clé manquante ou format invalide** → Erreur SQL (type cast)
- **Action** : Valider le JSON côté app avant l'appel
- **Collision de clé** → Silencieusement ignoré (comportement voulu)
- **Action** : Rien à faire (idempotence)

### 6.2. Transactions
- 1 transaction par batch recommandé côté app
- La fonction ne gère pas explicitement la transaction
- Rollback automatique en cas d'erreur

## 7. Performance & Sizing

### 7.1. Métriques de Performance
- **200 lignes/payload** : 45–95 ms typique (local/SSD)
- **2 000–10 000 lignes/payload** : 0,3–1,8 s selon IO/CPU
- **Utiliser JSONB** pour les lots usuels
- **Réserver COPY + staging UNLOGGED** aux gros backfills (≥ 50k–100k lignes/batch)

### 7.2. Optimisations
- Garder `ON CONFLICT DO NOTHING` + index unique ⇒ idempotence à coût quasi nul
- Maintenir un batch size stable (2k–10k) si regroupement multi-contrats
- La contrainte dominante = latence API 0,2 s/appel (optimiser le nombre d'appels)

## 8. Sécurité & Conformité

### 8.1. Sécurité
- **Paramétrage via requête préparée** (éviter l'injection)
- **Quota / rate limiting** déjà géré côté BitMart (respect 0,2 s)
- **Logs** : Tracer symbol, timeframe, count(payload) et durée par batch

### 8.2. Conformité
- Respect des contraintes de données existantes
- Compatibilité avec les workflows MTF actuels
- Maintien de la traçabilité des sources

## 9. Observabilité & Monitoring

### 9.1. Métriques à Collecter par Batch
- `payload_count` (nb de bougies)
- `ingest_ms` (durée SQL)
- `db_rows_inserted` (différence avant/après)
- `duplicates_ignored` (optionnel : comparer payload_count vs rows_inserted)

### 9.2. Sondes Santé
- Latence médiane (P50/P95) de `ingest_klines_json`
- Échec conversion types (compter les exceptions côté app)

### 9.3. Alertes
- P95 > seuil (ex. > 500 ms pour 200 lignes)
- Retard de bougies (> 2 périodes TF vs horloge)

## 10. Tests & Validation

### 10.1. Tests Unitaires (Base)
- **Happy path** (200 lignes valides) → 200 inserts
- **Rejeu idempotent** (même payload) → 0 insert
- **Mix doublons/nouveaux** → insert uniquement les nouveaux
- **Volume NULL/absent** → inséré à NULL (OK)
- **source absent** → REST par défaut

### 10.2. Tests de Robustesse
- Horodatages limites (UTC sensible, fin/début d'heure/jour)
- Valeurs extrêmes `numeric(24,12)` (précision préservée)
- Payload vide `[]` → OK (0 insert, pas d'erreur)

### 10.3. Tests d'Intégration MTF
- Compatibilité avec `MtfRunService::run()`
- Performance vs méthode Doctrine actuelle
- Gestion des gaps dans les workflows MTF

## 11. Exemples d'Appels

### 11.1. SQL Direct
```sql
SELECT ingest_klines_json(
  '[
    {"symbol":"BTCUSDT","timeframe":"15m","open_time":"2025-10-14T09:45:00Z","open_price":"111954.0","high_price":"113318.2","low_price":"111892.5","close_price":"113079.2","volume":"123.456"},
    {"symbol":"BTCUSDT","timeframe":"15m","open_time":"2025-10-14T10:00:00Z","open_price":"113079.2","high_price":"113500.0","low_price":"112900.0","close_price":"113400.0","volume":"98.765","source":"WS"}
  ]'::jsonb
);
```

### 11.2. Doctrine DBAL (PHP/Symfony)
```php
$payload = json_encode($rows, JSON_PRESERVE_ZERO_FRACTION);
$conn->beginTransaction();
try {
    $conn->executeStatement(
        'SELECT ingest_klines_json(:payload::jsonb)',
        ['payload' => $payload]
    );
    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollBack();
    throw $e;
}
```

### 11.3. Intégration avec BitmartRestClient
```php
// Dans BitmartRestClient::fetchKlines()
$klines = $this->fetchKlinesFromApi($symbol, $timeframe, $limit);

// NOUVEAU: Utiliser l'ingestion JSON au lieu de KlineRepository
$jsonIngestionService = $this->container->get(KlineJsonIngestionService::class);
$result = $jsonIngestionService->ingestKlinesBatch($klines);

$this->logger->info('[BitmartRestClient] Klines ingested via JSON', [
    'symbol' => $symbol,
    'timeframe' => $timeframe->value,
    'count' => $result->count,
    'duration_ms' => $result->durationMs
]);
```

## 12. Migration & Déploiement

### 12.1. Plan de Migration
1. **Phase 1** : Déployer la fonction SQL `ingest_klines_json`
2. **Phase 2** : Créer `KlineJsonIngestionService`
3. **Phase 3** : Modifier `BitmartRestClient` pour utiliser le nouveau service
4. **Phase 4** : Adapter `MtfService` pour les backfills JSON
5. **Phase 5** : Tests de performance et validation
6. **Phase 6** : Déploiement en production avec fallback

### 12.2. Rollback Strategy
- Garder `KlineRepository::upsertKlines()` comme fallback
- Feature flag pour basculer entre les deux méthodes
- Monitoring des performances pour validation

## 13. Évolutions Futures

### 13.1. Optimisations Avancées
- **Staging + COPY** (haut débit) pour backfills massifs
- **CHECK constraints** pour verrouiller la cohérence OHLC
- **Partitionnement** par timeframe + mois
- **TimescaleDB hypertables** pour scalabilité temporelle

### 13.2. Fonctionnalités Avancées
- **Triggers** pour mettre à jour `updated_at` sur upsert différé
- **Continuous Aggregates** (TimescaleDB) pour indicateurs en quasi-temps réel
- **Compression** des données historiques

## 14. Risques & Mitigations

| Risque | Impact | Mitigation |
|--------|--------|------------|
| Erreur de format JSON (types) | Échec de batch | Valider côté app ; journaliser l'index fautif |
| Dérive de formats BitMart | Données corrompues | Tests contractuels réguliers (schéma JSON) |
| Perte de précision numeric | Calculs indicateurs biaisés | Conserver `numeric(24,12)` & `numeric(28,12)` |
| Verrous lors de gros volumes | Ralentissements | Utiliser staging + COPY pour backfills |
| Index bloat | Performance | VACUUM (AUTO) + maintenance périodique |

## 15. Critères d'Acceptation

### 15.1. Fonctionnels
- ✅ Rejouer le même payload n'ajoute aucune ligne
- ✅ Champs convertis exactement (types & precision)
- ✅ `source` absent → REST
- ✅ Aucune exception sur payloads valides de BitMart
- ✅ Compatibilité totale avec les workflows MTF existants

### 15.2. Performance
- ✅ 200 lignes ingérées < 100 ms median sur environnement local SSD
- ✅ 10 000 lignes ingérées < 2 s median
- ✅ Latence P95 < 500 ms pour batches standards
- ✅ Performance ≥ méthode Doctrine actuelle

### 15.3. Intégration
- ✅ Intégration transparente avec `MtfRunService`
- ✅ Gestion des gaps dans `MtfService::processTimeframe()`
- ✅ Logs structurés pour observabilité
- ✅ Fallback vers méthode Doctrine en cas d'erreur

## 16. Sources & Documentation

### 16.1. Documentation PostgreSQL
- [INSERT … ON CONFLICT (UPSERT)](https://www.postgresql.org/docs/current/sql-insert.html#SQL-ON-CONFLICT)
- [Fonctions JSON / jsonb_to_recordset](https://www.postgresql.org/docs/current/functions-json.html#FUNCTIONS-JSON-PROCESSING)
- [PL/pgSQL](https://www.postgresql.org/docs/current/plpgsql.html)

### 16.2. Code Existant Analysé
- `trading-app/src/Domain/Mtf/Service/MtfRunService.php`
- `trading-app/src/Infrastructure/Persistence/KlineRepository.php`
- `trading-app/src/Infrastructure/Http/BitmartRestClient.php`
- `trading-app/migrations/sql/ingest_klines_json.sql`

---

**Version** : 1.0  
**Date** : 2025-01-15  
**Auteur** : Assistant IA  
**Statut** : Spécification détaillée pour implémentation
