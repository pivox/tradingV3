FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1
ENV PYTHONPATH=/app

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates build-essential \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY ./ ./
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV SYMFONY_BASE_URL=http://trading-app-nginx:80 \
    ORCHESTRATOR_PORT=8099 \
    MAX_CONCURRENCY=2

EXPOSE 8099

ENTRYPOINT ["/entrypoint.sh"]
