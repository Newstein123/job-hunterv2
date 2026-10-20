"""Stage 7 — Email Generation (LLM writes; code builds subject/signature and validates grounding)."""

import hashlib
import json
import logging
import re
import string

from app.llm.client import LLMClient
from app.models.application import (
    ApplicationStrategy,
    EmailDraft,
    GeneratedEmail,
    SkillStrategy,
    ValidationReport,
)
from app.models.candidate import CandidateEvidence
from app.models.job import JobAnalysis, JobInput
from app.models.resume import ResumeSelection
from app.prompts import email_generation as prompt
from app.rules.models import Rules
from app.rules.validator import check_email_rules
from app.skills.normalize import find_skills_in_text, known_vocabulary

logger = logging.getLogger(__name__)

_YEARS_PATTERN = re.compile(r"\b(\d{1,2})\+?\s*(?:years?|yrs)\b", re.IGNORECASE)
_NUMBER_WORDS = {w: str(i) for i, w in enumerate(
    "zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen "
    "seventeen eighteen nineteen twenty".split())}
_NUMBER_WORD_PATTERN = re.compile(r"\b(" + "|".join(_NUMBER_WORDS) + r")\b(?=[\s-]*(?:\+\s*)?(?:years?|yrs)\b)", re.IGNORECASE)


def _digits_for_number_words(text: str) -> str:
    """'eight years' -> '8 years', so spelled-out claims get the same check as digits."""
    return _NUMBER_WORD_PATTERN.sub(lambda m: _NUMBER_WORDS[m.group(1).lower()], text)
_METRIC_PATTERN = re.compile(r"\b\d+(?:[.,]\d+)?\s?(?:%|x\b|k\b|m\b)", re.IGNORECASE)
_ORG_PATTERN = re.compile(r"\b(?:at|with|for|from|joined)\s+((?:[A-Z][\w&.'-]+)(?:\s+[A-Z][\w&.'-]+)*)")
_ORG_STOPWORDS = {"I", "The", "My", "Your", "This", "Our", "A", "An"}


class _KeepMissing(dict):
    def __missing__(self, key: str) -> str:
        return "{" + key + "}"


def pick_subject_format(rules: Rules, job: JobAnalysis) -> str:
    """The user's fixed subject if set; otherwise one of the course formats, chosen per job (stable)."""
    email_rules = rules.email_rules
    if email_rules.subject_format:
        return email_rules.subject_format
    formats = email_rules.subject_formats or ["{position} - Application - {candidate_name}"]
    key = f"{job.company}|{job.title}".lower().encode()
    return formats[int(hashlib.sha1(key).hexdigest(), 16) % len(formats)]


def render_subject(subject_format: str, job: JobAnalysis, candidate_name: str) -> tuple[str, list[str]]:
    values = {"position": job.title, "company": job.company, "candidate_name": candidate_name}
    fields = {f for _, f, _, _ in string.Formatter().parse(subject_format) if f}
    unknown = sorted(fields - values.keys())
    warnings = [f"Unknown subject placeholder(s): {', '.join(unknown)}"] if unknown else []
    return subject_format.format_map(_KeepMissing(values)), warnings


def _candidate_facts(candidate: CandidateEvidence, skills: SkillStrategy, strategy: ApplicationStrategy,
                     requirement_checks=None) -> dict:
    profile = candidate.profile
    experiences = [e for e in profile.experiences if e.id in strategy.relevant_experience]
    project = next((p for p in profile.projects if p.id == strategy.relevant_project), None)
    return {
        "name": profile.name,
        "headline": profile.headline,
        "current_location": profile.location,
        "availability": profile.availability,
        "total_years_experience": candidate.total_years_experience,
        "skills_to_emphasize": skills.emphasize,
        "supporting_skills": skills.supporting_skills,
        "mention_lightly_if_at_all": skills.avoid_overemphasizing,
        "relevant_experience": [
            {
                "title": e.title,
                "company": e.company,
                "start": e.start.isoformat(),
                "end": e.end.isoformat() if e.end else "present",
                "summary": e.summary,
                "achievements": e.achievements,
            }
            for e in experiences
        ],
        "relevant_project": {"name": project.name, "description": project.description} if project else None,
        "education": [f"{e.degree}{', ' + e.field if e.field else ''} — {e.institution}" for e in profile.education],
        # Job requirements with verified evidence from the candidate's own words (quote exactly what they did).
        "verified_evidence": [
            {"requirement": c.requirement, "evidence": c.evidence[0].quote}
            for c in (requirement_checks or []) if c.status == "met" and c.evidence
        ][:5],
    }


