FROM python:3.11-slim

WORKDIR /app

# Copier les dépendances
COPY requirements.txt .

# Installer les dépendances système et Python (1 seul RUN bien formé)
RUN apt-get update && \
    apt-get install -y gcc libffi-dev libpq-dev procps && \
    pip install --no-cache-dir -r requirements.txt && \
    apt-get remove -y gcc libffi-dev libpq-dev && \
    apt-get autoremove -y && \
    rm -rf /var/lib/apt/lists/*

# Copier le code de l’application
COPY . .

CMD ["python", "worker.py"]
