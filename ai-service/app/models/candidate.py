from datetime import date

from pydantic import BaseModel, Field

from app.models.resume import ResumeProfile


class SkillEntry(BaseModel):
    name: str
    level: str | None = None
    years: float | None = None


class Experience(BaseModel):
    id: str
    title: str
    company: str
    start: date
    end: date | None = None  # None = current role
    location: str | None = None
    summary: str = ""
    achievements: list[str] = []
    skills: list[str] = []


class Project(BaseModel):
    id: str
    name: str
    description: str = ""
    skills: list[str] = []
    url: str | None = None


class Education(BaseModel):
    id: str
    institution: str
    degree: str
    field: str | None = None
    start: date | None = None
    end: date | None = None


class CandidateProfile(BaseModel):
    name: str
    email: str
    phone: str | None = None
    whatsapp: str | None = None
    linkedin: str | None = None
    location: str | None = None  # where the candidate is now, e.g. "Bangkok, Thailand"
    availability: str | None = None  # e.g. "Immediately", "1 month notice"
    headline: str | None = None
    summary: str | None = None
    skills: list[SkillEntry] = []
    experiences: list[Experience] = []
    projects: list[Project] = []
    education: list[Education] = []
    languages: list[str] = []


class CandidateEvidence(BaseModel):
    """Stage 2 output: every canonical skill mapped to where it is evidenced.

    Evidence refs: "skill_list", "experience:<id>", "project:<id>", "resume:<id>".
    """

    profile: CandidateProfile
    skill_evidence: dict[str, list[str]] = Field(default_factory=dict)
    resumes: list[ResumeProfile] = []
    total_years_experience: int = 0

    def has_skill(self, canonical: str) -> bool:
        return canonical in self.skill_evidence
