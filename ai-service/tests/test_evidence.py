from datetime import date

from app.llm.client import FakeLLMClient
from app.models.candidate import CandidateProfile, Education, Experience, Project, SkillEntry
from app.models.job import JobAnalysis
from app.models.resume import Resume
from app.pipelines.candidate_analysis import analyze_candidate
from app.pipelines.evidence import apply_requirement_checks, check_requirements
from app.pipelines.matching import match
from app.pipelines.resume_selection import select_resume
from app.rules.models import CVRules

# A fictional quantity surveyor in the style of the course resumes (no real person's data).
QS = CandidateProfile(
    name="Test Candidate", email="qs@example.org", phone="+95 9 000 000",
    skills=[SkillEntry(name="AutoCAD"), SkillEntry(name="Microsoft Excel")],
    experiences=[Experience(
        id="exp_qs", title="Quantity Surveyor", company="Golden Build Co., Ltd", start=date(2019, 1, 1), end=None,
        summary="Quantity surveying for residential and hydropower projects.",
        achievements=["Prepared Bills of Quantities and cost estimates for tender submission",
                      "Negotiated progress claims and variation orders with sub-contractors"],
    )],
    projects=[Project(id="proj_tower", name="Riverside Tower", description="Measured quantities from construction drawings")],
    education=[Education(id="edu_be", institution="Technological University", degree="Bachelor of Engineering", field="Civil")],
)
RESUME = Resume(id="cv_qs", filename="qs.pdf", format="pdf",
                text="QUANTITY SURVEYOR\nPrepared Bills of Quantities and cost estimates for tender submission\nAutoCAD, Excel")
JOB = JobAnalysis(
    title="Quantity Surveyor", company="Build Co", seniority="", location="", country="Singapore",
    required_skills=[], preferred_skills=[], responsibilities=[], keywords=[],
    requirements=["Bachelor's degree in quantity surveying or engineering", "Experience preparing quantity estimates",
                  "Experience with progress claims", "3+ years of experience", "May need to work on weekends",
                  "Knowledge of Revit"],
)
TODAY = date(2026, 10, 1)


def _llm():
    return FakeLLMClient([{"items": [
        {"requirement": "Bachelor's degree in quantity surveying or engineering", "status": "met",
         "quotes": [{"source_id": "education:edu_be", "quote": "Bachelor of Engineering, Civil"}]},
        {"requirement": "Experience preparing quantity estimates", "status": "met",
         "quotes": [{"source_id": "resume:cv_qs", "quote": "Prepared Bills of Quantities and cost estimates"}]},
        {"requirement": "Experience with progress claims", "status": "met",
         "quotes": [{"source_id": "experience:exp_qs", "quote": "Negotiated progress claims and variation orders"}]},
        {"requirement": "May need to work on weekends", "status": "not_applicable", "quotes": []},
        # Invented evidence: the profile never mentions Revit.
        {"requirement": "Knowledge of Revit", "status": "met",
         "quotes": [{"source_id": "experience:exp_qs", "quote": "Modelled buildings in Revit"}]},
    ]}])


def test_quotes_are_verified_and_years_checked_by_code():
    candidate = analyze_candidate(QS, [RESUME], today=TODAY)
    llm = _llm()
    items = [(r, True) for r in JOB.requirements]
    checks, warnings = check_requirements(JOB, items, candidate, [RESUME], llm)
    by = {c.requirement: c for c in checks}

    assert by["Experience preparing quantity estimates"].status == "met"
    assert by["Experience with progress claims"].evidence[0].source == "experience:exp_qs"
    assert by["3+ years of experience"].status == "met"  # 7 years from the work history, no AI
    assert "3+ years of experience" not in llm.calls[0]["prompt"].split("REQUIREMENTS:")[1].split("CANDIDATE")[0]
    assert by["Knowledge of Revit"].status == "not_met"  # quote not in the source -> doesn't count
    assert any("Modelled buildings in Revit" in w for w in warnings)
    assert by["May need to work on weekends"].status == "not_applicable"


