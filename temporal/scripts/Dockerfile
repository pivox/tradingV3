FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN apt-get update && \
    apt-get install -y gcc libffi-dev libpq-dev procps && \
    pip install --no-cache-dir -r requirements.txt && \
    apt-get remove -y gcc libffi-dev libpq-dev && \
    apt-get autoremove -y

COPY . .

CMD ["tail", "-f", "/dev/null"]  # Par défaut, mais tu pourras lancer client.py à la demande
