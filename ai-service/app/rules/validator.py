"""Rule schema validation and deterministic checks of generated emails against rules."""

import re
from typing import Any

from pydantic import ValidationError

from app.models.application import EmailDraft
from app.rules.models import Rules

_SALARY_PATTERN = re.compile(
    r"\b(salary|compensation|remuneration|expected pay|pay expectation)\b"
    r"|(?:\$|USD|SGD|EUR|GBP|THB)\s?\d",
    re.IGNORECASE,
)


_FORMATTING = re.compile(r"\*\*|__|(?<![\w*])\*(?!\s)[^*\n]+\*(?!\w)|^\s*[-*•]\s|^#{1,6}\s", re.MULTILINE)


class RulesError(ValueError):
    pass


def validate_rules(data: dict[str, Any]) -> Rules:
    try:
        return Rules.model_validate(data)
    except ValidationError as exc:
        raise RulesError(f"Invalid rules configuration: {exc}") from exc


def word_count(text: str) -> int:
    return len(re.findall(r"\b[\w'’-]+\b", text))


def check_email_rules(draft: EmailDraft, rules: Rules, include_cover_letter: bool) -> list[str]:
    """Return rule violations (errors) for a generated draft."""
    errors: list[str] = []
    email_rules = rules.email_rules
    body = "\n\n".join(draft.paragraphs)

    if len(draft.paragraphs) != email_rules.paragraph_count:
        errors.append(
            f"Expected {email_rules.paragraph_count} body paragraphs, got {len(draft.paragraphs)}"
        )
    words = word_count(" ".join([draft.greeting, body, draft.closing]))
    if words > email_rules.max_words:
        errors.append(f"Email has {words} words; max is {email_rules.max_words}")

    full_text = "\n".join([body, *draft.cover_letter_paragraphs])
    # Course formatting rule: one plain style, no bold/italic, no lists or headings.
    if _FORMATTING.search(full_text):
        errors.append("Use plain sentences: no bold, italics, bullet points or headings")
    if not email_rules.include_salary and _SALARY_PATTERN.search(full_text):
        errors.append("Mentions salary/compensation, which the rules exclude")
    if not email_rules.include_linkedin and re.search(r"linkedin", full_text, re.IGNORECASE):
        errors.append("Mentions LinkedIn, which the rules exclude")

    for claim in rules.application_rules.claims_to_avoid:
        if claim.strip() and claim.lower() in full_text.lower():
            errors.append(f"Contains a claim the rules say to avoid: {claim!r}")

    if include_cover_letter and not draft.cover_letter_paragraphs:
        errors.append("A cover letter was requested but not generated")
    if not include_cover_letter and draft.cover_letter_paragraphs:
        errors.append("A cover letter was generated but not requested")
    return errors
