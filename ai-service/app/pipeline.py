"""Orchestrates the application pipeline. Each stage is independently callable and testable."""

import logging
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from app.llm.client import LLMClient
from app.models.application import MatchPreview, MatchResult, PipelineMeta, PipelineResult
from app.models.candidate import CandidateEvidence
from app.models.job import JobAnalysis
from app.models.resume import ResumeSelection
from app.rules.models import Rules
from app.models.candidate import CandidateProfile
from app.models.job import JobInput
from app.models.resume import Resume
from app.pipelines.application_strategy import build_application_strategy
from app.pipelines.candidate_analysis import analyze_candidate
from app.pipelines.email_generation import generate_email
from app.pipelines.evidence import apply_requirement_checks, check_requirements
from app.pipelines.job_analysis import analyze_job
from app.pipelines.matching import match
from app.pipelines.resume_selection import select_resume
from app.pipelines.skill_strategy import build_skill_strategy
from app.pipelines.speculative import speculative_analysis, speculative_skill_strategy
from app.prompts import email_generation, evidence, job_analysis
from app.rules.loader import load_rules

logger = logging.getLogger(__name__)


@dataclass
class _Matched:
    rules: Rules
    rules_sources: list[str]
    warnings: list[str]
    analysis: JobAnalysis
    candidate: CandidateEvidence
    match: MatchResult
    selection: ResumeSelection


def _analyze_and_match(
    job, profile, resumes, llm, now, user_rules_path=None, user_rules=None, stored_analysis: JobAnalysis | None = None
) -> _Matched:
    """Stages 1-4, shared by the full pipeline and the match-only preview."""
    rules, rules_sources, warnings = load_rules(job.country, user_rules_path, user_rules=user_rules)

    if job.kind == "speculative":
        # No posting: the role offered and the company's listed specialties stand in for the analysis.
        logger.info("stage 1: speculative application (no posting to analyze)")
        analysis = speculative_analysis(job)
    elif stored_analysis is not None:
        # Analyzed already (e.g. at import); reuse it instead of paying for the same extraction twice.
        logger.info("stage 1: job analysis (reused)")
        analysis = stored_analysis.model_copy(update={
            "title": job.position or stored_analysis.title,
            "company": job.company or stored_analysis.company,
            "location": job.location or stored_analysis.location,
            "country": job.country or stored_analysis.country,
        })
    else:
        logger.info("stage 1: job analysis")
        analysis, job_warnings = analyze_job(job, llm)
        warnings += job_warnings

    logger.info("stage 2: candidate analysis")
    candidate = analyze_candidate(profile, resumes, today=now.date())

    logger.info("stage 3: matching")
    match_result = match(analysis, candidate)

    # 3b: requirements that skill names can't settle (competencies, degree, years, certifications)
    items = [(s, True) for s in match_result.missing_required] + [(s, False) for s in match_result.missing_preferred]
    items += [(r, True) for r in analysis.requirements]
    if not (analysis.required_skills or analysis.preferred_skills or analysis.requirements):
        # Duty-only postings: check the main duties against the candidate's experience instead.
        items = [(r, True) for r in analysis.responsibilities[:6]]
    if items and job.kind != "speculative":
        logger.info("stage 3b: evidence matching (%d items)", len(items))
        checks, evidence_warnings = check_requirements(analysis, items, candidate, resumes, llm)
        match_result = apply_requirement_checks(match_result, checks)
        warnings += evidence_warnings

    logger.info("stage 4: resume selection")
    selection = select_resume(analysis, candidate, match_result, {r.id: r.text for r in resumes}, rules.cv_rules)

    return _Matched(rules, rules_sources, warnings, analysis, candidate, match_result, selection)


def run_match(
    job: JobInput,
    profile: CandidateProfile,
    resumes: list[Resume],
    llm: LLMClient,
    now: datetime | None = None,
    user_rules: dict[str, Any] | None = None,
) -> MatchPreview:
    """How well a job fits the candidate, without drafting anything (one LLM call)."""
    now = now or datetime.now(timezone.utc)
    m = _analyze_and_match(job, profile, resumes, llm, now, user_rules=user_rules)
    return MatchPreview(
        job_analysis=m.analysis,
        match=m.match,
        resume_selection=m.selection,
        meta=PipelineMeta(
            model=llm.model,
            prompt_versions={"job_analysis": job_analysis.PROMPT_VERSION, "evidence": evidence.PROMPT_VERSION},
            rules_sources=m.rules_sources,
            generated_at=now,
            warnings=m.warnings,
            usage=llm.usage,
        ),
    )


def run_pipeline(
    job: JobInput,
    profile: CandidateProfile,
    resumes: list[Resume],
    llm: LLMClient,
    user_rules_path: Path | None = None,
    now: datetime | None = None,
    user_rules: dict[str, Any] | None = None,
    stored_analysis: JobAnalysis | None = None,
) -> PipelineResult:
    now = now or datetime.now(timezone.utc)
    m = _analyze_and_match(job, profile, resumes, llm, now, user_rules_path, user_rules, stored_analysis)
    rules, rules_sources, warnings = m.rules, m.rules_sources, m.warnings
    analysis, candidate, match_result, selection = m.analysis, m.candidate, m.match, m.selection

    logger.info("stage 5: skill strategy")
    if job.kind == "speculative":
        skills = speculative_skill_strategy(job, candidate, selection, match_result, rules.application_rules)
    else:
        skills = build_skill_strategy(match_result, selection, candidate, rules.application_rules)

    logger.info("stage 6: application strategy")
    strategy = build_application_strategy(job, skills, rules, now)

    logger.info("stage 7: email generation")
    email = generate_email(analysis, candidate, selection, skills, strategy, rules, llm,
                           requirement_checks=match_result.requirement_checks, job_input=job)
    if not email.validation.ok:
        warnings.append("Generated email still has validation errors; edit before approving")

    return PipelineResult(
        job_analysis=analysis,
        match=match_result,
        resume_selection=selection,
        skill_strategy=skills,
        application_strategy=strategy,
        email=email,
        meta=PipelineMeta(
            model=llm.model,
            prompt_versions={
                "job_analysis": job_analysis.PROMPT_VERSION,
                "evidence": evidence.PROMPT_VERSION,
                "email_generation": email_generation.PROMPT_VERSION,
            },
            rules_sources=rules_sources,
            generated_at=now,
            warnings=warnings,
            usage=llm.usage,
        ),
    )
