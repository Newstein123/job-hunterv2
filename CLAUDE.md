# JobHunter --- CLAUDE.md

## 1. Project Overview

JobHunter is an AI-powered job application management and distribution
platform.

### Core positioning

> **You find the job. JobHunter handles the application.**

JobHunter is NOT primarily: - a job search engine - a generic AI career
coach - a resume builder - an interview coach - a fully autonomous
auto-apply bot

The user finds jobs elsewhere (LinkedIn, Indeed, company websites,
Facebook, Telegram, referrals, etc.) and brings those jobs into
JobHunter.

JobHunter helps the user: 1. Manage their career profile 2. Manage
multiple existing resumes 3. Add/import jobs 4. Analyze each job 5.
Select the most appropriate existing resume 6. Identify skills and
experience to emphasize 7. Apply application rules 8. Generate a
personalized application email / optional cover letter 9. Review and
approve the application 10. Send through connected email accounts 11.
Track applications and email replies

The product should optimize **application preparation, distribution, and
tracking**, not job discovery.

------------------------------------------------------------------------

## 2. MVP Scope

The MVP includes:

-   Career Profile
-   Career Experience
-   Skills
-   Projects
-   Education
-   Multiple Resume Management
-   Add Job manually
-   Import Jobs from CSV/Excel
-   Job Analysis
-   Resume Selection
-   Skill/Experience Emphasis
-   Application Strategy
-   Personalized Email Generation
-   Optional Cover Letter Generation
-   Application Review
-   Multiple connected email accounts
-   Email Queue
-   Basic sending schedule
-   Gmail/Outlook integration where supported
-   Reply tracking
-   Application status tracking
-   Application status history
-   Basic country/application rules
-   Speculative applications to companies from the user's own company
    list, with the guardrails in §4 and §19 (added by the product owner,
    2026-10-02)

### Explicitly NOT in MVP

Do not add these unless the product owner explicitly changes scope:

-   Automatic job discovery
-   LinkedIn scraping
-   Indeed scraping
-   Browser automation for job boards
-   Fully autonomous applications
-   AI interview coach
-   Generic AI career coach
-   Full AI resume builder
-   Automatic CV rewriting/customization
-   Large general-purpose Knowledge Engine
-   Machine-learning recommendation model
-   Hundreds of country profiles
-   Complex job-board integrations
-   Autonomous mass emailing without user review
-   BCC blasts to many companies in one email
-   Creating or rotating email accounts to get around blocks or limits
-   Contact-finder lookups (e.g. RocketReach, ContactOut) or guessing
    addresses

------------------------------------------------------------------------

## 3. Critical MVP Decision: Do NOT Rewrite CVs

JobHunter should NOT rewrite or regenerate a user's CV for every job in
the MVP.

The user can upload multiple existing resumes, for example:

-   Backend Developer CV
-   Full Stack Developer CV
-   AWS/Cloud CV

For each job, the system should determine:

``` text
Recommended Resume
Why it matches
Relevant experience
Relevant projects
Skills to emphasize
Skills to avoid over-emphasizing
```

Example:

``` json
{
  "recommended_resume": "backend_v3.pdf",
  "reason": "Strong alignment with PHP, Laravel, REST API and Docker requirements",
  "emphasize": [
    "Laravel",
    "PHP",
    "REST API",
    "Docker"
  ],
  "relevant_experience": [
    "Senior PHP Developer"
  ],
  "relevant_projects": [
    "KwinChain"
  ]
}
```

Later versions may add CV tailoring, but that is a separate feature.

### MVP principle

> **Do not customize the CV. Customize the application.**

------------------------------------------------------------------------

## 4. Add Job Must Be a First-Class Feature

A user should be able to apply to one job immediately.

### Manual Add Job

Fields:

-   Position
-   Company
-   Job Description
-   Job URL
-   Location
-   Country
-   Contact Email
-   Source

The user may also simply paste a job description.

The AI should extract structured information from it.

### Bulk Import

Support CSV/Excel for multiple jobs.

Future job sources can include: - Job URLs - Browser extension - Job
platform integrations - ATS integrations

Do not implement those in MVP.

### Speculative Applications (Company Lists)

The user may also import a list of companies that have not posted a job
(e.g. an export from a company database such as "The Grid"), and write
to them directly. This is a common, legitimate job-search strategy taught
by the Career Coaching Center.

How it works:

-   The user chooses the position they are offering and which existing
    resume to send. The CV is still not rewritten.
-   One job record per company (`kind = speculative`), with the list's
    website, industry and specialties. There is no job analysis, because
    there is no posting.
-   The email is built from the candidate's own evidence and the
    company facts in the list only. It asks to be considered for current
    or future openings.
-   The email must never say or imply that the role was advertised or
    that a vacancy exists. This is checked in code, not only in the
    prompt.
-   Never invent anything about the company beyond what the list says.

Guardrails:

-   One company per email domain. Skip companies already in JobHunter.
-   Flag weak recipients (sales/finance/support inboxes, addresses that
    look like a named person) so the user decides. Do not silently drop
    them.
-   Every draft is shown on a batch review page before approval. Nothing
    is approved unseen. Drafts with validation errors cannot be batch
    approved.
-   A separate, lower daily cap per account for speculative first emails
    (default 20). Replies to companies that answer are not capped.

------------------------------------------------------------------------

## 5. Core User Flow

``` text
Career Profile
      |
      v
Upload / Manage Resumes
      |
      v
Add Job
      |
      v
Job Analysis
      |
      v
Apply Rules
      |
      v
Resume Selection
      |
      v
Skill & Experience Emphasis
      |
      v
Application Strategy
      |
      v
Personalized Email / Cover Letter
      |
      v
User Review
      |
      v
Email Queue
      |
      v
Send
      |
      v
Track Message / Thread
      |
      v
Track Reply
      |
      v
Update Application Status
```

------------------------------------------------------------------------

## 6. AI Pipeline

The AI service should be modular.

Do NOT build one giant prompt.

### Pipeline

#### Stage 1 --- Job Analysis

Input: - Raw job description - Job metadata

Output:

``` json
{
  "title": "",
  "company": "",
  "seniority": "",
  "location": "",
  "country": "",
  "required_skills": [],
  "preferred_skills": [],
  "responsibilities": [],
  "keywords": [],
  "requirements": []
}
```

The model should not invent requirements that are not supported by the
job description.

------------------------------------------------------------------------

#### Stage 2 --- Candidate Profile Analysis

Input: - Career profile - Experience - Skills - Projects - Education -
Existing resumes

Output structured candidate evidence.

The system should preserve evidence from the user's actual profile.

------------------------------------------------------------------------

#### Stage 3 --- Job/Candidate Matching

Determine:

-   Matching skills
-   Missing skills
-   Relevant experience
-   Relevant projects
-   Relevant education
-   Evidence supporting each match

Do not create unsupported qualifications.

Avoid relying on an arbitrary LLM-generated percentage.

Prefer explainable matching.

------------------------------------------------------------------------

#### Stage 4 --- Resume Selection

Input:

-   Job analysis
-   Candidate profile
-   Existing resumes

Output:

``` json
{
  "recommended_resume_id": "...",
  "alternative_resume_ids": [],
  "reason": "...",
  "emphasize": [],
  "relevant_experience": [],
  "relevant_projects": []
}
```

The system selects from existing resumes.

It does not rewrite the resume in MVP.

------------------------------------------------------------------------

#### Stage 5 --- Skill / Experience Strategy

Determine what should be emphasized in the application.

Example:

``` json
{
  "emphasize": [
    "Laravel",
    "PHP",
    "REST API"
  ],
  "supporting_skills": [
    "Docker",
    "MySQL"
  ],
  "relevant_experience": [
    "Senior PHP Developer"
  ],
  "relevant_project": "KwinChain"
}
```

The AI should represent the candidate accurately, not invent experience.

------------------------------------------------------------------------

#### Stage 6 --- Application Strategy

Combine:

-   Job analysis
-   Candidate profile
-   Selected resume
-   Skill strategy
-   Country
-   Application rules
-   User preferences

Output:

``` json
{
  "language": "English",
  "tone": "professional",
  "email_length": "short",
  "paragraph_count": 3,
  "focus": [],
  "relevant_experience": [],
  "relevant_project": "",
  "cta": "",
  "include_cover_letter": false
}
```

------------------------------------------------------------------------

#### Stage 7 --- Email Generation

Generate the final email from the structured strategy.

Do not make the generator responsible for deciding everything.

The generator receives: - Job - Candidate - Selected resume -
Application strategy - Rules

Then generates: - Subject - Email body - Optional cover letter

