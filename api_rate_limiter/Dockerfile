FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates build-essential \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copie du code + de l'entrypoint
COPY ./ ./
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Rendre les scripts Python exécutables
RUN chmod +x script.py


# Valeurs par défaut (surchageables)
ENV TEMPORAL_ADDRESS=temporal:7233 \
    TASK_QUEUE_NAME=api_rate_limiter_queue

# ⬇️ LANCE LE WORKER AUTOMATIQUEMENT
ENTRYPOINT ["/entrypoint.sh"]
