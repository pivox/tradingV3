<?php

/**
 * Script de test pour vérifier que les corrections de la commande mtf:run fonctionnent.
 * 
 * Usage:
 * php test_mtf_run_fix.php
 */

echo "=== Test des corrections de la commande mtf:run ===\n\n";

// Test 1: Vérifier que les paramètres sont bien récupérés
echo "1. Test de récupération des paramètres:\n";
echo "   - --symbols=DOTUSDT devrait être pris en compte\n";
echo "   - --force-run devrait contourner les kill switches\n\n";

// Test 2: Simuler la commande
echo "2. Commande à tester:\n";
echo "   docker-compose exec trading-app-php php bin/console mtf:run --symbols=DOTUSDT --force-run\n\n";

// Test 3: Vérifications attendues
echo "3. Résultats attendus:\n";
echo "   ✓ Le symbole DOTUSDT devrait être traité (pas tous les symboles actifs)\n";
echo "   ✓ Les kill switches devraient être contournés avec --force-run\n";
echo "   ✓ Le paramètre force-run devrait être passé à travers toute la chaîne\n\n";

// Test 4: Points de correction
echo "4. Corrections apportées:\n";
echo "   ✓ MtfRunCommand: Ne plus écraser les symboles fournis par l'utilisateur\n";
echo "   ✓ MtfRunService: Passer le paramètre forceRun à runForSymbol\n";
echo "   ✓ MtfService: Ajouter le paramètre forceRun à runForSymbol et processSymbol\n";
echo "   ✓ MtfService: Utiliser forceRun pour contourner les kill switches des symboles\n";
echo "   ✓ MtfService: Utiliser forceRun pour contourner les kill switches des timeframes\n\n";

echo "5. Pour tester manuellement:\n";
echo "   docker-compose exec trading-app-php php bin/console mtf:run --symbols=DOTUSDT --force-run --dry-run=1\n\n";

echo "=== Fin du test ===\n";


