#!/bin/bash

# Script de test final pour l'endpoint de revalidation des contrats
# Usage: ./scripts/test-revalidation-final.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

echo "=========================================="
echo "Test de l'endpoint de revalidation"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Un seul contrat
echo "Test 1: Un seul contrat (BTCUSDT)"
echo "----------------------------------------"
curl -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-01-15", "contracts": "BTCUSDT", "timeframe": "1h"}' | jq '.data.global_summary'
echo ""

# Test 2: Plusieurs contrats
echo "Test 2: Plusieurs contrats (BTCUSDT,ETHUSDT,ADAUSDT)"
echo "----------------------------------------"
curl -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-01-15", "contracts": "BTCUSDT,ETHUSDT,ADAUSDT", "timeframe": "1h"}' | jq '.data.global_summary'
echo ""

# Test 3: Timeframe différent
echo "Test 3: Timeframe 5m"
echo "----------------------------------------"
curl -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-01-15", "contracts": "BTCUSDT,ETHUSDT", "timeframe": "5m"}' | jq '.data.global_summary'
echo ""

# Test 4: Date différente
echo "Test 4: Date différente (2024-02-20)"
echo "----------------------------------------"
curl -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-02-20", "contracts": "SOLUSDT,MATICUSDT", "timeframe": "1h"}' | jq '.data.global_summary'
echo ""

# Test 5: Cas d'erreur - Date manquante
echo "Test 5: Cas d'erreur - Date manquante"
echo "----------------------------------------"
curl -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"contracts": "BTCUSDT", "timeframe": "1h"}' | jq '.error, .message'
echo ""

# Test 6: Cas d'erreur - Contrats manquants
echo "Test 6: Cas d'erreur - Contrats manquants"
echo "----------------------------------------"
curl -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-01-15", "timeframe": "1h"}' | jq '.error, .message'
echo ""

# Test 7: Cas d'erreur - Format de date invalide
echo "Test 7: Cas d'erreur - Format de date invalide"
echo "----------------------------------------"
curl -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "15/01/2024", "contracts": "BTCUSDT", "timeframe": "1h"}' | jq '.error, .message'
echo ""

# Test 8: Cas d'erreur - Contrats invalides
echo "Test 8: Cas d'erreur - Contrats invalides"
echo "----------------------------------------"
curl -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-01-15", "contracts": "INVALIDUSDT,FAKEUSDT", "timeframe": "1h"}' | jq '.error, .message'
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="

