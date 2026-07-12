FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir --requirement /app/requirements.txt \
    && useradd --create-home --uid 10001 signer

COPY --chown=10001:10001 app /app/app

USER 10001
EXPOSE 8098

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["python", "-c", "import os,urllib.request; r=urllib.request.Request('http://127.0.0.1:8098/v1/health',headers={'Authorization':'Bearer '+os.environ['HYPERLIQUID_SIGNER_AUTH_TOKEN']}); urllib.request.urlopen(r,timeout=5).read()"]

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8098"]