def _job_facts(job: JobAnalysis, job_input: JobInput | None = None) -> dict:
    if job_input is not None and job_input.kind == "speculative":
        # What the user's company list says; the only company facts the email may use.
        return {
            "kind": "speculative (the company has not advertised this role)",
            "position_offered": job.title,
            "company": job.company,
            "country": job.country,
            "company_industry": job_input.industry,
            "company_specialties": job.keywords,
        }
    return {
        "title": job.title,
        "company": job.company,
        "location": job.location,
        "required_skills": job.required_skills,
        "responsibilities": job.responsibilities[:5],
    }


def _strategy_facts(strategy: ApplicationStrategy) -> dict:
    return strategy.model_dump(
        include={"language", "tone", "email_length", "paragraph_count", "max_words", "focus", "cta",
                 "include_cover_letter", "include_salary", "claims_to_avoid", "letter_style", "job_source"}
    )


_ADVERTISED = re.compile(r"\b(?:advertis\w*|job (?:posting|post|ad|listing)|vacancy (?:you|that you)|you (?:posted|listed))\b", re.I)
_LOCATION_CLAIM = re.compile(r"\b(?:currently|now)\s+(?:based|located|living|residing)\s+in\b|\bI am (?:based|located) in\b", re.I)
_AVAILABILITY_CLAIM = re.compile(r"\b(?:available|able|ready) to (?:start|join|commence)\b|\bstart immediately\b|\bnotice period\b", re.I)


def check_grounding(
    text: str, job: JobAnalysis, candidate: CandidateEvidence, do_not_claim: list[str]
) -> tuple[list[str], list[str]]:
    """Deterministic checks that generated text only uses supported candidate information."""
    errors: list[str] = []
    warnings: list[str] = []
    profile = candidate.profile

    vocabulary = list(dict.fromkeys([*known_vocabulary(), *job.required_skills, *job.preferred_skills,
                                     *candidate.skill_evidence.keys()]))
    for skill in find_skills_in_text(text, vocabulary):
        if skill in do_not_claim or not candidate.has_skill(skill):
            errors.append(f"Mentions {skill!r}, which is not evidenced in the candidate profile")

    allowed_years = {candidate.total_years_experience}
    allowed_years |= {int(s.years) for s in profile.skills if s.years is not None}
    for match in _YEARS_PATTERN.finditer(_digits_for_number_words(text)):
        if int(match.group(1)) not in allowed_years:
            errors.append(f"Claims {match.group(0)!r}, which does not match the profile")

    profile_text = profile.model_dump_json()
    for match in _METRIC_PATTERN.finditer(text):
        if match.group(0).replace(" ", "") not in profile_text.replace(" ", ""):
            errors.append(f"Uses metric {match.group(0)!r}, which is not in the profile")

    known_orgs = [job.company, *(e.company for e in profile.experiences), *(p.name for p in profile.projects),
                  *(e.institution for e in profile.education)]
    known_orgs = [o.lower() for o in known_orgs if o]
    skill_names = {s.lower() for s in vocabulary}
    for match in _ORG_PATTERN.finditer(text):
        name = match.group(1).strip(".,")
        lowered = name.lower()
        if name in _ORG_STOPWORDS or lowered in skill_names or lowered in job.title.lower():
            continue
        if not any(lowered in org or org in lowered for org in known_orgs):
            warnings.append(f"Mentions {name!r}; check it is a real employer, project, or the target company")
    if not profile.location and _LOCATION_CLAIM.search(text):
        errors.append("States where the candidate is based, but the profile has no current location")
    if not profile.availability and _AVAILABILITY_CLAIM.search(text):
        errors.append("States when the candidate can start, but the profile has no availability")
    return errors, warnings


