"""Rules are configurable constraints/guidelines, kept separate from prompts."""

from typing import Literal

from pydantic import BaseModel, ConfigDict

class _Strict(BaseModel):
    model_config = ConfigDict(extra="forbid")


class CVRules(_Strict):
    preferred_formats: list[str] = []  # e.g. ["pdf"]
    max_pages: int | None = None
    include: list[str] = []  # guidance only; no CV rewriting in MVP
    avoid: list[str] = []


# Subject lines adapted from the Career Coaching Center samples. Used in rotation so the same
# subject isn't sent again and again (the course lists repeated subjects as a spam trigger).
COURSE_SUBJECT_FORMATS = [
    "{position} Position - {candidate_name}",
    "Resume attached for {position} position - {candidate_name}",
    "Application for {position} - {candidate_name}",
    "{position}: Seeking new opportunity - {candidate_name}",
]


class EmailRules(_Strict):
    # One fixed subject for every email; empty = rotate through subject_formats per job.
    subject_format: str | None = None
    subject_formats: list[str] = COURSE_SUBJECT_FORMATS
    # Course letter structures: "modern" (Dear Hiring Manager, shorter) or "traditional"
    # (addressed to the company, says where the job was seen).
    letter_style: Literal["modern", "traditional"] = "modern"
    language: str = "English"
    max_words: int = 200
    paragraph_count: int = 3
    tone: str = "professional"
    include_linkedin: bool = True
    include_phone: bool = True
    include_salary: bool = False


class ApplicationRules(_Strict):
    cover_letter: Literal["never", "when_requested", "always"] = "when_requested"
    mention_projects: bool = True
    cta: str = "I would welcome the opportunity to discuss how I can contribute to the team."
    skills_to_emphasize: list[str] = []
    claims_to_avoid: list[str] = []
    max_emphasized_skills: int = 5


class Rules(_Strict):
    country: str | None = None
    notes: str | None = None
    cv_rules: CVRules = CVRules()
    email_rules: EmailRules = EmailRules()
    application_rules: ApplicationRules = ApplicationRules()
