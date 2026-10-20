"""Stage 3b: evidence matching for requirements that skill names can't match.

The LLM proposes, per requirement, exact quotes from the candidate's own profile or resumes; code then
verifies every quote is really in the cited source. Unverified evidence doesn't count. Years of
experience are checked by code against the work history.
"""

import re

from app.llm.client import LLMClient
from app.models.candidate import CandidateEvidence
from app.models.evidence import EvidenceMatchLLM, EvidenceQuote, RequirementCheck
from app.models.job import JobAnalysis
from app.models.resume import Resume
from app.pipelines.candidate_analysis import experience_text
from app.prompts import evidence as prompt

_YEARS = re.compile(r"(\d{1,2})\s*\+?\s*(?:or more\s*)?(?:years?|yrs)", re.IGNORECASE)
_MAX_RESUME_CHARS = 6000
_MIN_QUOTE_WORDS = 2


def _norm(text: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"[•▪●➢✓❖–—\-–—:;,.()\[\]'\"’]+", " ", text)).strip().lower()


def candidate_sources(candidate: CandidateEvidence, resumes: list[Resume]) -> list[tuple[str, str]]:
    """Everything the candidate has said about themselves, with an id per source."""
    p = candidate.profile
    sources: list[tuple[str, str]] = []
    if p.headline or p.summary:
        sources.append(("profile", f"{p.headline or ''}\n{p.summary or ''}".strip()))
    if p.skills:
        sources.append(("skills", ", ".join(s.name for s in p.skills)))
    if p.languages:
        sources.append(("languages", "Languages: " + ", ".join(p.languages)))
    for e in p.experiences:
        period = f"{e.start.year}-{e.end.year if e.end else 'present'}"
        sources.append((f"experience:{e.id}", f"{e.title} at {e.company} ({period})\n{experience_text(e)}\n{', '.join(e.skills)}"))
    for pr in p.projects:
        sources.append((f"project:{pr.id}", f"{pr.name}\n{pr.description}\n{', '.join(pr.skills)}"))
    for ed in p.education:
        sources.append((f"education:{ed.id}", f"{ed.degree}{', ' + ed.field if ed.field else ''}, {ed.institution}"))
    for r in resumes:
        sources.append((f"resume:{r.id}", r.text[:_MAX_RESUME_CHARS]))
    return sources


def _years_check(requirement: str, required: bool, candidate: CandidateEvidence) -> RequirementCheck | None:
    m = _YEARS.search(requirement)
    if not m or not re.search(r"experience|exp\b", requirement, re.IGNORECASE):
        return None
    needed, have = int(m.group(1)), candidate.total_years_experience
    return RequirementCheck(
        requirement=requirement, required=required,
        status="met" if have >= needed else ("partial" if have >= needed - 1 else "not_met"),
        evidence=[EvidenceQuote(source="profile", quote=f"{have} years of work experience in total")],
        note="Checked against your total work experience; make sure it is in the field the job asks for.",
    )


def check_requirements(
    job: JobAnalysis,
    items: list[tuple[str, bool]],
    candidate: CandidateEvidence,
    resumes: list[Resume],
    llm: LLMClient,
) -> tuple[list[RequirementCheck], list[str]]:
    """items: (requirement text, is_required). Returns checks and warnings."""
    checks: dict[str, RequirementCheck] = {}
    to_ask: list[tuple[str, bool]] = []
    for text, required in items:
        years = _years_check(text, required, candidate)
        if years:
            checks[text] = years
        else:
            to_ask.append((text, required))

    warnings: list[str] = []
    if to_ask:
        sources = candidate_sources(candidate, resumes)
        by_id = {sid: _norm(text) for sid, text in sources}
        raw = llm.generate_structured(
            prompt.SYSTEM,
            prompt.build_prompt(f"{job.title} at {job.company}", [t for t, _ in to_ask], sources),
            EvidenceMatchLLM,
            task="evidence",
            think=False,
            max_tokens=4096,
        )
        answers = {item.requirement.strip().lower(): item for item in raw.items}
        for i, (text, required) in enumerate(to_ask):
            item = answers.get(text.strip().lower()) or (raw.items[i] if i < len(raw.items) else None)
            if item is None:
                checks[text] = RequirementCheck(requirement=text, required=required, status="not_met")
                continue
            verified = []
            for q in item.quotes:
                quote = _norm(q.quote)
                if len(quote.split()) >= _MIN_QUOTE_WORDS and quote in by_id.get(q.source_id, ""):
                    verified.append(EvidenceQuote(source=q.source_id, quote=q.quote.strip()))
                else:
                    warnings.append(f"Ignored evidence that isn't in your {q.source_id.split(':')[0]}: {q.quote[:60]!r}")
            status = item.status
            if status in ("met", "partial") and not verified:
                status = "not_met"  # a claim without verifiable evidence doesn't count
            checks[text] = RequirementCheck(requirement=text, required=required, status=status, evidence=verified)

    return [checks[t] for t, _ in items], warnings


def apply_requirement_checks(match_result, checks: list[RequirementCheck]):
    """Fold evidence checks into the match: verified items count as covered, the rest as missing.
    Job conditions (not_applicable) are left out of the counts."""
    from app.models.application import MatchSummary, SkillMatch

    skills_required = list(match_result.matching_required)
    skills_preferred = list(match_result.matching_preferred)
    missing_required = [s for s in match_result.missing_required]
    missing_preferred = [s for s in match_result.missing_preferred]
    by_text = {c.requirement: c for c in checks}

    def moved(name: str) -> SkillMatch | None:
        c = by_text.get(name)
        if c and c.status == "met":
            return SkillMatch(skill=name, evidence=list(dict.fromkeys(e.source for e in c.evidence)), kind="skill")
        return None

    for name in list(missing_required):
        if m := moved(name):
            skills_required.append(m); missing_required.remove(name)
    for name in list(missing_preferred):
        if m := moved(name):
            skills_preferred.append(m); missing_preferred.remove(name)

    skill_names = {*match_result.missing_required, *match_result.missing_preferred}
    for c in checks:
        if c.requirement in skill_names or c.status == "not_applicable":
            continue
        target_ok, target_missing = (skills_required, missing_required) if c.required else (skills_preferred, missing_preferred)
        if c.status == "met":
            target_ok.append(SkillMatch(skill=c.requirement, evidence=list(dict.fromkeys(e.source for e in c.evidence)), kind="requirement"))
        else:
            target_missing.append(c.requirement)

    checks = [c.model_copy(update={"kind": "skill" if c.requirement in skill_names else "requirement"}) for c in checks]
    return match_result.model_copy(update={
        "matching_required": skills_required,
        "matching_preferred": skills_preferred,
        "missing_required": missing_required,
        "missing_preferred": missing_preferred,
        "requirement_checks": checks,
        "summary": MatchSummary(
            required_covered=len(skills_required), required_total=len(skills_required) + len(missing_required),
            preferred_covered=len(skills_preferred), preferred_total=len(skills_preferred) + len(missing_preferred),
        ),
    })