def _signature(candidate: CandidateEvidence, strategy: ApplicationStrategy) -> str:
    profile = candidate.profile
    lines = [profile.name, profile.email]
    if strategy.include_phone and profile.phone:
        # Course convention: mark the number that's on WhatsApp.
        lines.append(f"{profile.phone} (WhatsApp)" if profile.whatsapp and profile.whatsapp == profile.phone else profile.phone)
    if strategy.include_phone and profile.whatsapp and profile.whatsapp != profile.phone:
        lines.append(f"WhatsApp: {profile.whatsapp}")
    if strategy.include_linkedin and profile.linkedin:
        lines.append(profile.linkedin)
    return "\n".join(lines)


def _assemble(draft: EmailDraft, signature: str) -> tuple[str, str | None]:
    body = "\n\n".join([draft.greeting, *draft.paragraphs, f"{draft.closing}\n{signature}"])
    cover = None
    if draft.cover_letter_paragraphs:
        cover = "\n\n".join([draft.greeting, *draft.cover_letter_paragraphs, f"{draft.closing}\n{signature}"])
    return body, cover


def generate_email(
    job: JobAnalysis,
    candidate: CandidateEvidence,
    selection: ResumeSelection,
    skills: SkillStrategy,
    strategy: ApplicationStrategy,
    rules: Rules,
    llm: LLMClient,
    max_attempts: int = 2,
    requirement_checks=None,
    job_input: JobInput | None = None,
) -> GeneratedEmail:
    speculative = job_input is not None and job_input.kind == "speculative"
    subject, subject_warnings = render_subject(pick_subject_format(rules, job), job, candidate.profile.name)
    args = dict(
        job=json.dumps(_job_facts(job, job_input), indent=2),
        candidate=json.dumps(_candidate_facts(candidate, skills, strategy, requirement_checks), indent=2),
        do_not_claim=", ".join(skills.do_not_claim),
        strategy=json.dumps(_strategy_facts(strategy), indent=2),
        resume=selection.recommended_filename,
    )

    repair = ""
    report = ValidationReport()
    draft: EmailDraft | None = None
    for attempt in range(1, max_attempts + 1):
        draft = llm.generate_structured(prompt.SYSTEM, prompt.build_prompt(**args, repair=repair), EmailDraft, task="email")
        text = "\n".join([draft.greeting, *draft.paragraphs, draft.closing, *draft.cover_letter_paragraphs])
        errors = check_email_rules(draft, rules, strategy.include_cover_letter)
        grounding_errors, warnings = check_grounding(text, job, candidate, skills.do_not_claim)
        if speculative and _ADVERTISED.search(text):
            grounding_errors.append("Says the role was advertised or posted; this company has not posted it")
        report = ValidationReport(errors=errors + grounding_errors, warnings=subject_warnings + warnings,
                                  attempts=attempt)
        if report.ok:
            break
        logger.info("email draft failed validation (attempt %d): %s", attempt, report.errors)
        repair = "\nYOUR PREVIOUS DRAFT VIOLATED THESE RULES. Fix all of them:\n- " + "\n- ".join(report.errors) + "\n"

    assert draft is not None
    body, cover = _assemble(draft, _signature(candidate, strategy))
    return GeneratedEmail(
        subject=subject,
        body=body,
        cover_letter=cover,
        attachment=selection.recommended_filename,
        validation=report,
    )
