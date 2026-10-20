"""Prototype CLI: run the full pipeline over local files and print a review summary.

    python -m app.cli run --profile examples/candidate_profile.json \
        --job examples/jobs/backend_laravel.txt --resumes examples/resumes --country Singapore
"""

import argparse
import json
import logging
import re
import sys
from pathlib import Path

from app.config import get_settings
from app.documents.extract import load_resumes
from app.llm.client import GeminiClient
from app.models.application import PipelineResult
from app.models.candidate import CandidateProfile
from app.models.job import JobInput
from app.pipeline import run_pipeline

_EMAIL_PATTERN = re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+")


class _RedactingFilter(logging.Filter):
    """Keep contact emails out of logs."""

    def filter(self, record: logging.LogRecord) -> bool:
        record.msg = _EMAIL_PATTERN.sub("<email>", str(record.msg))
        if record.args:
            record.args = tuple(_EMAIL_PATTERN.sub("<email>", str(a)) for a in record.args)
        return True


def _setup_logging(level: str) -> None:
    handler = logging.StreamHandler(sys.stderr)
    handler.addFilter(_RedactingFilter())
    logging.basicConfig(level=level.upper(), handlers=[handler], format="%(levelname)s %(name)s: %(message)s")
    for noisy in ("httpx", "google_genai", "httpcore"):
        logging.getLogger(noisy).setLevel(logging.WARNING)


def load_job(job_path: Path, meta_path: Path | None, country: str | None) -> JobInput:
    meta = json.loads(meta_path.read_text()) if meta_path else {}
    if country:
        meta["country"] = country
    return JobInput(description=job_path.read_text(encoding="utf-8"), **meta)


def print_review(result: PipelineResult) -> None:
    """The fields a user reviews before approving (CLAUDE.md §12)."""
    job, sel, email, strat = result.job_analysis, result.resume_selection, result.email, result.application_strategy
    print("=" * 72)
    print(f"Company:            {job.company}")
    print(f"Position:           {job.title}")
    print(f"Recommended resume: {sel.recommended_filename}")
    print(f"Why this resume:    {sel.reason}")
    print(f"Skills emphasized:  {', '.join(result.skill_strategy.emphasize) or '-'}")
    print(f"Missing (not claimed): {', '.join(result.skill_strategy.do_not_claim) or '-'}")
    print(f"Attachment:         {email.attachment}")
    print(f"Sending account:    (not connected in prototype)")
    print("-" * 72)
    print(f"Subject: {email.subject}\n")
    print(email.body)
    if email.cover_letter:
        print("-" * 72 + "\nCOVER LETTER\n")
        print(email.cover_letter)
    print("-" * 72)
    status = "OK" if email.validation.ok else "NEEDS EDITS"
    print(f"Validation: {status} (attempts: {email.validation.attempts})")
    for e in email.validation.errors:
        print(f"  ERROR: {e}")
    for w in [*email.validation.warnings, *result.meta.warnings]:
        print(f"  warning: {w}")
    print("=" * 72)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="jobhunter-ai")
    sub = parser.add_subparsers(dest="command", required=True)
    run = sub.add_parser("run", help="Run the full application pipeline for one job")
    run.add_argument("--profile", type=Path, required=True, help="candidate_profile.json")
    run.add_argument("--job", type=Path, required=True, help="Job description text file")
    run.add_argument("--job-meta", type=Path, help="Optional JSON: position, company, url, location, contact_email, source")
    run.add_argument("--resumes", type=Path, required=True, help="Directory of existing resumes (pdf/docx/txt/md)")
    run.add_argument("--country", help="Country context, e.g. Singapore")
    run.add_argument("--rules", type=Path, help="Optional user rules JSON (overrides country/default rules)")
    run.add_argument("--out", type=Path, default=Path("out"), help="Output directory for the JSON result")
    args = parser.parse_args(argv)

    settings = get_settings()
    _setup_logging(settings.log_level)
    if not settings.gemini_api_key:
        print("GEMINI_API_KEY is not set", file=sys.stderr)
        return 2

    profile = CandidateProfile.model_validate_json(args.profile.read_text())
    resumes = load_resumes(args.resumes)
    job = load_job(args.job, args.job_meta, args.country)
    llm = GeminiClient(settings.gemini_api_key, settings.gemini_model)

    result = run_pipeline(job, profile, resumes, llm, user_rules_path=args.rules)

    args.out.mkdir(parents=True, exist_ok=True)
    out_path = args.out / f"{args.job.stem}.json"
    out_path.write_text(result.model_dump_json(indent=2))
    print_review(result)
    print(f"Full result: {out_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
