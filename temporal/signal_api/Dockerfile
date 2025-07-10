FROM python:3.11-slim

WORKDIR /app

# Install minimal network diagnostic tools
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    iputils-ping \
    telnet \
    && rm -rf /var/lib/apt/lists/*

COPY . /app

RUN pip install --no-cache-dir fastapi uvicorn temporalio

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "5000"]