The generated content must only contain supported candidate information.

------------------------------------------------------------------------

## 7. Rules System

The MVP should use a **simple Rules Layer**.

Do NOT build a large knowledge engine yet.

The course documents/examples provided by the product owner may be
useful later, but they are not treated as universal or authoritative
rules.

### Rules are constraints/guidelines used by the application pipeline.

Rules can cover:

### A. CV Rules

Examples: - preferred CV format - basic country-specific preferences -
information to include/avoid - preferred length where configured

These rules influence recommendations and resume selection.

They do not trigger CV rewriting in MVP.

------------------------------------------------------------------------

### B. Email Rules

Examples:

``` json
{
  "subject_format": "{position} - Application - {candidate_name}",
  "max_words": 200,
  "paragraph_count": 3,
  "tone": "professional",
  "include_linkedin": true,
  "include_salary": false
}
```

------------------------------------------------------------------------

### C. Application Rules

Examples:

-   when to use a cover letter
-   whether to mention specific projects
-   whether to include salary
-   preferred CTA
-   preferred tone
-   skills to emphasize
-   claims to avoid

------------------------------------------------------------------------

### D. Sending Time (no sending window)

Removed as a rule by the product owner, 2026-10-21. There is no
sending window that holds emails back or moves them.

An approved email goes out either:

-   now (Approve & send, or "Now" when scheduling), or
-   at an exact time the user picks (in each job's country time or one
    fixed timezone).

Suggested send times (Career Coaching Center: 06:30--10:30 employer's
time for job-site applications, 11:30--15:30 for other outreach) are
shown as information only. They are never enforced.

Do not claim that a specific time is universally the "best" time to
apply unless supported by validated evidence.

Daily limits, the gap between sends and the speculative cap still
apply: they are account protection, not a sending window.

------------------------------------------------------------------------

## 8. Country Rules

Country should be a context variable, not a giant hard-coded conditional
system.

Avoid:

``` python
if country == "Singapore":
    ...
elif country == "Thailand":
    ...
```

Instead, use configurable data.

Example:

``` json
{
  "country": "Singapore",
  "cv_rules": {},
  "email_rules": {},
  "application_rules": {}
}
```

For MVP, only support a small number of curated country profiles.

Do not invent country-specific conventions.

Country is only one part of application context.

The full context can be:

``` text
Country
+
Industry
+
Role
+
Seniority
+
Company
+
Job Description
+
Candidate
```

For MVP, keep this simple.

------------------------------------------------------------------------

## 9. Knowledge vs Rules

Do not implement the broader Application Knowledge Base yet.

Future architecture may distinguish:

``` text
Knowledge
├── Market Knowledge
├── CV Knowledge
├── Cover Letter Knowledge
├── Email Knowledge
└── Interview Knowledge

Rules
├── User Rules
├── Application Rules
└── Market Rules
```

For MVP, only implement the Rules layer.

Later, course documents, templates, examples, and validated external
sources can become a structured knowledge system.

------------------------------------------------------------------------

## 10. Multiple Email Accounts

Users may connect multiple legitimate email accounts.

Example:

``` text
Gmail Account A
Gmail Account B
Outlook Account
...
```

The system should store:

-   Provider
-   Email address
-   Authentication type
-   Access token
-   Refresh token
-   Status
-   Daily sending configuration
-   Current usage
-   Last synchronization
-   Provider-specific metadata

Use OAuth as the primary approach for providers that support it.

App-password/SMTP support can be added where appropriate.

Do not design account rotation to bypass provider restrictions or
limits.

Respect each provider's policies and limits.

If an account is restricted or unhealthy, stop using it and notify the
user.

------------------------------------------------------------------------

## 11. Email Queue

All outgoing emails should go through a central queue.

Example states:

``` text
draft
approved
queued
scheduled
sending
sent
failed
retrying
cancelled
```

The queue should consider:

-   account availability
-   the time the user scheduled (or now)
-   provider limits
-   duplicate application protection
-   recipient validity
-   user approval
-   retry state

Do not directly send emails from the request cycle.

Use background jobs.

------------------------------------------------------------------------

## 12. Human Approval

MVP should include human review before sending.

The user should be able to see:

``` text
Company
Position
Recommended Resume
Why this resume
Skills emphasized
Subject
Email body
Attachment
Scheduled time
Sending account
```

Then:

``` text
Approve & Send
```

or:

