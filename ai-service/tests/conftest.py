from datetime import date
from pathlib import Path

import pytest

from app.documents.extract import load_resumes
from app.models.candidate import CandidateProfile
from app.models.job import JobAnalysis, JobInput
from app.pipelines.candidate_analysis import analyze_candidate
from app.rules.loader import load_rules

EXAMPLES = Path(__file__).parent.parent / "examples"
TODAY = date(2026, 9, 30)


@pytest.fixture
def profile() -> CandidateProfile:
    return CandidateProfile.model_validate_json((EXAMPLES / "candidate_profile.json").read_text())


@pytest.fixture
def resumes():
    return load_resumes(EXAMPLES / "resumes")


@pytest.fixture
def candidate(profile, resumes):
    return analyze_candidate(profile, resumes, today=TODAY)


@pytest.fixture
def backend_job_input() -> JobInput:
    return JobInput(
        description=(EXAMPLES / "jobs" / "backend_laravel.txt").read_text(),
        position="Senior Backend Engineer",
        company="Merlion Pay",
        country="Singapore",
    )


@pytest.fixture
def backend_llm_analysis() -> dict:
    """What a well-behaved LLM returns for the backend job (plus one invented skill)."""
    return {
        "title": "Senior Backend Engineer (PHP/Laravel)",
        "company": "Merlion Pay",
        "seniority": "senior",
        "location": "Singapore",
        "country": "Singapore",
        "required_skills": ["PHP", "Laravel", "MySQL", "REST APIs", "Docker", "Symfony"],
        "preferred_skills": ["Redis", "AWS", "Kubernetes"],
        "responsibilities": ["Design and build REST APIs", "Mentor junior engineers"],
        "keywords": ["fintech", "payments"],
        "requirements": ["5+ years of backend development experience"],
    }


@pytest.fixture
def backend_analysis(backend_llm_analysis) -> JobAnalysis:
    data = dict(backend_llm_analysis)
    data["required_skills"] = ["PHP", "Laravel", "MySQL", "REST API", "Docker"]
    data["title"] = "Senior Backend Engineer"
    return JobAnalysis.model_validate(data)


@pytest.fixture
def sg_rules():
    rules, _, _ = load_rules("Singapore")
    return rules


def good_draft(cover_letter: bool = False) -> dict:
    return {
        "greeting": "Dear Merlion Pay Hiring Team,",
        "paragraphs": [
            "I am applying for the Senior Backend Engineer role at Merlion Pay. As a Senior PHP Developer at "
            "Harbour Payments, I lead the merchant payments API built with Laravel and MySQL.",
            "I have designed REST APIs used by 300+ merchant integrations and moved services to Docker-based "
            "deployments. My open-source KwinChain package also uses Laravel and Docker.",
            "My resume is attached. I would welcome the opportunity to discuss how I can contribute to the team.",
        ],
        "closing": "Kind regards,",
        "cover_letter_paragraphs": ["Cover letter paragraph one.", "Paragraph two."] if cover_letter else [],
    }