def test_fit_counts_and_resume_could_add():
    candidate = analyze_candidate(QS, [RESUME], today=TODAY)
    checks, _ = check_requirements(JOB, [(r, True) for r in JOB.requirements], candidate, [RESUME], _llm())
    result = apply_requirement_checks(match(JOB, candidate), checks)

    # weekend work is a job condition: excluded. 4 of the 5 qualifications are proven.
    assert (result.summary.required_covered, result.summary.required_total) == (4, 5)
    assert result.missing_required == ["Knowledge of Revit"]
    assert all(m.kind == "requirement" for m in result.matching_required)

    sel = select_resume(JOB, candidate, result, {"cv_qs": RESUME.text}, CVRules())
    score = sel.scores[0]
    assert "Experience preparing quantity estimates" in score.required_covered  # quoted from this resume
    could = {a.skill for a in score.could_add}
    assert "Experience with progress claims" in could  # profile proves it, the resume doesn't say it
    assert "Knowledge of Revit" in score.gaps
    assert "Covers" in sel.reason and "/5 requirements" in sel.reason


def test_checks_are_tagged_as_skill_or_requirement():
    from app.models.application import MatchResult, MatchSummary
    from app.models.evidence import EvidenceQuote, RequirementCheck
    from app.pipelines.evidence import apply_requirement_checks

    base = MatchResult.model_construct(
        matching_required=[], matching_preferred=[], missing_required=["Tendering"], missing_preferred=[],
        relevant_experience=[], relevant_projects=[], relevant_education=[], requirement_checks=[],
        summary=MatchSummary(required_covered=0, required_total=1, preferred_covered=0, preferred_total=0),
    )
    quote = [EvidenceQuote(source="experience:exp1", quote="negotiate with sub-contractors for tender award")]
    out = apply_requirement_checks(base, [
        RequirementCheck(requirement="Tendering", required=True, status="met", evidence=quote),
        RequirementCheck(requirement="Degree in Quantity Surveying", required=True, status="not_met"),
    ])
    assert [c.kind for c in out.requirement_checks] == ["skill", "requirement"]
    assert [m.kind for m in out.matching_required] == ["skill"]
    assert out.missing_required == ["Degree in Quantity Surveying"]


def test_duty_only_posting_checks_duties(profile, resumes):
    from app.llm.client import FakeLLMClient
    from app.models.job import JobInput
    from app.pipeline import run_match

    duties = {"title": "Production Engineer", "company": "", "seniority": "", "location": "", "country": "",
              "required_skills": [], "preferred_skills": [], "requirements": [], "keywords": [],
              "responsibilities": ["Production planning", "Reduce waste and scrap"]}
    llm = FakeLLMClient([duties])
    result = run_match(JobInput(description="- Production planning\n- Reduce waste and scrap"), profile, resumes, llm)
    assert [c.requirement for c in result.match.requirement_checks] == ["Production planning", "Reduce waste and scrap"]
    assert result.match.summary.required_total == 2


def test_retry_prompt_does_not_echo_the_invalid_answer():
    from app.llm.client import FakeLLMClient
    from app.models.job import JobAnalysis

    runaway = '{"title": "' + "repeat " * 500
    llm = FakeLLMClient([runaway, {"title": "QS", "company": "", "seniority": "", "location": "", "country": "",
                                   "required_skills": [], "preferred_skills": [], "requirements": [],
                                   "keywords": [], "responsibilities": []}])
    llm.generate_structured("sys", "prompt", JobAnalysis, max_tokens=3000)
    assert "repeat repeat" not in llm.calls[1]["prompt"]
    assert llm.calls[0]["max_tokens"] == 3000


def test_languages_can_be_quoted():
    profile = QS.model_copy(update={"languages": ["English", "Myanmar"]})
    candidate = analyze_candidate(profile, [RESUME], today=TODAY)
    llm = FakeLLMClient([{"items": [{"requirement": "Proficiency in English", "status": "met",
                                     "quotes": [{"source_id": "languages", "quote": "Languages: English, Myanmar"}]}]}])
    checks, warnings = check_requirements(JOB, [("Proficiency in English", True)], candidate, [RESUME], llm)
    assert checks[0].status == "met" and not warnings
