import json
from datetime import datetime, timezone

from app.llm.client import FakeLLMClient
from app.models.job import JobInput
from app.pipeline import run_pipeline
from tests.conftest import good_draft

NOW = datetime(2026, 9, 30, 2, 0, tzinfo=timezone.utc)

JOB = JobInput(
    kind="speculative", description="Company: Acme Web\nIndustry: IT Consulting\nSpecialties: payments, web design",
    position="Senior Backend Engineer", company="Acme Web", country="Singapore",
    industry="Information & Communications Technology (ICT), IT Consulting", specialties="payments,web design",
    contact_email="info@acmeweb.example",
)


def _draft(**overrides):
    d = good_draft()
    d["greeting"] = "Dear Acme Web Hiring Team,"
    d["paragraphs"] = [
        "I am a Senior PHP Developer seeking a Senior Backend Engineer position, and your work in payments "
        "is close to what I do every day.",
        d["paragraphs"][1],
        "I would be glad to be considered for a current or future opening. My resume is attached.",
    ]
    return {**d, **overrides}


def test_speculative_skips_analysis_and_uses_company_list_only(profile, resumes):
    llm = FakeLLMClient([_draft()])
    result = run_pipeline(JOB, profile, resumes[:1], llm, now=NOW)

    assert [c["schema"] for c in llm.calls] == ["EmailDraft"]  # no job analysis, no evidence call
    assert result.job_analysis.keywords == ["payments", "web design"]
    assert result.application_strategy.letter_style == "speculative"
    assert result.skill_strategy.emphasize  # the candidate's own strongest skills
    assert result.skill_strategy.relevant_experience
    facts = json.loads(llm.calls[0]["prompt"].split("JOB:\n", 1)[1].split("\n\nCANDIDATE_FACTS", 1)[0])
    assert facts["company_specialties"] == ["payments", "web design"] and "required_skills" not in facts
    assert result.email.validation.ok, result.email.validation.errors


def test_speculative_email_must_not_claim_an_advert(profile, resumes):
    bad = _draft()
    bad["paragraphs"] = ["I am applying for the role you advertised on your website."] + bad["paragraphs"][1:]
    llm = FakeLLMClient([bad, bad])
    result = run_pipeline(JOB, profile, resumes[:1], llm, now=NOW)
    assert any("advertised" in e for e in result.email.validation.errors)
