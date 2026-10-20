"""Models for understanding an employer's reply and drafting an answer."""

from typing import Literal

from pydantic import BaseModel, Field

from app.models.application import ValidationReport
from app.models.usage import LLMUsage

ReplyCategory = Literal[
    "interview_invite",  # they want to talk / schedule an interview or call
    "info_request",  # they ask for documents, availability, salary, work rights, etc.
    "rejection",
    "offer",
    "acknowledgement",  # "we received your application", no action asked
    "auto_reply",  # out-of-office or automatic response
    "other",
]


class ThreadMessage(BaseModel):
    """An earlier message in the conversation, after the application email."""

    sender: Literal["employer", "candidate"]
    at: str | None = None  # when it was sent or received, as given
    text: str


# Application status the user is offered for each category (they confirm it).
SUGGESTED_STATUS: dict[str, str | None] = {
    "interview_invite": "interview",
    "rejection": "rejected",
    "offer": "offer",
    "info_request": None,
    "acknowledgement": None,
    "auto_reply": None,
    "other": None,
}


class ReplyAnalysisLLM(BaseModel):
    """LLM response schema for reply classification."""

    category: ReplyCategory = Field(description="What kind of reply this is")
    summary: str = Field(description="One or two sentences on what the employer said")
    requests: list[str] = Field(description="Things they ask the candidate to send or do, short phrases")
    questions: list[str] = Field(description="Questions they ask the candidate, copied or closely paraphrased")
    dates_mentioned: list[str] = Field(description="Dates, times or deadlines exactly as written in the reply")
    evidence_quotes: list[str] = Field(description="1-3 short exact quotes from the reply that support the category")


class ReplyAnalysis(ReplyAnalysisLLM):
    suggested_status: str | None
    warnings: list[str] = []
    usage: list[LLMUsage] = []


class ReplyDraftLLM(BaseModel):
    """LLM response schema for a reply draft. Subject and signature are added in code."""

    greeting: str = Field(description="Greeting line addressing the sender, e.g. 'Dear Jane,'")
    paragraphs: list[str] = Field(description="Body paragraphs of the reply")
    closing: str = Field(description="Closing phrase only, e.g. 'Kind regards,'")
    needs_input: list[str] = Field(
        description="Information the candidate must provide that is not in the facts, each also written as [[...]] in the text"
    )


class ReplyDraft(BaseModel):
    subject: str
    body: str
    needs_input: list[str]
    validation: ValidationReport
    usage: list[LLMUsage] = []
