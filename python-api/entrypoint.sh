#!/bin/sh

set -e

DB_PATH="/data/db.sqlite"

if [ ! -f "$DB_PATH" ]; then
  echo "📂 Fichier $DB_PATH absent. Il sera créé automatiquement par l'application/migration."
else
  echo "✔️ Base déjà présente à $DB_PATH"
fi

echo "🚀 Lancement de FastAPI (avec reload)..."
exec uvicorn main:app --host 0.0.0.0 --port 8000 --reload