``` text
Edit
```

or:

``` text
Cancel
```

This is important for quality control and prevents incorrect
AI-generated applications from being sent automatically.

------------------------------------------------------------------------

## 13. Reply Tracking

For Gmail/Outlook integrations where supported:

``` text
Send Application
      |
      v
Store message/thread ID
      |
      v
Sync mailbox
      |
      v
Detect reply
      |
      v
Match thread/application
      |
      v
Update application
```

Application states can include:

``` text
Draft
Generated
Approved
Queued
Sent
Replied
Interview
Rejected
Offer
Withdrawn
```

MVP can initially automate reliable "Replied" detection.

More advanced AI reply classification can come later.

------------------------------------------------------------------------

## 14. Database Concept

Initial entities:

``` text
users

career_profiles
career_experiences
career_skills
career_projects
career_education

resumes

jobs
job_requirements
job_analyses

applications
application_strategies

application_rules
market_rules

email_accounts
email_messages
email_events

application_status_history
```

Relationships:

``` text
User
 ├── Career Profile
 ├── Resumes
 ├── Jobs
 ├── Applications
 └── Email Accounts

Job
 └── Job Analysis
       |
       v
   Application
       |
       ├── Resume
       ├── Strategy
       ├── Email Account
       ├── Email Message
       └── Status History
```

Keep the database normalized and avoid storing large duplicated AI
prompts unnecessarily.

Store important structured AI outputs so the pipeline is inspectable and
reproducible.

------------------------------------------------------------------------

## 15. Python AI Service

The main application can remain Laravel/PHP.

Use a separate Python AI service.

### Laravel responsibilities

-   Authentication
-   Users
-   Career profiles
-   Resume storage
-   Jobs
-   Applications
-   Rules
-   Email accounts
-   Gmail/Outlook integrations
-   Queues
-   Sending
-   Reply synchronization
-   Dashboard
-   API orchestration

### Python responsibilities

-   Document processing
-   Job analysis
-   Candidate analysis
-   Job/candidate matching
-   Resume selection
-   Skill strategy
-   Application strategy
-   Email generation
-   AI evaluation/testing

------------------------------------------------------------------------

## 16. Suggested Python Structure

``` text
ai-service/
├── app/
│   ├── api/
│   │   ├── jobs.py
│   │   ├── matching.py
│   │   ├── resumes.py
│   │   └── applications.py
│   │
│   ├── pipelines/
│   │   ├── job_analysis.py
│   │   ├── candidate_analysis.py
│   │   ├── matching.py
│   │   ├── resume_selection.py
│   │   ├── skill_strategy.py
│   │   ├── application_strategy.py
│   │   └── email_generation.py
│   │
│   ├── rules/
│   │   ├── loader.py
│   │   ├── validator.py
│   │   └── profiles/
│   │
│   ├── models/
│   │   ├── job.py
│   │   ├── candidate.py
│   │   ├── resume.py
│   │   └── application.py
│   │
│   ├── llm/
│   │   └── client.py
│   │
│   └── prompts/
│       ├── job_analysis.py
│       ├── matching.py
│       ├── resume_selection.py
│       ├── application_strategy.py
│       └── email_generation.py
│
├── tests/
├── requirements.txt
├── Dockerfile
└── README.md
```

------------------------------------------------------------------------

## 17. Development Strategy

Do NOT start by building the entire FastAPI service.

First create a small working AI prototype.

### Phase 1

Use:

``` text
candidate_profile.json
job_description.txt
resumes/
rules/
```

Run:

``` text
Job
 ↓
Job Analysis
 ↓
Candidate Match
 ↓
Resume Selection
 ↓
Skill Strategy
 ↓
Application Strategy
 ↓
Email
```

Return structured JSON.

### Phase 2

Test against approximately 10--20 real jobs.

Check:

-   Is the correct resume selected?
-   Are requirements extracted correctly?
-   Are relevant skills identified?
-   Does the AI invent information?
-   Are application emails genuinely personalized?
-   Are rules being followed?
-   Are results consistent?

### Phase 3

Only after the pipeline works:

-   Convert to FastAPI
-   Add API endpoints
-   Connect Laravel
-   Add database persistence
-   Add queues
-   Add email integration

------------------------------------------------------------------------

## 18. AI / ML Strategy

Do NOT use machine learning for the MVP.

Use:

