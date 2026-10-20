"""Stage 2 — Candidate Profile Analysis (deterministic, evidence-preserving)."""

import re
from datetime import date

from app.models.candidate import CandidateEvidence, CandidateProfile, Experience
from app.models.resume import Resume, ResumeProfile
from app.rules.validator import word_count
from app.skills.normalize import find_skills_in_text, known_vocabulary, normalize_skill


def _add(evidence: dict[str, list[str]], skill: str, ref: str) -> None:
    refs = evidence.setdefault(normalize_skill(skill), [])
    if ref not in refs:
        refs.append(ref)


def experience_text(exp: Experience) -> str:
    return " ".join([exp.title, exp.summary, *exp.achievements])


def total_years_experience(experiences: list[Experience], today: date) -> int:
    """Years covered by the union of experience intervals (overlaps counted once)."""
    intervals = sorted((e.start, e.end or today) for e in experiences)
    total_days = 0
    current_start = current_end = None
    for start, end in intervals:
        if current_end is None or start > current_end:
            if current_end is not None:
                total_days += (current_end - current_start).days
            current_start, current_end = start, end
        else:
            current_end = max(current_end, end)
    if current_end is not None:
        total_days += (current_end - current_start).days
    return int(total_days // 365.25)


def _mentions(text: str, phrase: str) -> bool:
    return bool(phrase.strip()) and re.search(re.escape(phrase.strip()), text, re.IGNORECASE) is not None


def profile_resume(resume: Resume, profile: CandidateProfile, vocabulary: list[str]) -> ResumeProfile:
    return ResumeProfile(
        resume_id=resume.id,
        filename=resume.filename,
        format=resume.format,
        page_count=resume.page_count,
        word_count=word_count(resume.text),
        skills=find_skills_in_text(resume.text, vocabulary),
        experience_ids=[
            e.id for e in profile.experiences if _mentions(resume.text, e.company) or _mentions(resume.text, e.title)
        ],
        project_ids=[p.id for p in profile.projects if _mentions(resume.text, p.name)],
    )


def analyze_candidate(
    profile: CandidateProfile, resumes: list[Resume], today: date | None = None
) -> CandidateEvidence:
    today = today or date.today()
    evidence: dict[str, list[str]] = {}

    for entry in profile.skills:
        _add(evidence, entry.name, "skill_list")

    profile_skill_names = [s.name for s in profile.skills]
    vocabulary = list(dict.fromkeys([*profile_skill_names, *known_vocabulary()]))

    for exp in profile.experiences:
        for skill in [*exp.skills, *find_skills_in_text(experience_text(exp), vocabulary)]:
            _add(evidence, skill, f"experience:{exp.id}")
    for project in profile.projects:
        for skill in [*project.skills, *find_skills_in_text(f"{project.name} {project.description}", vocabulary)]:
            _add(evidence, skill, f"project:{project.id}")

    # Vocabulary for resumes includes every skill already evidenced in the profile.
    vocabulary = list(dict.fromkeys([*evidence.keys(), *vocabulary]))
    resume_profiles = [profile_resume(r, profile, vocabulary) for r in resumes]
    for rp in resume_profiles:
        for skill in rp.skills:
            _add(evidence, skill, f"resume:{rp.resume_id}")

    return CandidateEvidence(
        profile=profile,
        skill_evidence=evidence,
        resumes=resume_profiles,
        total_years_experience=total_years_experience(profile.experiences, today),
    )
