"""Stage 5 — Skill / Experience Strategy (deterministic)."""

from app.models.application import MatchResult, SkillStrategy
from app.models.candidate import CandidateEvidence
from app.models.resume import ResumeSelection
from app.rules.models import ApplicationRules
from app.skills.normalize import normalize_skills


def _is_weak(refs: list[str]) -> bool:
    """Only self-listed, with no experience or project behind it."""
    return not any(r.startswith(("experience:", "project:")) for r in refs)


def build_skill_strategy(
    match_result: MatchResult,
    selection: ResumeSelection,
    candidate: CandidateEvidence,
    app_rules: ApplicationRules,
) -> SkillStrategy:
    in_resume = set(selection.emphasize)
    strong = [m.skill for m in match_result.matching_required if m.kind == "skill" and not _is_weak(m.evidence)]
    # Prefer required skills that are both strongly evidenced and visible on the chosen resume.
    emphasize = [s for s in strong if s in in_resume] + [s for s in strong if s not in in_resume]

    # User-configured emphasis only applies where the candidate has evidence.
    for skill in normalize_skills(app_rules.skills_to_emphasize):
        refs = candidate.skill_evidence.get(skill)
        if refs and not _is_weak(refs) and skill not in emphasize:
            emphasize.append(skill)
    emphasize = emphasize[: app_rules.max_emphasized_skills]

    supporting = [
        m.skill for m in match_result.matching_preferred
        if m.kind == "skill" and m.skill not in emphasize and not _is_weak(m.evidence)
    ][:4]

    matched = [*match_result.matching_required, *match_result.matching_preferred]
    avoid = [m.skill for m in matched if _is_weak(m.evidence)]

    experiences = [e.id for e in match_result.relevant_experience[:2]]
    project = None
    if app_rules.mention_projects and match_result.relevant_projects:
        project = match_result.relevant_projects[0].id

    return SkillStrategy(
        emphasize=emphasize,
        supporting_skills=supporting,
        avoid_overemphasizing=avoid,
        do_not_claim=[*match_result.missing_required, *match_result.missing_preferred],
        relevant_experience=experiences,
        relevant_project=project,
    )
