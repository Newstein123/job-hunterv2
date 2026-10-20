import pytest

from app.llm.client import FakeLLMClient, LLMOutputError
from app.pipelines.job_analysis import analyze_job


def test_drops_unsupported_skills_and_normalizes(backend_job_input, backend_llm_analysis):
    analysis, warnings = analyze_job(backend_job_input, FakeLLMClient([backend_llm_analysis]))
    assert analysis.required_skills == ["PHP", "Laravel", "MySQL", "REST API", "Docker"]
    assert "Symfony" not in analysis.required_skills
    assert any("Symfony" in w for w in warnings)
    assert analysis.preferred_skills == ["Redis", "AWS", "Kubernetes"]


def test_user_metadata_overrides_extraction(backend_job_input, backend_llm_analysis):
    analysis, _ = analyze_job(backend_job_input, FakeLLMClient([backend_llm_analysis]))
    assert analysis.title == "Senior Backend Engineer"


def test_invalid_json_retries_then_succeeds(backend_job_input, backend_llm_analysis):
    llm = FakeLLMClient(['{"title": "oops"}', backend_llm_analysis])
    analysis, _ = analyze_job(backend_job_input, llm)
    assert analysis.company == "Merlion Pay"
    assert len(llm.calls) == 2 and "previous answer was invalid" in llm.calls[1]["prompt"]


def test_invalid_json_twice_raises(backend_job_input):
    with pytest.raises(LLMOutputError):
        analyze_job(backend_job_input, FakeLLMClient(["not json", '{"title": 1}']))
