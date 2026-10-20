from datetime import datetime

from pydantic import BaseModel, Field

from app.models.job import JobAnalysis
from app.models.resume import ResumeSelection
from app.models.evidence import RequirementCheck
from app.models.usage import LLMUsage
from app.rules.models import SendingRules


class SkillMatch(BaseModel):
    skill: str
    evidence: list[str]
    kind: str = "skill"  # "skill" (a named tool/skill) or "requirement" (a qualification checked by evidence)


class RelevantItem(BaseModel):
    id: str
    label: str
    matched_skills: list[str]
    evidence: list[str]


class MatchSummary(BaseModel):
    required_covered: int
    required_total: int
    preferred_covered: int
    preferred_total: int


class MatchResult(BaseModel):
    """Stage 3 output: explainable counts and evidence, no LLM percentage."""

    matching_required: list[SkillMatch]
    matching_preferred: list[SkillMatch]
    missing_required: list[str]
    missing_preferred: list[str]
    relevant_experience: list[RelevantItem]
    relevant_projects: list[RelevantItem]
    relevant_education: list[RelevantItem]
    summary: MatchSummary
    # Requirement-by-requirement evidence (stage 3b), with verified quotes from the candidate's own sources.
    requirement_checks: list[RequirementCheck] = []


class SkillStrategy(BaseModel):
    emphasize: list[str]
    supporting_skills: list[str]
    avoid_overemphasizing: list[str]
    do_not_claim: list[str]
    relevant_experience: list[str]  # experience ids
    relevant_project: str | None  # project id


class ApplicationStrategy(BaseModel):
    language: str
    tone: str
    email_length: str
    paragraph_count: int
    max_words: int
    focus: list[str]
    relevant_experience: list[str]  # experience ids
    relevant_project: str | None  # project id
    cta: str
    include_cover_letter: bool
    cover_letter_reason: str
    include_linkedin: bool
    include_phone: bool
    include_salary: bool
    claims_to_avoid: list[str]
    earliest_allowed_send_time: datetime
    send_timezone: str
    sending_rules: SendingRules  # resolved window, so the sender can re-check it at send time
    letter_style: str = "modern"
    job_source: str | None = None  # where the job was seen (traditional letters mention it)


class EmailDraft(BaseModel):
    """LLM response schema for Stage 7. Subject and signature are built in code."""

    greeting: str = Field(description="Greeting line, e.g. 'Dear Hiring Manager,'")
    paragraphs: list[str] = Field(description="Body paragraphs, exactly the requested count")
    closing: str = Field(description="Closing phrase only, e.g. 'Kind regards,'. No name or contact details.")
    cover_letter_paragraphs: list[str] = Field(description="Cover letter paragraphs, or an empty list if not requested")


class ValidationReport(BaseModel):
    errors: list[str] = []
    warnings: list[str] = []
    attempts: int = 1

    @property
    def ok(self) -> bool:
        return not self.errors


class GeneratedEmail(BaseModel):
    subject: str
    body: str
    cover_letter: str | None
    attachment: str
    validation: ValidationReport


class PipelineMeta(BaseModel):
    model: str
    prompt_versions: dict[str, str]
    rules_sources: list[str]
    generated_at: datetime
    warnings: list[str]
    usage: list[LLMUsage] = []


class MatchPreview(BaseModel):
    """Stages 1-4 only: used to rank imported jobs by fit before drafting anything."""

    job_analysis: JobAnalysis
    match: MatchResult
    resume_selection: ResumeSelection
    meta: PipelineMeta


class PipelineResult(BaseModel):
    job_analysis: JobAnalysis
    match: MatchResult
    resume_selection: ResumeSelection
    skill_strategy: SkillStrategy
    application_strategy: ApplicationStrategy
    email: GeneratedEmail
    meta: PipelineMeta
