"""Run the API with a template-based fake LLM, so the web app can be developed without a Gemini key.

    .venv/bin/python -m scripts.dev_fake_llm_server   (serves on 127.0.0.1:8001, reads .env)

DEV ONLY. The "analysis" lists vocabulary skills found in the posting, and the "email" is a fixed template
filled from the prompt's candidate facts. It exercises the real pipeline, rules and validation, but it is
not a substitute for evaluating the real model.
"""

import json
import re

import uvicorn
from dotenv import load_dotenv
from pydantic import BaseModel

from app.api.main import app, get_llm
from app.llm.client import LLMClient
from app.models.application import EmailDraft
from app.models.job import JobAnalysis
from app.models.usage import LLMUsage
from app.skills.normalize import find_skills_in_text, known_vocabulary


def _section(prompt: str, name: str) -> dict:
    match = re.search(rf"{name}:\n(\{{.*?\n\}})", prompt, re.DOTALL)
    return json.loads(match.group(1)) if match else {}


class TemplateLLM(LLMClient):
    model = "dev-template"

    def _generate(
        self, system: str, prompt: str, schema: type[BaseModel], think: bool, max_tokens: int
    ) -> tuple[str, LLMUsage]:
        return self._text(prompt, schema), LLMUsage(task="llm", model=self.model)

    def _text(self, prompt: str, schema: type[BaseModel]) -> str:
        if schema.__name__ == "EvidenceMatchLLM":
            return '{"items": []}'
        if schema is JobAnalysis:
            description = prompt.split("<<<", 1)[-1].split(">>>", 1)[0]
            first_line = description.strip().splitlines()[0] if description.strip() else ""
            return JobAnalysis(
                title=first_line[:80], company="", seniority="", location="", country="",
                required_skills=find_skills_in_text(description, known_vocabulary()),
                preferred_skills=[], responsibilities=[], keywords=[], requirements=[],
            ).model_dump_json()

        job, candidate, strategy = _section(prompt, "JOB"), _section(prompt, "CANDIDATE_FACTS"), _section(prompt, "STRATEGY")
        experience = (candidate.get("relevant_experience") or [{}])[0]
        project = candidate.get("relevant_project")
        skills = ", ".join(candidate.get("skills_to_emphasize", [])[:4]) or "backend development"
        paragraphs = [
            f"I am writing to apply for the {job.get('title', 'advertised')} role"
            f"{' at ' + job['company'] if job.get('company') else ''}.",
            f"In my role as {experience.get('title', 'a developer')}"
            f"{' at ' + experience['company'] if experience.get('company') else ''}, I have worked with {skills}."
            + (f" I also built {project['name']}." if project else ""),
            f"My resume is attached. {strategy.get('cta', '')}".strip(),
        ]
        count = strategy.get("paragraph_count", 3)
        paragraphs = (paragraphs + ["Thank you for your time."] * count)[:count]
        cover = ["I am excited to apply.", "My experience matches the role."] if strategy.get("include_cover_letter") else []
        return EmailDraft(greeting="Dear Hiring Manager,", paragraphs=paragraphs, closing="Kind regards,",
                          cover_letter_paragraphs=cover).model_dump_json()


if __name__ == "__main__":
    load_dotenv()
    app.dependency_overrides[get_llm] = TemplateLLM
    uvicorn.run(app, host="127.0.0.1", port=8001)
