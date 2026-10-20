from pydantic import BaseModel


class ResumeLayout(BaseModel):
    """Layout features that applicant tracking systems (ATS) often read badly."""

    multi_column: bool = False  # PDF: much of the text starts in the right half (sidebar/two-column design)
    tables: int = 0  # DOCX: tables with 2+ columns
    text_boxes: int = 0  # DOCX: text boxes / shapes holding text
    contact_only_in_header: bool = False  # DOCX: email/phone only in the page header or footer


class Resume(BaseModel):
    id: str
    filename: str
    format: str  # pdf | docx | txt | md
    text: str
    page_count: int | None = None
    layout: ResumeLayout | None = None


class ResumeProfile(BaseModel):
    """What a resume actually mentions, relative to the career profile."""

    resume_id: str
    filename: str
    format: str
    page_count: int | None = None
    word_count: int
    skills: list[str]  # canonical skills found in the resume text
    experience_ids: list[str]
    project_ids: list[str]


class AddableSkill(BaseModel):
    """A job skill this resume doesn't mention, but the candidate's profile backs up."""

    skill: str
    required: bool
    evidence: list[str]  # human-readable, e.g. "experience: Senior PHP Developer at Harbour Payments"


class ResumeScore(BaseModel):
    resume_id: str
    required_covered: list[str]
    preferred_covered: list[str]
    keyword_hits: list[str]
    cv_rule_issues: list[str]
    # CV analysis (suggestions only; JobHunter never rewrites the resume):
    could_add: list[AddableSkill] = []  # the candidate has it, this CV doesn't say so
    gaps: list[str] = []  # the job wants it and the candidate has no evidence: don't add
    word_count: int | None = None
    page_count: int | None = None
    # ATS-style keyword match: the job's skills and keywords found in this CV's own text.
    # Guidance only; there is no universal pass mark.
    ats_match: int | None = None  # percent; None when the job lists no keywords
    ats_found: list[str] = []
    ats_missing: list[str] = []


class ResumeSelection(BaseModel):
    recommended_resume_id: str
    recommended_filename: str
    alternative_resume_ids: list[str]
    reason: str
    emphasize: list[str]
    relevant_experience: list[str]
    relevant_projects: list[str]
    scores: list[ResumeScore]
