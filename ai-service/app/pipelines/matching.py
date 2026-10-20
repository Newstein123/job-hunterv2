"""Stage 3 — Job/Candidate Matching (deterministic, explainable)."""

import re

from app.models.application import MatchResult, MatchSummary, RelevantItem, SkillMatch
from app.models.candidate import CandidateEvidence
from app.models.job import JobAnalysis
from app.pipelines.candidate_analysis import experience_text
from app.skills.normalize import find_skills_in_text

_DEGREE_PATTERN = re.compile(r"\b(degree|bachelor|master|phd|diploma|b\.?sc|m\.?sc)\b", re.IGNORECASE)


def _match_skills(skills: list[str], candidate: CandidateEvidence) -> tuple[list[SkillMatch], list[str]]:
    matched, missing = [], []
    for skill in skills:
        refs = candidate.skill_evidence.get(skill)
        if refs:
            matched.append(SkillMatch(skill=skill, evidence=refs))
        else:
            missing.append(skill)
    return matched, missing


def _rank(items: list[tuple[int, RelevantItem]]) -> list[RelevantItem]:
    return [item for score, item in sorted(items, key=lambda x: -x[0]) if score > 0]


def match(job: JobAnalysis, candidate: CandidateEvidence) -> MatchResult:
    required = job.required_skills
    preferred = job.preferred_skills
    matching_required, missing_required = _match_skills(required, candidate)
    matching_preferred, missing_preferred = _match_skills(preferred, candidate)
    job_skills = [*required, *preferred]
    profile = candidate.profile

    def score(found: list[str], text: str) -> tuple[int, list[str]]:
        keyword_hits = [k for k in job.keywords if re.search(re.escape(k), text, re.IGNORECASE)]
        return 2 * len(set(found) & set(required)) + len(set(found) & set(preferred)) + len(keyword_hits), keyword_hits

    experiences = []
    for exp in profile.experiences:
        text = experience_text(exp) + " " + " ".join(exp.skills)
        found = find_skills_in_text(text, job_skills)
        s, _ = score(found, text)
        experiences.append(
            (s, RelevantItem(id=exp.id, label=f"{exp.title} at {exp.company}", matched_skills=found,
                             evidence=[f"experience:{exp.id}"]))
        )

    projects = []
    for project in profile.projects:
        text = f"{project.name} {project.description} {' '.join(project.skills)}"
        found = find_skills_in_text(text, job_skills)
        s, _ = score(found, text)
        projects.append(
            (s, RelevantItem(id=project.id, label=project.name, matched_skills=found,
                             evidence=[f"project:{project.id}"]))
        )

    degree_required = any(_DEGREE_PATTERN.search(r) for r in job.requirements)
    education = []
    for edu in profile.education:
        text = f"{edu.degree} {edu.field or ''}"
        found = find_skills_in_text(text, job_skills)
        s, hits = score(found, text)
        s += 1 if degree_required else 0
        education.append(
            (s, RelevantItem(id=edu.id, label=f"{edu.degree}{', ' + edu.field if edu.field else ''} — {edu.institution}",
                             matched_skills=found + hits,
                             evidence=[f"education:{edu.id}"] + (["job requires a degree"] if degree_required else [])))
        )

    return MatchResult(
        matching_required=matching_required,
        matching_preferred=matching_preferred,
        missing_required=missing_required,
        missing_preferred=missing_preferred,
        relevant_experience=_rank(experiences),
        relevant_projects=_rank(projects),
        relevant_education=_rank(education),
        summary=MatchSummary(
            required_covered=len(matching_required),
            required_total=len(required),
            preferred_covered=len(matching_preferred),
            preferred_total=len(preferred),
        ),
    )
