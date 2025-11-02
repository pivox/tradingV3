#!/bin/bash

###############################################################################
# Script de test pour l'endpoint /api/ws-worker/balance
#
# Ce script teste l'endpoint qui reçoit les signaux de balance du ws-worker.
# Il simule l'envoi d'un signal avec signature HMAC.
#
# Usage:
#   ./scripts/test_balance_endpoint.sh
###############################################################################

set -e

# Configuration
ENDPOINT="http://localhost:8080/api/ws-worker/balance"
SHARED_SECRET="${WS_WORKER_SHARED_SECRET:-change-me}"

# Couleurs pour l'output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "=== Test de l'endpoint Balance Signal ==="
echo ""

# Test 1: Envoyer un signal valide avec signature
echo -e "${YELLOW}Test 1: Signal valide avec signature HMAC${NC}"

TIMESTAMP=$(date +%s%3N)
PAYLOAD=$(cat <<EOF
{
  "asset": "USDT",
  "available_balance": "10000.50",
  "frozen_balance": "500.00",
  "equity": "10500.50",
  "unrealized_pnl": "100.00",
  "position_deposit": "400.00",
  "bonus": "0.00",
  "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%S+00:00)",
  "trace_id": "test_$(date +%s)",
  "retry_count": 0,
  "payload_version": "1.0",
  "context": {
    "source": "bitmart_ws_worker",
    "raw_data": {}
  }
}
EOF
)

# Calculer la signature HMAC
SIGNATURE=$(echo -n "${TIMESTAMP}"$'\n'"${PAYLOAD}" | openssl dgst -sha256 -hmac "${SHARED_SECRET}" | awk '{print $2}')

echo "Timestamp: ${TIMESTAMP}"
echo "Signature: ${SIGNATURE}"
echo "Payload:"
echo "${PAYLOAD}" | jq .
echo ""

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${ENDPOINT}" \
  -H "Content-Type: application/json" \
  -H "X-WS-Worker-Timestamp: ${TIMESTAMP}" \
  -H "X-WS-Worker-Signature: ${SIGNATURE}" \
  -d "${PAYLOAD}")

HTTP_CODE=$(echo "${RESPONSE}" | tail -n1)
BODY=$(echo "${RESPONSE}" | sed '$d')

if [ "${HTTP_CODE}" -eq 202 ]; then
  echo -e "${GREEN}✓ Test 1 réussi: Signal accepté (HTTP ${HTTP_CODE})${NC}"
  echo "${BODY}" | jq .
else
  echo -e "${RED}✗ Test 1 échoué: HTTP ${HTTP_CODE}${NC}"
  echo "${BODY}" | jq .
fi
echo ""

# Test 2: Envoyer un signal sans signature (devrait échouer)
echo -e "${YELLOW}Test 2: Signal sans signature (devrait échouer)${NC}"

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${ENDPOINT}" \
  -H "Content-Type: application/json" \
  -d "${PAYLOAD}")

HTTP_CODE=$(echo "${RESPONSE}" | tail -n1)
BODY=$(echo "${RESPONSE}" | sed '$d')

if [ "${HTTP_CODE}" -eq 401 ]; then
  echo -e "${GREEN}✓ Test 2 réussi: Rejeté comme prévu (HTTP ${HTTP_CODE})${NC}"
  echo "${BODY}" | jq .
else
  echo -e "${RED}✗ Test 2 échoué: Devrait retourner 401, obtenu ${HTTP_CODE}${NC}"
  echo "${BODY}" | jq .
fi
echo ""

# Test 3: Envoyer un signal avec signature invalide (devrait échouer)
echo -e "${YELLOW}Test 3: Signal avec signature invalide (devrait échouer)${NC}"

TIMESTAMP=$(date +%s%3N)
INVALID_SIGNATURE="invalidSignature123456789"

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${ENDPOINT}" \
  -H "Content-Type: application/json" \
  -H "X-WS-Worker-Timestamp: ${TIMESTAMP}" \
  -H "X-WS-Worker-Signature: ${INVALID_SIGNATURE}" \
  -d "${PAYLOAD}")

HTTP_CODE=$(echo "${RESPONSE}" | tail -n1)
BODY=$(echo "${RESPONSE}" | sed '$d')

if [ "${HTTP_CODE}" -eq 401 ]; then
  echo -e "${GREEN}✓ Test 3 réussi: Rejeté comme prévu (HTTP ${HTTP_CODE})${NC}"
  echo "${BODY}" | jq .
else
  echo -e "${RED}✗ Test 3 échoué: Devrait retourner 401, obtenu ${HTTP_CODE}${NC}"
  echo "${BODY}" | jq .
fi
echo ""

# Test 4: Envoyer un payload invalide (asset non-USDT)
echo -e "${YELLOW}Test 4: Payload avec asset invalide (devrait échouer)${NC}"

TIMESTAMP=$(date +%s%3N)
INVALID_PAYLOAD=$(cat <<EOF
{
  "asset": "BTC",
  "available_balance": "1.5",
  "frozen_balance": "0.1",
  "equity": "1.6",
  "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%S+00:00)",
  "trace_id": "test_invalid_$(date +%s)",
  "retry_count": 0
}
EOF
)

SIGNATURE=$(echo -n "${TIMESTAMP}"$'\n'"${INVALID_PAYLOAD}" | openssl dgst -sha256 -hmac "${SHARED_SECRET}" | awk '{print $2}')

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${ENDPOINT}" \
  -H "Content-Type: application/json" \
  -H "X-WS-Worker-Timestamp: ${TIMESTAMP}" \
  -H "X-WS-Worker-Signature: ${SIGNATURE}" \
  -d "${INVALID_PAYLOAD}")

HTTP_CODE=$(echo "${RESPONSE}" | tail -n1)
BODY=$(echo "${RESPONSE}" | sed '$d')

if [ "${HTTP_CODE}" -eq 400 ]; then
  echo -e "${GREEN}✓ Test 4 réussi: Payload invalide rejeté (HTTP ${HTTP_CODE})${NC}"
  echo "${BODY}" | jq .
else
  echo -e "${RED}✗ Test 4 échoué: Devrait retourner 400, obtenu ${HTTP_CODE}${NC}"
  echo "${BODY}" | jq .
fi
echo ""

echo "=== Tests terminés ==="
echo ""
echo "Note: Consultez les logs de trading-app pour vérifier que les signaux sont bien traités."
echo "Commande: docker-compose logs -f trading-app | grep BalanceSignal"

