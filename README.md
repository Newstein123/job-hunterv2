# JobHunter

> You find the job. JobHunter handles the application.

| Part | Stack | Responsibility |
|---|---|---|
| [`web/`](web/) | Laravel 13, Inertia + React + TypeScript (Vite), MySQL | Auth, career profile, resumes, jobs (manual + CSV/XLSX), rules, applications, review/approval, status history, queue |
| [`ai-service/`](ai-service/) | Python, FastAPI, Gemini | Job analysis, candidate evidence, matching, resume selection, strategy, email generation |

Laravel calls the AI service only from queued jobs (`App\Jobs\GenerateApplicationContent`), never in the request
cycle. The AI service is stateless; Laravel stores every structured output (`job_analyses`, `job_requirements`,
`application_strategies`) so results are inspectable.

## Local setup

Requirements: PHP 8.3+, Composer, MySQL, Node 22 (`web/.nvmrc`; Vite 8 needs ≥ 20.19), Python 3.12+.

```bash
# AI service
cd ai-service
python3 -m venv .venv && .venv/bin/pip install -r requirements-dev.txt
cp .env.example .env          # set GEMINI_API_KEY and AI_SERVICE_TOKEN

# Web app
cd ../web
composer install && npm install
cp .env.example .env && php artisan key:generate
# set DB_* and AI_SERVICE_TOKEN (same value as ai-service/.env)
mysql -u root -e "CREATE DATABASE jobhunter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
php artisan db:seed --class=DemoSeeder   # optional: demo@example.com / password

composer dev   # web server, queue worker, scheduler, Vite, and the AI service on :8001
```

Open http://localhost:8000.

Without a Gemini key you can still click through the whole flow with the **dev-only** template LLM:
run `.venv/bin/python -m scripts.dev_fake_llm_server` in `ai-service/` instead of the real service. Its output
is a fixed template and says nothing about real model quality.

## AI service

`composer dev` already starts it. To run it on its own:

```bash
cd ai-service

# Locally (needs .env with GEMINI_API_KEY and AI_SERVICE_TOKEN)
.venv/bin/uvicorn app.api.main:app --port 8001 --env-file .env --reload
curl localhost:8001/health                     # {"status":"ok"}

# In Docker, the same image that runs in production
docker build -t jobhunter-ai .
docker run --rm -p 8001:8001 --env-file .env jobhunter-ai
```

The image contains only `app/` and `requirements.txt` (see `.dockerignore`): course materials, examples and
`.env` never go into it. Secrets are passed as environment variables.

### Production: AWS Lambda

Runs as the Lambda function `jobhunter-ai` (arm64, `ap-southeast-1`) behind a function URL, through the
Lambda Web Adapter. Every `/v1` call still needs the `X-Service-Token` header. One-time setup (ECR repository,
execution role, function, URL) is already done; deploy an update with:

```bash
cd ai-service
REGION=ap-southeast-1
REG=$(aws sts get-caller-identity --query Account --output text).dkr.ecr.$REGION.amazonaws.com
TAG=$(date +%Y%m%d-%H%M)

docker build --platform linux/arm64 --provenance=false -t jobhunter-ai:latest .
aws ecr get-login-password --region $REGION | docker login -u AWS --password-stdin $REG
docker tag jobhunter-ai:latest $REG/jobhunter-ai:$TAG && docker push $REG/jobhunter-ai:$TAG
aws lambda update-function-code --region $REGION --function-name jobhunter-ai --image-uri $REG/jobhunter-ai:$TAG
aws lambda wait function-updated-v2 --region $REGION --function-name jobhunter-ai

# Check
URL=$(aws lambda get-function-url-config --region $REGION --function-name jobhunter-ai --query FunctionUrl --output text)
curl ${URL}health                              # {"status":"ok"}; first call after idle takes ~5 s
```

- `--provenance=false` matters: Lambda rejects the multi-arch manifest Docker builds by default.
- Roll back: run `update-function-code` with an earlier tag (ECR keeps the last 5 images).
- Logs: `aws logs tail /aws/lambda/jobhunter-ai --region ap-southeast-1 --since 15m --follow`
- Change a secret (`GEMINI_API_KEY`, `AI_SERVICE_TOKEN`, …): Lambda console → `jobhunter-ai` →
  Configuration → Environment variables. `update-function-configuration --environment` replaces *all*
  variables, so include every one if you use the CLI.
- Laravel needs `AI_SERVICE_URL` (the function URL without the trailing `/`), the same `AI_SERVICE_TOKEN`,
  and `AI_SERVICE_TIMEOUT=120`.

## Connecting Gmail accounts

Users can connect several Gmail accounts (Email accounts page). Each has its own daily limit and health status;
approved applications are queued and sent by the scheduler, now or at the time you pick, and replies are detected
from thread headers every 10 minutes.

1. In [Google Cloud Console](https://console.cloud.google.com/): create a project and enable the **Gmail API**.
2. **OAuth consent screen**: user type *External*, add the scopes `gmail.send` and `gmail.readonly`, and add
   yourself (and any testers, up to 100) under **Test users** while the app is in *Testing*.
3. **Credentials → Create OAuth client ID → Web application**, authorized redirect URI:
   `http://localhost:8000/email-accounts/google/callback`
4. Put the values in `web/.env`:
   ```
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI=http://localhost:8000/email-accounts/google/callback
   ```
5. Run `composer dev` (it includes the scheduler), open **Email accounts → Connect Gmail**. Repeat for each account.

Notes:
- In *Testing* mode Google expires refresh tokens after 7 days; the account then shows **Reconnect needed**.
- `gmail.send` and `gmail.readonly` are restricted scopes. Opening this to the public requires Google's app
  verification (and a security assessment). JobHunter only reads the threads of applications it sent, to show,
  classify and answer replies. Accounts connected with the older `gmail.metadata` scope keep working with
  headers only until reconnected.
- In production run `php artisan schedule:run` every minute (cron) and a queue worker.

## Tests

```bash
cd ai-service && .venv/bin/python -m pytest
cd web && php artisan test && vendor/bin/phpstan analyse && npm run types:check && npm run check
```

## Status

Done: AI pipeline (steps 1–10), FastAPI (step 11), Laravel core app (step 12): profile, resumes, jobs, import,
rules, generation, review → approve/edit/cancel, manual outcome tracking, status history.

Also done: fit analysis for every added or imported job (job analysis + match + resume pick, no email,
one Gemini call per job), with Strong / Partial / Weak filters and "Analyze" for selected jobs on the Jobs page.
Multiple Gmail accounts (OAuth), the email queue (send now or at a chosen time, daily limit, min gap,
duplicate and recipient checks, retries, cancel), account health, and reply/bounce detection.

Also done: reading replies (text, auto-reply/bounce detection), AI classification with quoted evidence and a
suggested stage you confirm, and AI-drafted answers with [[placeholders]] for facts it doesn't have, sent in the
same Gmail thread after your review.

Next: Outlook (Microsoft Graph) as a second provider; run the eval harness (`ai-service/evals`) against
10–20 real jobs with a real Gemini key.
