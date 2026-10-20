from typing import Literal

from pydantic import BaseModel, Field


class JobInput(BaseModel):
    """A job the user found elsewhere. Only the description is required."""

    description: str
    position: str | None = None
    company: str | None = None
    url: str | None = None
    location: str | None = None
    country: str | None = None
    contact_email: str | None = None
    source: str | None = None
    # "speculative": an application to a company that hasn't posted this job. The description is then
    # just what the user's company list says; position is the role the candidate is offering.
    kind: Literal["posting", "speculative"] = "posting"
    industry: str | None = None
    specialties: str | None = None
    website: str | None = None


class JobAnalysis(BaseModel):
    """Stage 1 output. Also used as the LLM response schema, so no defaults."""

    title: str = Field(description="Job title as written in the posting")
    company: str = Field(description="Hiring company, empty if not stated")
    seniority: str = Field(description="e.g. junior, mid, senior, lead; empty if not stated")
    location: str = Field(description="City/region as stated, empty if not stated")
    country: str = Field(description="Country as stated, empty if not stated")
    required_skills: list[str] = Field(description="Technologies/skills explicitly required")
    preferred_skills: list[str] = Field(description="Skills listed as nice-to-have/preferred/bonus")
    responsibilities: list[str] = Field(description="Main duties, short phrases")
    keywords: list[str] = Field(description="Other domain keywords from the posting")
    requirements: list[str] = Field(description="Non-skill requirements, e.g. years of experience, degree, language")
