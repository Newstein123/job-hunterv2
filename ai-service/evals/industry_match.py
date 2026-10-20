"""Real-Gemini check of evidence matching on public non-IT job posts, using fictional profiles only.

    python -m evals.industry_match            # all jobs
    python -m evals.industry_match qs         # one profile
"""

import json
import sys
from pathlib import Path

from app.config import get_settings
from app.documents.extract import load_resume
from app.llm.client import GeminiClient, LLMOutputError
from app.models.candidate import CandidateProfile
from app.models.job import JobInput
from app.pipeline import run_match

HERE = Path(__file__).parent
PROFILE_FOR = {
    "quantity_surveyor": "qs", "mep_engineer": "mep", "site_engineer": "mep", "electrical_engineer": "mep",
    "draftsman": "qs", "production_engineer": "mep", "accountant": "accountant",
}


def main(only: str | None = None) -> None:
    settings = get_settings()
    llm = GeminiClient(settings.gemini_api_key, settings.gemini_model)
    rows = []
    for path in sorted((HERE / "industry_jobs").glob("*.txt")):
        role = path.stem.removeprefix("sg_").rsplit("_", 1)[0]
        key = PROFILE_FOR[role]
        if only and key != only:
            continue
        profile = CandidateProfile.model_validate_json((HERE / "profiles" / f"{key}.json").read_text())
        resume = load_resume(HERE / "profiles" / f"{key}_resume.txt")
        try:
            result = run_match(JobInput(description=path.read_text(), country="Singapore"), profile, [resume], llm)
        except LLMOutputError as exc:
            rows.append({"job": path.stem, "profile": key, "error": str(exc)[:300]})
            print(f"{path.stem:32} {key:10} FAILED: {str(exc)[:80]}")
            continue
        s = result.match.summary
        rows.append({
            "job": path.stem, "profile": key,
            "skills": result.job_analysis.required_skills,
            "required": f"{s.required_covered}/{s.required_total}",
            "checks": [
                {"req": c.requirement, "kind": c.kind, "status": c.status, "quotes": [e.quote for e in c.evidence]}
                for c in result.match.requirement_checks
            ],
            "warnings": result.meta.warnings,
        })
        print(f"{path.stem:32} {key:10} required {s.required_covered}/{s.required_total}  skills={len(result.job_analysis.required_skills)}")
    out = HERE / "out" / "industry_match.json"
    out.parent.mkdir(exist_ok=True)
    out.write_text(json.dumps(rows, indent=2))
    by_task: dict[str, list[int]] = {}
    for u in llm.usage:
        t = by_task.setdefault(u.task, [0, 0, 0])
        t[0] += u.input_tokens; t[1] += u.output_tokens; t[2] += u.thinking_tokens
    print("tokens by task (in, out, thinking):", by_task)
    print(f"calls={len(llm.usage)} tokens_in={sum(u.input_tokens for u in llm.usage)} tokens_out={sum(u.output_tokens + u.thinking_tokens for u in llm.usage)} -> {out}")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else None)
