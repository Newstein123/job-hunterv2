# JobHunter AI Service — Phase 1 prototype

Turns a job the user already found into a reviewable application: job analysis → match evidence →
recommended **existing** resume → skills to emphasize → application strategy → email (+ optional cover letter).
It is exposed to the Laravel app through a small FastAPI layer (step 11); it has no database of its own.

**Design rule:** the LLM (Gemini) only *extracts* (job analysis) and *writes* (email paragraphs).
Matching, resume selection, skill strategy, rules, subject line and signature are deterministic,
and every LLM output is schema-validated and checked against the candidate's real profile.

| Stage | Module | How |
|---|---|---|
| 1 Job analysis | `app/pipelines/job_analysis.py` | LLM + drops skills not found in the posting |
| 2 Candidate analysis | `app/pipelines/candidate_analysis.py` | deterministic evidence index (`experience:`, `project:`, `resume:`, `skill_list`) |
| 3 Matching | `app/pipelines/matching.py` | deterministic counts + evidence, no % score |
| 4 Resume selection | `app/pipelines/resume_selection.py` | ranks: required > preferred > keywords > CV-rule compliance |
| 5 Skill strategy | `app/pipelines/skill_strategy.py` | emphasize strongly evidenced skills; `do_not_claim` = missing skills |
| 6 Application strategy | `app/pipelines/application_strategy.py` | from rules; cover-letter decision; earliest *allowed* send time |
| 7 Email | `app/pipelines/email_generation.py` | LLM paragraphs → rule + grounding checks → one repair retry → errors shown for review |

Rules live in `app/rules/profiles/*.json` and are merged as `default < country < --rules user file`.
Country profiles are intentionally minimal (timezone only) until conventions are validated.

## Setup

```bash
cd ai-service
python3 -m venv .venv && .venv/bin/pip install -r requirements-dev.txt
cp .env.example .env   # set GEMINI_API_KEY; GEMINI_MODEL is configurable
```

## Run

```bash
export GEMINI_API_KEY=...
.venv/bin/python -m app.cli run \
  --profile examples/candidate_profile.json \
  --job examples/jobs/backend_laravel.txt --job-meta examples/jobs/backend_laravel.meta.json \
  --resumes examples/resumes --country Singapore --rules examples/rules/user_rules.json
```

The CLI prints the review summary (company, position, resume + reason, emphasized skills, subject, body,
attachment, send time, validation) and writes the full structured result to `out/<job>.json`.

Resumes: `.pdf`, `.docx`, `.txt`, `.md`. The resume id is the file stem. Scanned (image-only) PDFs are rejected.
`python -m examples.build_resumes` regenerates the example PDF/DOCX from `examples/resume_sources/`.

## API (FastAPI)

```bash
.venv/bin/uvicorn app.api.main:app --port 8001 --env-file .env
```

Every `/v1` route requires the `X-Service-Token` header, which must match `AI_SERVICE_TOKEN`.

| Route | Body | Returns |
|---|---|---|
| `GET /health` | — | `{"status": "ok"}` |
| `POST /v1/jobs/analyze` | `{job}` | job analysis + warnings |
| `POST /v1/applications/generate` | `{job, profile, resumes: [{id, filename, content_base64}], user_rules?}` | full `PipelineResult` |

Resume ids are the caller's (Laravel's) ids and come back as `recommended_resume_id`.
Errors: 401 bad token, 422 invalid input/rules/document, 502 the model returned invalid output, 503 not configured.

`python -m scripts.dev_fake_llm_server` serves the same API with a template "LLM" for offline UI work (dev only).

## Test

```bash
.venv/bin/python -m pytest        # offline, uses FakeLLMClient
.venv/bin/python -m evals.run --repeat 3   # live Gemini over evals/cases.json
```

For Phase 2, add 10–20 real postings to `evals/jobs/` with a case in `evals/cases.json`
(`expected_resume_id`, `expected_required_skills`, `expect_cover_letter`). The eval reports resume accuracy,
consistency across repeats, skill recall/precision, and email grounding/rule errors.

## Known limitations

- Skill matching uses a small alias table (`app/skills/normalize.py`); extend it as real jobs show gaps.
  Ambiguous words (e.g. "react to incidents") can still cause false matches.
- The grounding check is conservative: it flags any skill mentioned in the email that the candidate lacks,
  even when it refers to the company's stack. Organization checks are warnings only.
- Prompts are versioned (`PROMPT_VERSION`) and recorded in `meta.prompt_versions`.
