FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONPATH=/app/ai-agent \
    PRONTAGENDA_AI_SESSION_DB=/tmp/prontagenda-ai/sessions.db \
    PORT=8080

WORKDIR /app

COPY ai-agent/requirements.txt /app/ai-agent/requirements.txt
COPY hackathon/all-things-agentic/requirements-hackathon.txt /app/hackathon/requirements-hackathon.txt
RUN pip install --no-cache-dir \
    -r /app/ai-agent/requirements.txt \
    -r /app/hackathon/requirements-hackathon.txt

COPY ai-agent /app/ai-agent

EXPOSE 8080

CMD ["sh", "-c", "uvicorn gateway:app --app-dir /app/ai-agent --host 0.0.0.0 --port ${PORT}"]
