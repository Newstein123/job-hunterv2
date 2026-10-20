"""Stage 1 — Job Analysis (LLM extraction + deterministic grounding post-check)."""

import json
import logging

from app.llm.client import LLMClient
from app.models.job import JobAnalysis, JobInput
from app.prompts import job_analysis as prompt
from app.skills.normalize import normalize_skills, text_mentions

logger = logging.getLogger(__name__)

MAX_REQUIRED, MAX_PREFERRED = 12, 8


def _grounded_skills(skills: list[str], description: str, label: str, warnings: list[str]) -> list[str]:
    kept = []
    for skill in normalize_skills(skills):
        if text_mentions(description, skill):
            kept.append(skill)
        else:
            warnings.append(f"Dropped {label} skill {skill!r}: not found in the job description")
    return kept


def analyze_job(job: JobInput, llm: LLMClient) -> tuple[JobAnalysis, list[str]]:
    metadata = job.model_dump(exclude={"description"}, exclude_none=True)
    raw = llm.generate_structured(
        prompt.SYSTEM,
        prompt.build_prompt(job.description, json.dumps(metadata) if metadata else ""),
        JobAnalysis,
        task="job_analysis",
        think=False,  # extraction: no reasoning needed
        max_tokens=3000,  # a full analysis is ~200-1000 tokens
    )

    warnings: list[str] = []
    required = _grounded_skills(raw.required_skills, job.description, "required", warnings)
    preferred = [
        s
        for s in _grounded_skills(raw.preferred_skills, job.description, "preferred", warnings)
        if s not in required
    ]

    if len(required) > MAX_REQUIRED or len(preferred) > MAX_PREFERRED:
        warnings.append(f"Kept the first {MAX_REQUIRED} required / {MAX_PREFERRED} preferred skills")
        required, preferred = required[:MAX_REQUIRED], preferred[:MAX_PREFERRED]

    analysis = raw.model_copy(
        update={
            "required_skills": required,
            "preferred_skills": preferred,
            # User-supplied metadata is authoritative over extraction.
            "title": job.position or raw.title,
            "company": job.company or raw.company,
            "location": job.location or raw.location,
            "country": job.country or raw.country,
        }
    )
    logger.debug("job analysis: %s", analysis.model_dump_json())
    return analysis, warnings
