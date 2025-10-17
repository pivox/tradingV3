#!/bin/bash

# Script de test pour la commande mtf:switch-off
# Usage: ./scripts/test_switch_off.sh

echo "=== Test de la commande MTF Switch Off ==="
echo ""

# Test 1: Mode dry-run avec quelques symboles
echo "Test 1: Mode dry-run avec quelques symboles"
echo "-------------------------------------------"
php bin/console mtf:switch-off --symbols="BTCUSDT,ETHUSDT,ADAUSDT" --duration="38640m" --reason="TOO_RECENT" --dry-run

echo ""
echo ""

# Test 2: Validation de différents formats de durée
echo "Test 2: Validation des formats de durée"
echo "---------------------------------------"
echo "Test avec 4h:"
php bin/console mtf:switch-off --symbols="TEST1" --duration="4h" --dry-run --no-interaction

echo ""
echo "Test avec 1d:"
php bin/console mtf:switch-off --symbols="TEST2" --duration="1d" --dry-run --no-interaction

echo ""
echo "Test avec 38640m:"
php bin/console mtf:switch-off --symbols="TEST3" --duration="38640m" --dry-run --no-interaction

echo ""
echo "=== Tests terminés ==="
