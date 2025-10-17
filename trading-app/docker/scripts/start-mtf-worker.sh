#!/bin/bash

# Script de démarrage du worker MTF dans Docker

set -e

echo "Starting MTF Worker in Docker..."

# Attendre que la base de données soit prête
echo "Waiting for database..."
while ! php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
    echo "Database not ready, waiting..."
    sleep 2
done

echo "Database is ready!"

# Exécuter les migrations
echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction

# Démarrer le worker
echo "Starting MTF worker..."
php bin/console mtf:worker --daemon




