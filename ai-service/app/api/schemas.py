from typing import Any

from pydantic import BaseModel, Field

from app.models.candidate import CandidateProfile
from app.models.job import JobAnalysis, JobInput
from app.models.reply import ThreadMessage


class ResumeFile(BaseModel):
    id: str = Field(description="Caller's resume id; returned as recommended_resume_id")
    filename: str
    content_base64: str


class AnalyzeJobRequest(BaseModel):
    job: JobInput


class AnalyzeJobResponse(BaseModel):
    analysis: JobAnalysis
    warnings: list[str]
    model: str
    prompt_version: str


class GenerateApplicationRequest(BaseModel):
    job: JobInput
    profile: CandidateProfile
    resumes: list[ResumeFile] = Field(min_length=1)
    user_rules: dict[str, Any] | None = None
    # A stored analysis of this same job description; when given, stage 1 is skipped.
    job_analysis: JobAnalysis | None = None


class ReplyAnalyzeRequest(BaseModel):
    job: JobAnalysis
    sent_body: str
    reply_text: str
    history: list[ThreadMessage] = []  # earlier messages after the application email, oldest first


class ReplyDraftRequest(BaseModel):
    job: JobAnalysis
    profile: CandidateProfile
    sent_subject: str
    sent_body: str
    reply_text: str
    analysis: dict[str, Any]
    notes: str = ""
    country: str | None = None
    user_rules: dict[str, Any] | None = None
    history: list[ThreadMessage] = []


class ResumeReviewRequest(BaseModel):
    resume: ResumeFile
    candidate_name: str | None = None  # for the file-name check
    user_rules: dict[str, Any] | None = None
