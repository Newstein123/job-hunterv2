# JobHunter AI service (FastAPI). Only app/ and requirements.txt go into the image:
# course materials (knowledge/), examples, evals, tests and .env stay out.
#
# Runs on AWS Lambda (through the Lambda Web Adapter) and anywhere else Docker runs.
# For Lambda (arm64), build with:
#   docker build --platform linux/arm64 --provenance=false -t jobhunter-ai .
FROM python:3.13-slim

# Lets Lambda forward requests to uvicorn; ignored when not running on Lambda.
COPY --from=public.ecr.aws/awsguru/aws-lambda-adapter:0.9.1 /lambda-adapter /opt/extensions/lambda-adapter

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    AWS_LWA_PORT=8001 \
    AWS_LWA_READINESS_CHECK_PATH=/health

WORKDIR /srv

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY app ./app

RUN useradd --system --uid 10001 appuser
USER appuser

EXPOSE 8001

# Secrets (GEMINI_API_KEY, AI_SERVICE_TOKEN) come from the environment, never the image.
CMD ["uvicorn", "app.api.main:app", "--host", "0.0.0.0", "--port", "8001", "--proxy-headers", "--forwarded-allow-ips", "*"]
