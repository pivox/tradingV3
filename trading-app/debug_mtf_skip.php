<?php

/**
 * Script de diagnostic pour comprendre pourquoi mtf:run skippe.
 * 
 * Usage:
 * php debug_mtf_skip.php
 */

echo "=== Diagnostic MTF Skip ===\n\n";

echo "1. Commandes de diagnostic à exécuter:\n\n";

echo "   a) Vérifier les kill switches:\n";
echo "      docker-compose exec trading-app-php php bin/console mtf:switches\n\n";

echo "   b) Vérifier les contrats actifs:\n";
echo "      docker-compose exec trading-app-php php bin/console bitmart:fetch-contracts\n\n";

echo "   c) Vérifier les klines disponibles:\n";
echo "      docker-compose exec trading-app-php php bin/console bitmart:check-klines --symbol=BTCUSDT --timeframe=4h --limit=5\n\n";

echo "   d) Tester avec un symbole spécifique:\n";
echo "      docker-compose exec trading-app-php php bin/console mtf:run --symbols=BTCUSDT --force-run --dry-run=1\n\n";

echo "   e) Tester avec un timeframe spécifique:\n";
echo "      docker-compose exec trading-app-php php bin/console mtf:run --symbols=BTCUSDT --tf=4h --force-run --dry-run=1\n\n";

echo "2. Logs à vérifier:\n";
echo "   - Regarder les logs de l'application pour voir les raisons exactes des skips\n";
echo "   - Chercher les messages 'SKIPPED', 'GRACE_WINDOW', 'TOO_RECENT', etc.\n\n";

echo "3. Causes possibles de skip (même avec force-run):\n";
echo "   - Pas de klines en base de données (BACKFILL_NEEDED)\n";
echo "   - Erreurs de validation des signaux (signal = NONE)\n";
echo "   - Erreurs techniques (API, base de données, configuration)\n";
echo "   - Problèmes de configuration MTF\n\n";

echo "4. Solutions:\n";
echo "   - Remplir les klines manquantes avec backfill\n";
echo "   - Vérifier la configuration des signaux\n";
echo "   - Vérifier la connectivité API\n";
echo "   - Vérifier la configuration MTF\n\n";

echo "=== Fin du diagnostic ===\n";