``` text
LLM
+
Structured Outputs
+
Deterministic Rules
+
Candidate Evidence
```

No recommendation model is required initially.

No vector database is required initially.

No RAG system is required initially.

No embeddings are required initially.

Later, after enough application outcome data exists, ML can potentially
learn from:

``` text
Job characteristics
Candidate characteristics
Resume selected
Skills emphasized
Application strategy
Country
Company
Email characteristics
Application outcome
```

Possible outcomes:

``` text
No response
Reply
Interview
Rejection
Offer
```

That is a future optimization layer, not MVP.

------------------------------------------------------------------------

## 19. Responsible Sending

JobHunter is intended for legitimate job applications.

"Bulk" means:

> efficiently processing many distinct applications.

It does NOT mean:

> sending the same generic message to hundreds of recipients.

The system should include:

-   Human approval in MVP
-   Duplicate protection
-   Recipient validation
-   User-controlled sending
-   Provider limit awareness
-   Account health checks
-   Clear application records
-   Stop/retry controls
-   No private-email scraping
-   No guessed personal addresses
-   No mechanisms intended to bypass provider restrictions
-   Speculative (company-list) emails: one per company, personalized,
    batch-reviewed, and capped per account per day (see §4)

The product should help users apply efficiently without becoming a
generic spam tool.

------------------------------------------------------------------------

## 20. Product Principle

The core value is:

> **JobHunter turns jobs the user has already found into personalized,
> organized, trackable applications.**

The MVP should answer four questions for every job:

1.  **Which resume should I use?**
2.  **What experience/skills should I emphasize?**
3.  **How should I write the application?**
4.  **How do I send and track it?**

If a feature does not directly support those questions, it probably
belongs outside the MVP.

------------------------------------------------------------------------

## 21. Current Build Priority

Build in this order:

### Step 1

Create Python AI prototype.

### Step 2

Implement Job Analysis.

### Step 3

Implement Candidate Profile Analysis.

### Step 4

Implement Job ↔ Candidate Matching.

### Step 5

Implement Resume Selection.

### Step 6

Implement Skill/Experience Strategy.

### Step 7

Implement simple Rules Layer.

### Step 8

Implement Application Strategy.

### Step 9

Implement Personalized Email Generation.

### Step 10

Test the complete pipeline with real examples.

### Step 11

Expose the Python service through FastAPI.

### Step 12

Connect Laravel.

Do not start with Gmail integration or bulk sending before the AI
application-generation pipeline is reliable.

------------------------------------------------------------------------

## 22. Coding Principles

-   Keep modules small and focused.
-   Prefer structured Pydantic models / schemas for AI outputs.
-   Validate all LLM outputs.
-   Never trust raw LLM text as structured application data.
-   Never invent candidate qualifications.
-   Keep prompts versioned.
-   Keep rules separate from prompts.
-   Keep business logic separate from AI logic.
-   Make AI pipeline stages independently testable.
-   Log pipeline inputs/outputs safely.
-   Do not expose secrets in logs.
-   Use environment variables for API credentials.
-   Write tests for parsing, rules, matching, and output validation.
-   Prefer deterministic logic when AI is not required.
-   Do not introduce ML, RAG, embeddings, or vector databases without a
    concrete MVP requirement.

------------------------------------------------------------------------

## 23. Definition of Done for the AI MVP

Given:

``` text
Candidate profile
+
2–3 existing resumes
+
One real job description
+
Country
+
Application rules
```

the system should produce:

``` text
1. Structured job analysis
2. Candidate/job matching evidence
3. Recommended existing resume
4. Reason for recommendation
5. Skills to emphasize
6. Relevant experience
7. Relevant project
8. Application strategy
9. Personalized email subject
10. Personalized email body
11. Optional cover letter
```

All generated claims must be supported by the candidate's actual
information.

The user must be able to review the result before sending.

------------------------------------------------------------------------

## 24. Product Direction to Preserve

Do not gradually turn JobHunter into another generic AI job-search
application.

The central workflow remains:

``` text
FIND JOB ELSEWHERE
       ↓
ADD JOB TO JOBHUNTER
       ↓
UNDERSTAND JOB
       ↓
SELECT RIGHT CV
       ↓
POSITION CANDIDATE
       ↓
GENERATE APPLICATION
       ↓
REVIEW
       ↓
SEND
       ↓
TRACK
       ↓
REPLY / OUTCOME
```

That workflow is the product.
