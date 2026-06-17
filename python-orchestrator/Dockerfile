FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1
ENV PYTHONPATH=/app

# Pas de build-essential : les dépendances runtime fournissent des wheels.
# À réintroduire (de préférence en multi-stage) si une dépendance compilée arrive.
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# requirements.txt = runtime épinglé uniquement (pytest/dev exclus, cf. requirements-dev.txt).
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Utilisateur non-root pour réduire la surface d'attaque.
RUN useradd --create-home --uid 10001 appuser

COPY ./ ./
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh && chown -R appuser:appuser /app

ENV SYMFONY_BASE_URL=http://trading-app-nginx:80 \
    ORCHESTRATOR_PORT=8099 \
    MAX_CONCURRENCY=2

EXPOSE 8099
USER appuser

ENTRYPOINT ["/entrypoint.sh"]
