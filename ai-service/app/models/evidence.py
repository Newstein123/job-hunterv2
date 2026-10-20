"""Requirement-by-requirement evidence: what the job asks for, and where the candidate proves it."""

from typing import Literal

from pydantic import BaseModel, Field

EvidenceStatus = Literal["met", "partial", "not_met", "not_applicable"]


class EvidenceQuoteLLM(BaseModel):
    source_id: str = Field(description="The id of the source the quote is copied from, e.g. 'experience:exp_1'")
    quote: str = Field(description="Words copied exactly from that source, 3-25 words")


class RequirementEvidenceLLM(BaseModel):
    requirement: str = Field(description="The requirement, exactly as given")
    status: EvidenceStatus = Field(description="met, partial, not_met, or not_applicable for job conditions (weekend work, travel, location) that are not candidate qualifications")
    quotes: list[EvidenceQuoteLLM] = Field(description="Exact quotes from the candidate sources that prove it; empty if none")


class EvidenceMatchLLM(BaseModel):
    """LLM response schema."""

    items: list[RequirementEvidenceLLM]


class EvidenceQuote(BaseModel):
    source: str  # e.g. "experience:exp_1", "resume:12", "education:edu_1"
    quote: str


class RequirementCheck(BaseModel):
    requirement: str
    required: bool
    status: EvidenceStatus
    evidence: list[EvidenceQuote] = []
    note: str | None = None  # e.g. "checked against your total experience"
    kind: Literal["skill", "requirement"] = "requirement"  # skill = a job skill the name match missed
