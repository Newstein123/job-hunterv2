# JobHunter AI service (FastAPI). Only app/ and requirements.txt go into the image:
# course materials (knowledge/), examples, evals, tests and .env stay out.
#
# Lightsail runs linux/amd64; on an Apple Silicon Mac build with:
#   docker build --platform linux/amd64 -t jobhunter-ai .
FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /srv

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY app ./app

RUN useradd --system --uid 10001 appuser
USER appuser

EXPOSE 8001

# Secrets (GEMINI_API_KEY, AI_SERVICE_TOKEN) come from the container's environment, never the image.
CMD ["uvicorn", "app.api.main:app", "--host", "0.0.0.0", "--port", "8001", "--proxy-headers", "--forwarded-allow-ips", "*"]
