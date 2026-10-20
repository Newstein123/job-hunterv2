"""Runtime configuration from environment variables. Never log these values."""

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    gemini_api_key: str | None
    gemini_model: str
    log_level: str
    ai_service_token: str | None


def get_settings() -> Settings:
    return Settings(
        gemini_api_key=os.environ.get("GEMINI_API_KEY") or None,
        gemini_model=os.environ.get("GEMINI_MODEL", "gemini-2.5-flash"),
        log_level=os.environ.get("LOG_LEVEL", "INFO"),
        ai_service_token=os.environ.get("AI_SERVICE_TOKEN") or None,
    )
