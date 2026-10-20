from pydantic import BaseModel


class LLMUsage(BaseModel):
    """Tokens used by one LLM call, as reported by the provider (billing is per token)."""

    task: str
    model: str
    input_tokens: int = 0
    output_tokens: int = 0  # the answer itself
    thinking_tokens: int = 0  # internal reasoning; billed as output
