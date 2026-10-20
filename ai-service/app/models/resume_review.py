"""Resume review: a deterministic checklist (course norms) plus a few AI suggestions.

Suggestions only: the user edits their own CV. JobHunter never rewrites it.
"""

from typing import Literal

from pydantic import BaseModel, Field

from app.models.usage import LLMUsage

CheckStatus = Literal["pass", "missing", "check"]


class CheckItem(BaseModel):
    id: str
    label: str
    status: CheckStatus
    detail: str = ""
    evidence: str | None = None  # the CV line that satisfies it
    group: Literal["course", "ats"] = "course"  # course norms, or readable by applicant tracking systems


class SuggestionLLM(BaseModel):
    title: str = Field(description="Short headline, e.g. 'Lead with results, not duties'")
    detail: str = Field(description="One or two sentences: what to change and why it helps with HR")
    quote: str = Field(description="Exact text copied from the CV that this is about (3-25 words), or empty")
    example: str = Field(description="Optional short example wording using only facts already in the CV, or empty")


class ResumeSuggestionsLLM(BaseModel):
    suggestions: list[SuggestionLLM]


class Suggestion(BaseModel):
    title: str
    detail: str
    quote: str | None = None
    example: str | None = None


class ResumeReview(BaseModel):
    checks: list[CheckItem]
    suggestions: list[Suggestion]
    word_count: int
    page_count: int | None
    warnings: list[str] = []
    model: str = ""
    prompt_version: str = ""
    usage: list[LLMUsage] = []
