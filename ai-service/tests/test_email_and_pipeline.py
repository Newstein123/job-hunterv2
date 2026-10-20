from datetime import datetime, timezone

from app.llm.client import FakeLLMClient
from app.models.application import EmailDraft
from app.pipeline import run_pipeline
from app.pipelines.email_generation import check_grounding
from app.rules.models import Rules
from app.rules.validator import check_email_rules
from tests.conftest import EXAMPLES, good_draft

NOW = datetime(2026, 9, 30, 2, 0, tzinfo=timezone.utc)


def _draft(**overrides) -> EmailDraft:
    return EmailDraft.model_validate({**good_draft(), **overrides})


def test_good_draft_passes_rules():
    assert check_email_rules(_draft(), Rules(), include_cover_letter=False) == []


def test_rules_catch_word_limit_paragraphs_salary_and_claims():
    rules = Rules.model_validate({"email_rules": {"max_words": 40}, "application_rules": {"claims_to_avoid": ["rockstar"]}})
    draft = _draft(paragraphs=["I am a rockstar engineer.", "My expected salary is SGD 9000."])
    errors = check_email_rules(draft, rules, include_cover_letter=True)
    joined = " | ".join(errors)
    assert "Expected 3 body paragraphs" in joined
    assert "salary" in joined
    assert "rockstar" in joined
    assert "cover letter was requested" in joined
    long = _draft(paragraphs=["word " * 30] * 3)
    assert any("max is 40" in e for e in check_email_rules(long, rules, False))


def test_grounding_catches_invented_skill_years_and_metrics(backend_analysis, candidate):
    text = "I have 12 years of experience with Kubernetes and Symfony, and cut latency by 45%."
    errors, _ = check_grounding(text, backend_analysis, candidate, do_not_claim=["Kubernetes"])
    joined = " | ".join(errors)
    assert "'Kubernetes'" in joined and "'Symfony'" in joined
    assert "12 years" in joined
    assert "45%" in joined


def test_grounding_accepts_supported_claims(backend_analysis, candidate):
    text = " ".join(good_draft()["paragraphs"]) + " I have 7 years of PHP experience."
    errors, warnings = check_grounding(text, backend_analysis, candidate, do_not_claim=["Kubernetes"])
    assert errors == [] and warnings == []


def test_grounding_warns_on_unknown_org(backend_analysis, candidate):
    _, warnings = check_grounding("I previously worked at Globex Corporation.", backend_analysis, candidate, [])
    assert warnings and "Globex Corporation" in warnings[0]


def test_pipeline_end_to_end_with_repair(profile, resumes, backend_job_input, backend_llm_analysis):
    bad = good_draft()
    bad["paragraphs"] = bad["paragraphs"][:2] + ["I also have deep Kubernetes experience. Resume attached."]
    llm = FakeLLMClient([backend_llm_analysis, bad, good_draft()])
    result = run_pipeline(backend_job_input, profile, resumes, llm,
                          user_rules_path=EXAMPLES / "rules" / "user_rules.json", now=NOW)

    assert result.resume_selection.recommended_resume_id == "backend_developer"
    assert result.skill_strategy.do_not_claim == ["Kubernetes"]
    assert result.application_strategy.include_cover_letter is False
    assert result.application_strategy.send_timezone == "Asia/Singapore"
    email = result.email
    assert email.validation.ok and email.validation.attempts == 2
    assert "Kubernetes" in llm.calls[2]["prompt"]  # repair prompt lists the violation
    assert "Senior Backend Engineer" in email.subject and "Alex Tan" in email.subject
    assert email.attachment == "backend_developer.pdf"
    assert email.body.endswith("https://www.linkedin.com/in/alex-tan-example")
    assert result.meta.prompt_versions["job_analysis"] == "job_analysis.v2"


def test_pipeline_surfaces_unfixed_errors(profile, resumes, backend_job_input, backend_llm_analysis):
    bad = good_draft()
    bad["paragraphs"] = ["Too few paragraphs."]
    result = run_pipeline(backend_job_input, profile, resumes, FakeLLMClient([backend_llm_analysis, bad, bad]),
                          now=NOW)
    assert not result.email.validation.ok
    assert any("edit before approving" in w for w in result.meta.warnings)


