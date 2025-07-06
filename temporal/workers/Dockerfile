FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN apt-get update && \
    apt-get install -y gcc libffi-dev libpq-dev procps && \
    pip install --no-cache-dir -r requirements.txt && \
    apt-get remove -y gcc libffi-dev libpq-dev && \
    apt-get autoremove -y

COPY ./task_processor ./task_processor

CMD ["python", "task_processor/worker.py"]
