from app.models.job import JobAnalysis
from app.pipelines.candidate_analysis import total_years_experience
from app.pipelines.matching import match
from app.pipelines.resume_selection import select_resume
from app.pipelines.skill_strategy import build_skill_strategy
from app.rules.models import ApplicationRules, CVRules
from tests.conftest import TODAY


def _texts(resumes):
    return {r.id: r.text for r in resumes}


def test_candidate_evidence_is_traceable(candidate):
    assert "experience:exp_senior_php" in candidate.skill_evidence["Laravel"]
    assert "project:proj_kwinchain" in candidate.skill_evidence["Laravel"]
    assert "resume:backend_developer" in candidate.skill_evidence["Laravel"]
    assert candidate.skill_evidence["GraphQL"] == ["skill_list"]
    backend = next(r for r in candidate.resumes if r.resume_id == "backend_developer")
    assert "proj_kwinchain" in backend.project_ids and "exp_senior_php" in backend.experience_ids


def test_total_years_counts_overlaps_once(profile):
    assert total_years_experience(profile.experiences, TODAY) == 8


def test_matching_is_explainable(backend_analysis, candidate):
    result = match(backend_analysis, candidate)
    assert [m.skill for m in result.matching_required] == ["PHP", "Laravel", "MySQL", "REST API", "Docker"]
    assert result.missing_preferred == ["Kubernetes"]
    assert result.summary.required_covered == 5 and result.summary.required_total == 5
    assert result.relevant_experience[0].id == "exp_senior_php"
    assert result.relevant_projects[0].id == "proj_kwinchain"
    assert all(m.evidence for m in result.matching_required)


def test_backend_job_selects_backend_resume(backend_analysis, candidate, resumes):
    m = match(backend_analysis, candidate)
    sel = select_resume(backend_analysis, candidate, m, _texts(resumes), CVRules(preferred_formats=["pdf"]))
    assert sel.recommended_resume_id == "backend_developer"
    assert set(sel.alternative_resume_ids) == {"fullstack_developer", "cloud_engineer"}
    assert "5/5 requirements" in sel.reason
    assert "KwinChain" in sel.relevant_projects


def test_fullstack_job_selects_fullstack_resume(candidate, resumes):
    job = JobAnalysis(
        title="Full Stack Developer", company="Orchard Commerce", seniority="", location="", country="",
        required_skills=["PHP", "Laravel", "Vue.js", "JavaScript", "MySQL", "Git"],
        preferred_skills=["TypeScript", "Tailwind CSS"], responsibilities=[], keywords=["e-commerce"],
        requirements=[],
    )
    m = match(job, candidate)
    sel = select_resume(job, candidate, m, _texts(resumes), CVRules())
    assert sel.recommended_resume_id == "fullstack_developer"
    assert m.missing_preferred == ["TypeScript", "Tailwind CSS"]


def test_cloud_job_selects_cloud_resume(candidate, resumes):
    job = JobAnalysis(
        title="Platform Engineer", company="X", seniority="", location="", country="",
        required_skills=["AWS", "Docker", "Redis"], preferred_skills=["Linux"], responsibilities=[],
        keywords=[], requirements=[],
    )
    m = match(job, candidate)
    sel = select_resume(job, candidate, m, _texts(resumes), CVRules())
    # backend and cloud both cover all required; cloud wins on the preferred skill (Linux).
    assert sel.recommended_resume_id == "cloud_engineer"


def test_skill_strategy(backend_analysis, candidate, resumes):
    m = match(backend_analysis, candidate)
    sel = select_resume(backend_analysis, candidate, m, _texts(resumes), CVRules())
    strategy = build_skill_strategy(m, sel, candidate, ApplicationRules(skills_to_emphasize=["GraphQL"]))
    assert strategy.emphasize == ["PHP", "Laravel", "MySQL", "REST API", "Docker"]
    assert "GraphQL" not in strategy.emphasize  # only weak evidence
    assert strategy.supporting_skills == ["Redis", "AWS"]
    assert strategy.do_not_claim == ["Kubernetes"]
    assert strategy.relevant_project == "proj_kwinchain"


def test_skill_strategy_respects_mention_projects(backend_analysis, candidate, resumes):
    m = match(backend_analysis, candidate)
    sel = select_resume(backend_analysis, candidate, m, _texts(resumes), CVRules())
    strategy = build_skill_strategy(m, sel, candidate, ApplicationRules(mention_projects=False, max_emphasized_skills=2))
    assert strategy.relevant_project is None
    assert len(strategy.emphasize) == 2


def test_cv_analysis_lists_addable_skills_and_gaps(candidate, resumes):
    job = JobAnalysis(
        title="Backend", company="X", seniority="", location="", country="",
        required_skills=["PHP", "Laravel", "Vue.js"], preferred_skills=["Kubernetes", "Redis"],
        responsibilities=[], keywords=[], requirements=[],
    )
    m = match(job, candidate)
    sel = select_resume(job, candidate, m, _texts(resumes), CVRules())
    backend = next(s for s in sel.scores if s.resume_id == "backend_developer")
    # The backend CV doesn't mention Vue.js, but the profile proves it (full stack role, ShopDash).
    vue = next(a for a in backend.could_add if a.skill == "Vue.js")
    assert vue.required is True
    assert "experience: Full Stack Developer at Lumen Retail" in vue.evidence
    assert "project: ShopDash" in vue.evidence
    # Kubernetes: no evidence anywhere, so it's a gap, never a suggestion.
    assert backend.gaps == ["Kubernetes"]
    assert all(a.skill != "Kubernetes" for a in backend.could_add)
    assert backend.word_count and backend.page_count == 1


def test_resume_scores_include_ats_keyword_match(backend_analysis, candidate, profile, resumes):
    from app.pipelines.matching import match
    from app.pipelines.resume_selection import select_resume
    from app.rules.models import CVRules

    selection = select_resume(backend_analysis, candidate, match(backend_analysis, candidate),
                              {r.id: r.text for r in resumes}, CVRules())
    best = next(s for s in selection.scores if s.resume_id == selection.recommended_resume_id)
    terms = len(set(backend_analysis.required_skills + backend_analysis.preferred_skills + backend_analysis.keywords))
    assert len(best.ats_found) + len(best.ats_missing) == terms
    assert best.ats_match == round(100 * len(best.ats_found) / terms)
    assert "PHP" in best.ats_found
