import base64

import pytest
from fastapi.testclient import TestClient

from app.api.main import app, get_llm
from app.llm.client import FakeLLMClient
from tests.conftest import EXAMPLES, good_draft

TOKEN = "test-token"


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setenv("AI_SERVICE_TOKEN", TOKEN)
    yield TestClient(app)
    app.dependency_overrides.clear()


def _use_llm(responses):
    llm = FakeLLMClient(responses)
    app.dependency_overrides[get_llm] = lambda: llm
    return llm


def _resume(resume_id: str, name: str) -> dict:
    content = (EXAMPLES / "resumes" / name).read_bytes()
    return {"id": resume_id, "filename": name, "content_base64": base64.b64encode(content).decode()}


def _job():
    return {"description": (EXAMPLES / "jobs" / "backend_laravel.txt").read_text(),
            "position": "Senior Backend Engineer", "company": "Merlion Pay", "country": "Singapore"}


def test_health(client):
    assert client.get("/health").json() == {"status": "ok"}


def test_token_required(client):
    assert client.post("/v1/jobs/analyze", json={"job": _job()}).status_code == 401
    bad = client.post("/v1/jobs/analyze", json={"job": _job()}, headers={"X-Service-Token": "nope"})
    assert bad.status_code == 401


def test_analyze(client, backend_llm_analysis):
    _use_llm([backend_llm_analysis])
    res = client.post("/v1/jobs/analyze", json={"job": _job()}, headers={"X-Service-Token": TOKEN})
    assert res.status_code == 200
    body = res.json()
    assert body["analysis"]["required_skills"] == ["PHP", "Laravel", "MySQL", "REST API", "Docker"]
    assert body["prompt_version"] == "job_analysis.v2"


def test_generate_maps_caller_resume_ids(client, profile, backend_llm_analysis):
    _use_llm([backend_llm_analysis, good_draft()])
    payload = {
        "job": _job(),
        "profile": profile.model_dump(mode="json"),
        "resumes": [_resume("12", "backend_developer.pdf"), _resume("13", "fullstack_developer.docx")],
        "user_rules": {"email_rules": {"max_words": 180}},
    }
    res = client.post("/v1/applications/generate", json=payload, headers={"X-Service-Token": TOKEN})
    assert res.status_code == 200, res.text
    body = res.json()
    assert body["resume_selection"]["recommended_resume_id"] == "12"
    assert body["email"]["attachment"] == "backend_developer.pdf"
    assert "user_rules:inline" in body["meta"]["rules_sources"]


def test_generate_rejects_bad_input(client, profile):
    _use_llm([])
    headers = {"X-Service-Token": TOKEN}
    base = {"job": _job(), "profile": profile.model_dump(mode="json")}
    bad_b64 = client.post("/v1/applications/generate", headers=headers,
                          json={**base, "resumes": [{"id": "1", "filename": "a.pdf", "content_base64": "%%%"}]})
    assert bad_b64.status_code == 422
    bad_rules = client.post("/v1/applications/generate", headers=headers,
                            json={**base, "resumes": [_resume("1", "cloud_engineer.md")],
                                  "user_rules": {"email_rules": {"max_wrds": 1}}})
    assert bad_rules.status_code == 422


def test_llm_failure_is_502(client, profile):
    _use_llm(["garbage", "garbage"])
    res = client.post("/v1/jobs/analyze", json={"job": _job()}, headers={"X-Service-Token": TOKEN})
    assert res.status_code == 502


def test_match_preview_runs_one_llm_call(client, profile, backend_llm_analysis):
    llm = _use_llm([backend_llm_analysis])
    payload = {"job": _job(), "profile": profile.model_dump(mode="json"),
               "resumes": [_resume("12", "backend_developer.pdf"), _resume("13", "fullstack_developer.docx")]}
    res = client.post("/v1/jobs/match", json=payload, headers={"X-Service-Token": TOKEN})
    assert res.status_code == 200, res.text
    body = res.json()
    assert [c["schema"] for c in llm.calls] == ["JobAnalysis", "EvidenceMatchLLM"]  # no email drafted
    assert body["resume_selection"]["recommended_resume_id"] == "12"
    assert body["match"]["summary"]["required_covered"] == 6  # 5 skills + "5+ years" checked against 8 years
    assert "email" not in body
