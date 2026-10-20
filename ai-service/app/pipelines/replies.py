"""Understand an employer's reply, and draft the candidate's answer (LLM + deterministic checks)."""

import json
import re

from app.llm.client import LLMClient
from app.models.application import EmailDraft, ValidationReport
from app.models.candidate import CandidateProfile
from app.models.job import JobAnalysis
from app.models.reply import SUGGESTED_STATUS, ReplyAnalysis, ReplyAnalysisLLM, ReplyDraft, ReplyDraftLLM
from app.pipelines.candidate_analysis import analyze_candidate
from app.pipelines.email_generation import check_grounding
from app.prompts import reply_analysis, reply_draft
from app.rules.models import Rules
from app.rules.validator import word_count

_PLACEHOLDER = re.compile(r"\[\[[^\]]+\]\]")
# A money amount. Saying "my salary expectation is [[...]]" is fine; inventing a number is not.
_AMOUNT = re.compile(r"(?:\$|USD|SGD|AED|AUD|THB|EUR|GBP)\s?\d[\d,.]*\s?k?|\d[\d,.]*\s?(?:k\b|USD|SGD|AED|AUD|THB)", re.IGNORECASE)


def _norm(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip().lower()


def analyze_reply(job: JobAnalysis, sent_body: str, reply_text: str, llm: LLMClient) -> ReplyAnalysis:
    raw = llm.generate_structured(
        reply_analysis.SYSTEM,
        reply_analysis.build_prompt(f"{job.title} at {job.company}", sent_body, reply_text),
        ReplyAnalysisLLM,
        task="reply_analysis",
        think=False,  # classification: no reasoning needed
        max_tokens=2048,
    )
    warnings: list[str] = []
    reply_norm = _norm(reply_text)
    quotes = []
    for quote in raw.evidence_quotes:
        if _norm(quote) and _norm(quote) in reply_norm:
            quotes.append(quote)
        else:
            warnings.append(f"Dropped a quote that is not in the reply: {quote[:60]!r}")
    if not quotes and raw.category not in ("auto_reply", "other"):
        warnings.append("No supporting quote found in the reply; check the category yourself")

    return ReplyAnalysis(
        **raw.model_dump(exclude={"evidence_quotes"}),
        evidence_quotes=quotes,
        suggested_status=SUGGESTED_STATUS.get(raw.category),
        warnings=warnings,
    )


def _facts(profile: CandidateProfile) -> dict:
    candidate = analyze_candidate(profile, [])
    return {
        "name": profile.name,
        "headline": profile.headline,
        "total_years_experience": candidate.total_years_experience,
        "skills": [s.name for s in profile.skills],
        "experience": [f"{e.title} at {e.company} ({e.start.year}–{e.end.year if e.end else 'present'})"
                       for e in profile.experiences],
        "projects": [p.name for p in profile.projects],
        "languages": profile.languages,
        "linkedin": profile.linkedin,
    }, candidate


def draft_reply(
    job: JobAnalysis,
    profile: CandidateProfile,
    sent_subject: str,
    sent_body: str,
    reply_text: str,
    analysis: ReplyAnalysis,
    notes: str,
    rules: Rules,
    llm: LLMClient,
    max_attempts: int = 2,
) -> ReplyDraft:
    facts, candidate = _facts(profile)
    email_rules = rules.email_rules
    max_words = min(email_rules.max_words, 220)
    args = dict(
        job=f"{job.title} at {job.company}",
        sent=sent_body.strip()[:4000],
        reply=reply_text.strip()[:6000],
        analysis=json.dumps(analysis.model_dump(include={"category", "requests", "questions", "dates_mentioned"})),
        candidate=json.dumps(facts, indent=2),
        notes=notes.strip() or "(none)",
        language=email_rules.language,
        tone=email_rules.tone,
        max_words=max_words,
    )

    repair = ""
    report = ValidationReport()
    draft: ReplyDraftLLM | None = None
    notes_norm = _norm(notes)
    for attempt in range(1, max_attempts + 1):
        draft = llm.generate_structured(
            reply_draft.SYSTEM, reply_draft.build_prompt(**args, repair=repair), ReplyDraftLLM, task="reply_draft"
        )
        text = "\n".join([draft.greeting, *draft.paragraphs, draft.closing])

        errors: list[str] = []
        words = word_count(text)
        if words > max_words:
            errors.append(f"Reply has {words} words; max is {max_words}")
        # Money amounts only when the candidate wrote them in their notes (e.g. salary expectations).
        notes_compact = _norm(notes).replace(" ", "")
        for amount in _AMOUNT.findall(_PLACEHOLDER.sub("", text)):
            if _norm(amount).replace(" ", "") not in notes_compact:
                errors.append(f"Mentions the amount {amount.strip()!r}, which isn't in your notes")
        grounding_errors, warnings = check_grounding(_PLACEHOLDER.sub("", text), job, candidate, do_not_claim=[])
        # Facts the candidate supplied in their notes are allowed.
        grounding_errors = [e for e in grounding_errors if not any(
            token in notes_norm for token in re.findall(r"'([^']+)'", e.lower()))]
        errors += grounding_errors

        report = ValidationReport(errors=errors, warnings=warnings, attempts=attempt)
        if report.ok:
            break
        repair = "\nYOUR PREVIOUS DRAFT VIOLATED THESE RULES. Fix all of them:\n- " + "\n- ".join(errors) + "\n"

    assert draft is not None
    lines = [profile.name, profile.email]
    if email_rules.include_phone and profile.phone:
        lines.append(profile.phone)
    body = "\n\n".join([draft.greeting, *draft.paragraphs, f"{draft.closing}\n" + "\n".join(lines)])

    # Placeholders must be filled by the candidate before sending.
    needs = list(dict.fromkeys(n.strip("[] ").strip() for n in [*draft.needs_input, *_PLACEHOLDER.findall(body)]))
    if _PLACEHOLDER.search(body):
        report.errors.append("Fill in every [[...]] placeholder before sending")

    subject = sent_subject if sent_subject.lower().startswith("re:") else f"Re: {sent_subject}"
    return ReplyDraft(subject=subject, body=body, needs_input=needs, validation=report)
