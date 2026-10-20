"""Speculative applications: the candidate writes to a company that hasn't posted a job.

There is no posting to analyze, so stage 1 and the evidence check are replaced by deterministic steps:
the "job" is the role the candidate offers plus what the user's company list says about the company,
and the emphasis comes from the candidate's own strongest evidence on the chosen resume.
"""

import re

from app.models.application import MatchResult, SkillStrategy
from app.models.candidate import CandidateEvidence
from app.models.job import JobAnalysis, JobInput
from app.models.resume import ResumeSelection
from app.rules.models import ApplicationRules
from app.skills.normalize import text_mentions

MAX_SPECIALTIES = 10


def specialty_terms(job: JobInput) -> list[str]:
    """The company's listed specialties, e.g. "search engine optimisation,web design" -> two terms."""
    terms = [t.strip() for t in re.split(r"[,;|\n]", job.specialties or "") if t.strip()]
    return list(dict.fromkeys(terms))[:MAX_SPECIALTIES]


def speculative_analysis(job: JobInput) -> JobAnalysis:
    return JobAnalysis(
        title=job.position or "", company=job.company or "", seniority="", location=job.location or "",
        country=job.country or "", required_skills=[], preferred_skills=[], responsibilities=[],
        keywords=specialty_terms(job), requirements=[],
    )


def _strength(refs: list[str]) -> int:
    return sum(1 for r in refs if r.startswith(("experience:", "project:")))


def speculative_skill_strategy(
    job: JobInput,
    candidate: CandidateEvidence,
    selection: ResumeSelection,
    match_result: MatchResult,
    app_rules: ApplicationRules,
) -> SkillStrategy:
    """Lead with the candidate's best-evidenced skills that are on the chosen resume, preferring ones the
    company's specialties mention. Nothing here comes from the company: it only orders the candidate's facts."""
    resume = next((r for r in candidate.resumes if r.resume_id == selection.recommended_resume_id), None)
    on_resume = set(resume.skills) if resume else set()
    company_text = f"{job.industry or ''} {job.specialties or ''}"

    ranked = sorted(
        (s for s, refs in candidate.skill_evidence.items() if _strength(refs) > 0),
        key=lambda s: (
            -(s in on_resume),
            -bool(company_text.strip() and text_mentions(company_text, s)),
            -_strength(candidate.skill_evidence[s]),
            s,
        ),
    )
    emphasize = ranked[: app_rules.max_emphasized_skills]
    supporting = ranked[app_rules.max_emphasized_skills : app_rules.max_emphasized_skills + 3]

    # Most recent roles first, preferring ones the chosen resume shows.
    in_resume = set(resume.experience_ids) if resume else set()
    experiences = sorted(candidate.profile.experiences, key=lambda e: (e.id not in in_resume, -e.start.toordinal()))
    project = None
    if app_rules.mention_projects:
        ranked_projects = [p.id for p in match_result.relevant_projects] or [p.id for p in candidate.profile.projects]
        on_resume_projects = [p for p in ranked_projects if resume and p in resume.project_ids]
        project = (on_resume_projects or ranked_projects or [None])[0]

    return SkillStrategy(
        emphasize=emphasize,
        supporting_skills=supporting,
        avoid_overemphasizing=[],
        do_not_claim=[],
        relevant_experience=[e.id for e in experiences[:2]],
        relevant_project=project,
    )
