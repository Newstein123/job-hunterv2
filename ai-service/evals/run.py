"""Phase 2 evaluation harness: run the pipeline over real jobs and score it.

    python -m evals.run [--cases evals/cases.json] [--repeat 3]

Add real jobs to evals/jobs/ and a case per job to cases.json. Checks:
resume selection, required-skill recall/precision, grounding/rule errors, cover-letter decision,
and (with --repeat) consistency of the selected resume across runs.
"""

import argparse
import json
import sys
from collections import Counter
from pathlib import Path

from app.config import get_settings
from app.documents.extract import load_resumes
from app.llm.client import GeminiClient
from app.models.candidate import CandidateProfile
from app.models.job import JobInput
from app.pipeline import run_pipeline

HERE = Path(__file__).parent
EXAMPLES = HERE.parent / "examples"


def evaluate_case(case: dict, profile, resumes, llm, rules_path, repeat: int, out_dir: Path) -> dict:
    job = JobInput(description=(HERE / case["job_file"]).read_text(), country=case.get("country"),
                   **case.get("meta", {}))
    picks, rows = [], []
    for i in range(repeat):
        result = run_pipeline(job, profile, resumes, llm, user_rules_path=rules_path)
        (out_dir / f"{case['id']}.{i}.json").write_text(result.model_dump_json(indent=2))
        picks.append(result.resume_selection.recommended_resume_id)
        rows.append(result)

    first = rows[0]
    expected = set(case.get("expected_required_skills", []))
    extracted = set(first.job_analysis.required_skills)
    return {
        "id": case["id"],
        "resume_ok": picks[0] == case.get("expected_resume_id"),
        "resume": picks[0],
        "consistent": len(set(picks)) == 1,
        "picks": dict(Counter(picks)),
        "skill_recall": len(expected & extracted) / len(expected) if expected else None,
        "skill_precision": len(expected & extracted) / len(extracted) if extracted and expected else None,
        "extra_skills": sorted(extracted - expected),
        "missed_skills": sorted(expected - extracted),
        "email_errors": first.email.validation.errors,
        "email_warnings": first.email.validation.warnings,
        "email_attempts": first.email.validation.attempts,
        "cover_letter_ok": ("expect_cover_letter" not in case
                            or first.application_strategy.include_cover_letter == case["expect_cover_letter"]),
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cases", type=Path, default=HERE / "cases.json")
    parser.add_argument("--profile", type=Path, default=EXAMPLES / "candidate_profile.json")
    parser.add_argument("--resumes", type=Path, default=EXAMPLES / "resumes")
    parser.add_argument("--rules", type=Path, default=None)
    parser.add_argument("--repeat", type=int, default=1, help="Runs per case, for consistency checks")
    parser.add_argument("--out", type=Path, default=Path("out/evals"))
    args = parser.parse_args(argv)

    settings = get_settings()
    if not settings.gemini_api_key:
        print("GEMINI_API_KEY is not set", file=sys.stderr)
        return 2
    llm = GeminiClient(settings.gemini_api_key, settings.gemini_model)
    profile = CandidateProfile.model_validate_json(args.profile.read_text())
    resumes = load_resumes(args.resumes)
    args.out.mkdir(parents=True, exist_ok=True)

    results = [evaluate_case(c, profile, resumes, llm, args.rules, args.repeat, args.out)
               for c in json.loads(args.cases.read_text())]

    for r in results:
        recall = f"{r['skill_recall']:.0%}" if r["skill_recall"] is not None else "-"
        print(f"{r['id']:<24} resume={'OK ' if r['resume_ok'] else 'BAD'} ({r['resume']}) "
              f"consistent={r['consistent']} recall={recall} email_errors={len(r['email_errors'])} "
              f"attempts={r['email_attempts']} cover_letter={'OK' if r['cover_letter_ok'] else 'BAD'}")
        for label in ("missed_skills", "extra_skills", "email_errors", "email_warnings"):
            if r[label]:
                print(f"    {label}: {r[label]}")

    n = len(results)
    print(f"\nResume accuracy: {sum(r['resume_ok'] for r in results)}/{n}  "
          f"Consistent: {sum(r['consistent'] for r in results)}/{n}  "
          f"Clean emails: {sum(not r['email_errors'] for r in results)}/{n}")
    (args.out / "summary.json").write_text(json.dumps(results, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
