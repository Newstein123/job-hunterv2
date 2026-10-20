"""LLM access. Every response is validated against a Pydantic schema before use."""

import json
import logging
from abc import ABC, abstractmethod
from typing import TypeVar

from pydantic import BaseModel, ValidationError

from app.models.usage import LLMUsage

logger = logging.getLogger(__name__)

T = TypeVar("T", bound=BaseModel)


class LLMOutputError(RuntimeError):
    """The model did not return valid structured output after retries."""


class LLMClient(ABC):
    model: str = "unknown"
    max_attempts: int = 2

    def __init__(self) -> None:
        self.usage: list[LLMUsage] = []

    @abstractmethod
    def _generate(
        self, system: str, prompt: str, schema: type[BaseModel], think: bool, max_tokens: int
    ) -> tuple[str, LLMUsage]:
        """Return raw JSON text for the given schema and the tokens it used."""

    def generate_structured(
        self, system: str, prompt: str, schema: type[T], task: str = "llm", think: bool = True,
        max_tokens: int = 8192,
    ) -> T:
        """
        think=False turns off the model's internal reasoning, for extraction/classification
        tasks that don't need it (reasoning tokens are billed as output).
        max_tokens caps output (including reasoning), so a model stuck repeating itself fails fast
        and cheaply instead of running to the provider's ~65k-token limit.
        """
        last_error: Exception | None = None
        attempt_prompt = prompt
        for attempt in range(1, self.max_attempts + 1):
            raw, usage = self._generate(system, attempt_prompt, schema, think, max_tokens)
            self.usage.append(usage.model_copy(update={"task": task}))
            try:
                return schema.model_validate_json(raw)
            except ValidationError as exc:
                last_error = exc
                logger.warning("LLM output failed %s validation (attempt %d)", schema.__name__, attempt)
                attempt_prompt = (
                    f"{prompt}\n\nYour previous answer was invalid JSON for the schema:\n"
                    f"{exc.errors(include_url=False, include_input=False, include_context=False)}\nReturn only valid JSON."
                )
        raise LLMOutputError(f"Invalid {schema.__name__} output: {last_error}")


class GeminiClient(LLMClient):
    def __init__(self, api_key: str, model: str, temperature: float = 0.2):
        super().__init__()
        from google import genai

        self._client = genai.Client(api_key=api_key)
        self.model = model
        self.temperature = temperature

    def _generate(
        self, system: str, prompt: str, schema: type[BaseModel], think: bool, max_tokens: int
    ) -> tuple[str, LLMUsage]:
        from google.genai import types

        response = self._client.models.generate_content(
            model=self.model,
            contents=prompt,
            config=types.GenerateContentConfig(
                system_instruction=system,
                temperature=self.temperature,
                max_output_tokens=max_tokens,
                response_mime_type="application/json",
                response_json_schema=schema.model_json_schema(),
                # None leaves the model's default (dynamic) reasoning on.
                thinking_config=None if think else types.ThinkingConfig(thinking_budget=0),
            ),
        )
        meta = response.usage_metadata
        usage = LLMUsage(
            task="llm",
            model=self.model,
            input_tokens=(meta.prompt_token_count or 0) if meta else 0,
            output_tokens=(meta.candidates_token_count or 0) if meta else 0,
            thinking_tokens=(meta.thoughts_token_count or 0) if meta else 0,
        )
        return response.text or "", usage


class FakeLLMClient(LLMClient):
    """Offline client for tests: returns queued responses in order."""

    model = "fake"

    def __init__(self, responses: list[dict | str]):
        super().__init__()
        self._responses = list(responses)
        self.calls: list[dict] = []

    def _generate(
        self, system: str, prompt: str, schema: type[BaseModel], think: bool, max_tokens: int
    ) -> tuple[str, LLMUsage]:
        self.calls.append(
            {"system": system, "prompt": prompt, "schema": schema.__name__, "think": think, "max_tokens": max_tokens}
        )
        # Tests that don't script the evidence step get "no evidence found" (the safe default).
        if schema.__name__ == "EvidenceMatchLLM" and not (
            self._responses and isinstance(self._responses[0], dict) and "items" in self._responses[0]
        ):
            return '{"items": []}', LLMUsage(task="llm", model=self.model, input_tokens=len(prompt) // 4)
        if not self._responses:
            raise AssertionError("FakeLLMClient ran out of responses")
        response = self._responses.pop(0)
        text = response if isinstance(response, str) else json.dumps(response)
        return text, LLMUsage(task="llm", model=self.model, input_tokens=len(prompt) // 4, output_tokens=len(text) // 4)
