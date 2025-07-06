#!/bin/bash

PHP_EXEC="docker exec -it symfony_php php"

# 1. Récupérer tous les symboles via fetch-contracts (sortie dans un fichier temporaire)
$PHP_EXEC bin/console app:bitmart:fetch-contracts > /tmp/contracts.log

# 2. Extraire les symboles (grep / sed / awk — simple mais rustique)
SYMBOLS=$(grep "Persisted contract:" /tmp/contracts.log | sed -E 's/.*Persisted contract: (.*)/\1/')

# 3. Boucler sur chaque symbole
for symbol in $SYMBOLS; do
  echo "🔄 Synchronisation de $symbol..."
  $PHP_EXEC bin/console bitmart:kline:sync-all --symbol=$symbol
  sleep 1
done

echo "✅ Synchronisation terminée."
