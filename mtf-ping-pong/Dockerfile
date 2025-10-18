FROM python:3.11-slim

# Définir le répertoire de travail
WORKDIR /app

# Installer les dépendances système
RUN apt-get update && apt-get install -y \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Copier les fichiers de dépendances
COPY requirements.txt .

# Installer les dépendances Python
RUN pip install --no-cache-dir -r requirements.txt

# Copier le code source
COPY . .

# Créer un utilisateur non-root
RUN useradd --create-home --shell /bin/bash app \
    && chown -R app:app /app
USER app

# Variables d'environnement
ENV PYTHONPATH=/app
ENV TEMPORAL_ADDRESS=temporal-grpc:7233
ENV TEMPORAL_NAMESPACE=default
ENV TASK_QUEUE_NAME=mtf-ping-pong-queue
ENV WORKER_IDENTITY=mtf-ping-pong-worker

# Point d'entrée
CMD ["python", "worker.py"]








