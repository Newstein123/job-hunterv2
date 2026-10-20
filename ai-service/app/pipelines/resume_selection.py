"""Stage 4 — Resume Selection. Picks an EXISTING resume; never rewrites it."""

import re

from app.models.application import MatchResult
from app.models.candidate import CandidateEvidence
from app.models.job import JobAnalysis
from app.models.resume import AddableSkill, ResumeProfile, ResumeScore, ResumeSelection
from app.rules.models import CVRules
from app.skills.normalize import text_mentions


def _cv_rule_issues(rp: ResumeProfile, cv_rules: CVRules) -> list[str]:
    issues = []
    if cv_rules.preferred_formats and rp.format not in cv_rules.preferred_formats:
        issues.append(f"format {rp.format} is not in preferred formats {cv_rules.preferred_formats}")
    if cv_rules.max_pages and rp.page_count and rp.page_count > cv_rules.max_pages:
        issues.append(f"{rp.page_count} pages exceeds max {cv_rules.max_pages}")
    return issues


def _evidence_labels(refs: list[str], candidate: CandidateEvidence, this_resume: str) -> list[str]:
    """Where the candidate backs a skill up, as readable labels (excluding the resume being scored)."""
    profile = candidate.profile
    experiences = {e.id: f"experience: {e.title} at {e.company}" for e in profile.experiences}
    projects = {p.id: f"project: {p.name}" for p in profile.projects}
    resumes = {r.resume_id: f"resume: {r.filename}" for r in candidate.resumes if r.resume_id != this_resume}
    labels = []
    for ref in refs:
        kind, _, ref_id = ref.partition(":")
        if kind == "experience" and ref_id in experiences:
            labels.append(experiences[ref_id])
        elif kind == "project" and ref_id in projects:
            labels.append(projects[ref_id])
        elif kind == "resume" and ref_id in resumes:
            labels.append(resumes[ref_id])
        elif kind == "skill_list":
            labels.append("your skills list")
    return list(dict.fromkeys(labels))


def score_resume(
    rp: ResumeProfile, job: JobAnalysis, resume_text: str, cv_rules: CVRules, candidate: CandidateEvidence | None = None
) -> ResumeScore:
    skills = set(rp.skills)
    could_add: list[AddableSkill] = []
    gaps: list[str] = []
    if candidate is not None:
        for skill, required in [*((s, True) for s in job.required_skills), *((s, False) for s in job.preferred_skills)]:
            if skill in skills:
                continue
            labels = _evidence_labels(candidate.skill_evidence.get(skill, []), candidate, rp.resume_id)
            if labels:
                could_add.append(AddableSkill(skill=skill, required=required, evidence=labels))
            else:
                gaps.append(skill)

    terms = list(dict.fromkeys([*job.required_skills, *job.preferred_skills, *job.keywords]))
    found = [t for t in terms if text_mentions(resume_text, t)]
    return ResumeScore(
        ats_match=round(100 * len(found) / len(terms)) if terms else None,
        ats_found=found,
        ats_missing=[t for t in terms if t not in found],
        resume_id=rp.resume_id,
        required_covered=[s for s in job.required_skills if s in skills],
        preferred_covered=[s for s in job.preferred_skills if s in skills],
        keyword_hits=[k for k in job.keywords if re.search(re.escape(k), resume_text, re.IGNORECASE)],
        cv_rule_issues=_cv_rule_issues(rp, cv_rules),
        could_add=could_add,
        gaps=gaps,
        word_count=rp.word_count,
        page_count=rp.page_count,
    )


def _with_requirement_checks(score: ResumeScore, match_result: MatchResult, candidate: CandidateEvidence) -> ResumeScore:
    """Count evidence-checked requirements per resume: covered if this resume is quoted, "could add" if only
    the profile or another resume proves it, and a gap if nothing does."""
    if not match_result.requirement_checks:
        return score
    this = f"resume:{score.resume_id}"
    required, preferred = list(score.required_covered), list(score.preferred_covered)
    could_add, gaps = list(score.could_add), list(score.gaps)
    known = {*required, *preferred, *(a.skill for a in could_add)}
    for c in match_result.requirement_checks:
        if c.status == "not_applicable" or c.requirement in known:
            continue
        gaps = [g for g in gaps if g != c.requirement]
        if c.status == "met" and any(e.source == this for e in c.evidence):
            (required if c.required else preferred).append(c.requirement)
        elif c.status == "met":
            labels = _evidence_labels([e.source for e in c.evidence], candidate, score.resume_id)
            could_add.append(AddableSkill(skill=c.requirement, required=c.required, evidence=labels or ["your profile"]))
        else:
            gaps.append(c.requirement)
    return score.model_copy(update={"required_covered": required, "preferred_covered": preferred,
                                    "could_add": could_add, "gaps": gaps})


def _sort_key(score: ResumeScore) -> tuple:
    # Explainable lexicographic ranking: required > preferred > keywords > rule compliance.
    return (
        -len(score.required_covered),
        -len(score.preferred_covered),
        -len(score.keyword_hits),
        len(score.cv_rule_issues),
        score.resume_id,
    )


def _reason(score: ResumeScore, totals: tuple[int, int], runner_up: ResumeScore | None, runner_up_name: str = "",
            keywords: bool = True) -> str:
    parts = []
    required_total, preferred_total = totals
    if required_total:
        covered = ", ".join(score.required_covered[:6]) or "none"
        parts.append(f"Covers {len(score.required_covered)}/{required_total} requirements ({covered})")
    if preferred_total:
        parts.append(f"{len(score.preferred_covered)}/{preferred_total} preferred")
    if score.keyword_hits:
        parts.append(f"mentions {', '.join(score.keyword_hits)}")
    if runner_up is not None:
        parts.append(
            f"next best is {runner_up_name or runner_up.resume_id} with {len(runner_up.required_covered)} required "
            f"and {len(runner_up.preferred_covered)} preferred"
        )
    if score.cv_rule_issues:
        parts.append("note: " + "; ".join(score.cv_rule_issues))
    return "; ".join(parts) + "."


def select_resume(
    job: JobAnalysis,
    candidate: CandidateEvidence,
    match_result: MatchResult,
    resume_texts: dict[str, str],
    cv_rules: CVRules,
) -> ResumeSelection:
    if not candidate.resumes:
        raise ValueError("At least one resume is required for selection")

    scores = sorted(
        (_with_requirement_checks(score_resume(rp, job, resume_texts[rp.resume_id], cv_rules, candidate), match_result, candidate)
         for rp in candidate.resumes),
        key=_sort_key,
    )
    best = scores[0]
    best_profile = next(rp for rp in candidate.resumes if rp.resume_id == best.resume_id)
    runner_up = scores[1] if len(scores) > 1 else None

    in_resume_exp = set(best_profile.experience_ids)
    in_resume_proj = set(best_profile.project_ids)
    return ResumeSelection(
        recommended_resume_id=best.resume_id,
        recommended_filename=best_profile.filename,
        alternative_resume_ids=[s.resume_id for s in scores[1:]],
        reason=_reason(best, (match_result.summary.required_total, match_result.summary.preferred_total), runner_up,
                       next((rp.filename for rp in candidate.resumes
                                                   if runner_up and rp.resume_id == runner_up.resume_id), "")),
        emphasize=best.required_covered + best.preferred_covered,
        relevant_experience=[e.label for e in match_result.relevant_experience if e.id in in_resume_exp],
        relevant_projects=[p.label for p in match_result.relevant_projects if p.id in in_resume_proj],
        scores=scores,
    )