def test_stored_analysis_skips_the_extraction_call(profile, resumes, backend_job_input, backend_analysis):
    llm = FakeLLMClient([good_draft()])  # only the email; no job-analysis response queued
    result = run_pipeline(backend_job_input, profile, resumes, llm, now=NOW, stored_analysis=backend_analysis)
    assert result.resume_selection.recommended_resume_id == "backend_developer"
    assert [u.task for u in result.meta.usage] == ["evidence", "email"]


def test_usage_is_reported_and_extraction_runs_without_thinking(profile, resumes, backend_job_input, backend_llm_analysis):
    llm = FakeLLMClient([backend_llm_analysis, good_draft()])
    result = run_pipeline(backend_job_input, profile, resumes, llm, now=NOW)
    assert [u.task for u in result.meta.usage] == ["job_analysis", "evidence", "email"]
    assert all(u.input_tokens > 0 for u in result.meta.usage)
    assert llm.calls[0]["think"] is False  # job analysis
    assert llm.calls[1]["think"] is False  # evidence matching
    assert llm.calls[2]["think"] is True  # writing the email


def test_subject_rotates_across_jobs_but_is_stable_per_job(backend_analysis):
    from app.models.job import JobAnalysis
    from app.pipelines.email_generation import pick_subject_format
    from app.rules.models import COURSE_SUBJECT_FORMATS

    rules = Rules()
    assert pick_subject_format(rules, backend_analysis) == pick_subject_format(rules, backend_analysis)
    jobs = [backend_analysis.model_copy(update={"company": f"Company {i}"}) for i in range(12)]
    used = {pick_subject_format(rules, j) for j in jobs}
    assert len(used) >= 3 and used <= set(COURSE_SUBJECT_FORMATS)
    fixed = Rules.model_validate({"email_rules": {"subject_format": "{position} - {candidate_name}"}})
    assert pick_subject_format(fixed, backend_analysis) == "{position} - {candidate_name}"


def test_plain_text_rule_rejects_bold_and_bullets():
    bold = _draft(paragraphs=["I have **strong** Laravel skills.", "Second.", "Third."])
    assert any("plain sentences" in e for e in check_email_rules(bold, Rules(), False))
    bullets = _draft(paragraphs=["Skills:\n- Laravel\n- PHP", "Second.", "Third."])
    assert any("plain sentences" in e for e in check_email_rules(bullets, Rules(), False))
    assert check_email_rules(_draft(), Rules(), False) == []


def test_signature_marks_whatsapp(profile, resumes, backend_job_input, backend_llm_analysis):
    same = profile.model_copy(update={"whatsapp": profile.phone})
    result = run_pipeline(backend_job_input, same, resumes, FakeLLMClient([backend_llm_analysis, good_draft()]), now=NOW)
    assert f"{profile.phone} (WhatsApp)" in result.email.body
    other = profile.model_copy(update={"whatsapp": "+959 7000 0000"})
    result = run_pipeline(backend_job_input, other, resumes, FakeLLMClient([backend_llm_analysis, good_draft()]), now=NOW)
    assert "WhatsApp: +959 7000 0000" in result.email.body


def test_spelled_out_years_are_checked_too(backend_analysis, candidate):
    errors, _ = check_grounding("With twelve years of experience in Laravel.", backend_analysis, candidate, [])
    assert any("12 years" in e for e in errors)
    ok, _ = check_grounding("With eight years of experience in Laravel.", backend_analysis, candidate, [])
    assert ok == []  # the profile covers 8 years


def test_location_and_availability_only_when_in_profile(backend_analysis, candidate):
    text = "I am currently based in Bangkok and available to start immediately."
    bare = candidate.model_copy(update={"profile": candidate.profile.model_copy(update={"location": None})})
    errors, _ = check_grounding(text, backend_analysis, bare, [])
    assert any("current location" in e for e in errors) and any("availability" in e for e in errors)

    profile = candidate.profile.model_copy(update={"location": "Bangkok, Thailand", "availability": "Immediately"})
    errors, _ = check_grounding(text, backend_analysis, candidate.model_copy(update={"profile": profile}), [])
    assert errors == []
    # Being available for an interview is not a start-date claim.
    errors, _ = check_grounding("I am available for an interview at your convenience.", backend_analysis, candidate, [])
    assert errors == []
